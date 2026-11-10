<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\DashboardLog;
use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestRecipient;
use App\Models\DocumentSearchIndex;
use App\Models\Folder;
use App\Models\Notification;
use App\Models\SchoolYear;
use App\Models\User;
use App\Jobs\IndexDocumentContentJob;
use App\Services\AcademicHierarchyService;
use App\Services\DocumentVersionService;
use App\Services\ExamQuestionnaireSyncService;
use App\Services\TeachingGuideSyncService;
use App\Support\UploadStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DocumentRequestController extends Controller
{
    private const REQUEST_CATEGORIES = ['teaching_guide', 'exam_questionnaire', 'general'];

    public function index(Request $request)
    {
        $user = $request->user();
        $tab = $request->string('tab')->value() === 'sent' ? 'sent' : 'received';
        $query = DocumentRequest::query()->with(['requester.employee', 'requester.role', 'course', 'schoolYear', 'destinationFolder', 'recipients.recipient.employee', 'recipients.document']);

        if ($tab === 'sent') {
            $query->where('requested_by', $user->id);
        } else {
            $query->whereHas('recipients', fn ($q) => $q->where('user_id', $user->id));
        }

        if ($request->filled('status')) {
            $status = $request->string('status')->value();
            if (in_array($status, ['open', 'completed', 'cancelled'], true)) {
                $query->where('status', $status);
            } elseif ($tab === 'sent') {
                $query->whereHas('recipients', fn ($q) => $q->where('status', $status));
            } else {
                $query->whereHas('recipients', fn ($q) => $q->where('user_id', $user->id)->where('status', $status));
            }
        }

        $requests = $query->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')->orderBy('due_at')->latest('id')->paginate(20)->withQueryString();
        $canCreateRequests = \App\Services\DocumentRequestManagementService::canCreateRequests($user);
        $people = $canCreateRequests ? app(\App\Services\DocumentRequestManagementService::class)->eligiblePeople($user)
            ->with(['assignedCourses' => fn ($query) => $query->active()->ordered()])->orderBy('username')->get() : collect();
        $courses = Course::active()->ordered()->get();

        return view('document-requests.index', [
            'requests' => $requests,
            'tab' => $tab,
            'people' => $people,
            'courses' => $courses,
            'canCreateRequests' => $canCreateRequests,
            'schoolYears' => SchoolYear::orderByDesc('start_year')->get(),
            'pendingCount' => DocumentRequestRecipient::where('user_id', $user->id)->whereIn('status', ['pending', 'changes_requested'])->whereHas('request', fn ($q) => $q->where('status', 'open'))->count(),
            'customRequestCategories' => \App\Models\DocumentCategory::whereNotNull('created_by')->whereNull('owner_id')->where('is_active', true)->orderBy('category_name')->get(),
        ]);
    }

    public function recipients(Request $request)
    {
        abort_unless(\App\Services\DocumentRequestManagementService::canCreateRequests($request->user()), 403);
        $people = app(\App\Services\DocumentRequestManagementService::class)->eligiblePeople($request->user())
            ->with(['assignedCourses' => fn ($query) => $query->active()->ordered()])->orderBy('username')->get();

        return response()->json(['people' => $people->map(fn ($person) => [
            'id' => $person->id,
            'name' => $person->employee?->full_name ?? $person->username,
            'username' => $person->username,
            'program' => $person->employee?->program ?? 'No program',
            'role' => $person->role?->role_name ?? 'Employee',
            'course_ids' => $person->assignedCourses->pluck('id')->values(),
        ])])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request)
    {
        abort_unless(\App\Services\DocumentRequestManagementService::canCreateRequests($request->user()), 403, 'Your account cannot create document requests.');

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'document_type' => ['required', Rule::in(['any', 'pdf', 'word', 'image'])],
            'request_category' => ['required', Rule::in(self::REQUEST_CATEGORIES)],
            'custom_category_id' => ['nullable', Rule::exists('document_categories', 'category_id')->whereNull('owner_id')->whereNotNull('created_by')->where('is_active', true)],
            'recipient_ids' => ['required', 'array', 'min:1'],
            'recipient_ids.*' => ['integer', Rule::exists('users', 'id')->where('status', 'Active')],
            'course_id' => ['nullable', Rule::exists('courses', 'id')->where('is_active', true)->whereIn('program', \App\Models\Program::codes())],
            'destination_type' => ['nullable', Rule::in(['tg', 'lb', 'tos', 'toq'])],
            'exam_period' => ['nullable', Rule::in(['prelims', 'midterms', 'finals'])],
            'school_year_id' => ['nullable', 'exists:school_years,id'],
            'semester' => ['nullable', Rule::in(['1st', '2nd'])],
            'due_at' => ['nullable', 'date', 'after:now'],
            'allow_late_submission' => ['nullable', 'boolean'],
        ], [
            'due_at.after' => 'The deadline has already passed. Choose a future date and time, or leave it blank for no deadline.',
            'due_at.date' => 'Enter a valid deadline date and time, or leave it blank for no deadline.',
            'recipient_ids.required' => 'Choose at least one recipient, then click Done selecting.',
        ]);

        $recipientIds = collect($validated['recipient_ids'])->map(fn ($id) => (int) $id)->unique()->reject(fn ($id) => $id === (int) $request->user()->id);
        abort_if($recipientIds->isEmpty(), 422, 'Choose at least one other employee.');

        // Faculty and Coordinators are limited to eligiblePeople() (Faculty: Secretaries only).
        if ($request->user()->isProgramCoordinator() || $request->user()->isFaculty()) {
            $accessibleCount = app(\App\Services\DocumentRequestManagementService::class)
                ->eligiblePeople($request->user())
                ->whereIn('id', $recipientIds)
                ->count();
            if ($accessibleCount !== $recipientIds->count()) {
                throw ValidationException::withMessages(['recipient_ids' => $request->user()->isFaculty()
                    ? 'Faculty can request documents from the Secretary only.'
                    : 'Choose recipients from the programs you handle or the Secretary.']);
            }
        }

        if ($request->user()->isFaculty() && $validated['request_category'] !== 'general') {
            throw ValidationException::withMessages(['request_category' => 'Requests to the Secretary use the General Document category.']);
        }

        if ($validated['request_category'] === 'general') {
            $validated['course_id'] = null;
            $validated['destination_folder_id'] = null;
            if (!empty($validated['custom_category_id'])) {
                $category = \App\Models\DocumentCategory::findOrFail($validated['custom_category_id']);
                $validated['destination_folder_id'] = $category->folders()->whereNotNull('parent_id')->orderBy('folder_id')->firstOrFail()->folder_id;
            }
        } else {
            if (empty($validated['course_id']) || empty($validated['school_year_id']) || empty($validated['semester']) || empty($validated['destination_type'])) {
                throw ValidationException::withMessages([
                    'destination_type' => 'Complete the destination selection for this course-related request.',
                ]);
            }

            $assignedRecipientCount = DB::table('faculty_courses')
                ->where('course_id', $validated['course_id'])
                ->whereIn('user_id', $recipientIds)
                ->distinct()
                ->count('user_id');
            if ($assignedRecipientCount !== $recipientIds->count()) {
                throw ValidationException::withMessages([
                    'course_id' => 'The selected course must be assigned to every chosen recipient.',
                ]);
            }

            if (!in_array($validated['document_type'], ['pdf', 'word'], true)) {
                throw ValidationException::withMessages([
                    'document_type' => 'Teaching Guides and Exam Questionnaires must use PDF or Word files.',
                ]);
            }

            $hierarchy = app(AcademicHierarchyService::class);
            $course = Course::active()->findOrFail($validated['course_id']);
            $matchingPrograms = User::whereIn('id', $recipientIds)
                ->whereHas('employee', fn ($query) => $query->where('program', $course->program))->count();
            if ($matchingPrograms !== $recipientIds->count()) {
                throw ValidationException::withMessages(['course_id' => 'The course must belong to every recipient’s current program.']);
            }
            $schoolYear = SchoolYear::find($validated['school_year_id']);
            $subjectLabel = $course?->code.' — '.$course?->title;
            $destination = null;

            if ($validated['request_category'] === 'teaching_guide') {
                if (!in_array($validated['destination_type'], ['tg', 'lb'], true)) {
                    throw ValidationException::withMessages(['destination_type' => 'Choose TG or LB for a Teaching Guide request.']);
                }
                $hierarchy->ensureSchoolYearStructure('tg', $schoolYear->start_year);
                $semesterFolder = Folder::where('slug', 'tg-'.$validated['semester'].'-'.$schoolYear->start_year.'-'.$schoolYear->end_year)->first();
                if ($semesterFolder) {
                    $subjectFolder = $hierarchy->ensureSubjectWithTgLb($semesterFolder, $subjectLabel);
                    $destination = $subjectFolder->children()->where('folder_name', strtoupper($validated['destination_type']))->first();
                }
            } else {
                if (!in_array($validated['destination_type'], ['tos', 'toq'], true) || empty($validated['exam_period'])) {
                    throw ValidationException::withMessages(['destination_type' => 'Choose an exam period and TOS or TOQ.']);
                }
                $examType = match ($validated['exam_period']) {
                    'midterms' => 'Midterm',
                    'finals' => 'Final',
                    default => 'Prelim',
                };
                $hierarchy->ensureSchoolYearStructure('eq', $schoolYear->start_year);
                $destination = $hierarchy->resolveEqUploadFolder(
                    $schoolYear->start_year,
                    $validated['semester'],
                    $subjectLabel,
                    $examType,
                    $validated['destination_type'],
                );
            }

            if (!$destination) {
                throw ValidationException::withMessages([
                    'destination_type' => 'The selected destination could not be created. Please review your choices.',
                ]);
            }

            $validated['destination_folder_id'] = $destination->folder_id;
            $validated['school_year_id'] = $destination->school_year_id;
        }

        $documentRequest = DB::transaction(function () use ($validated, $recipientIds, $request) {
            $item = DocumentRequest::create([
                ...collect($validated)->except(['recipient_ids', 'destination_type', 'exam_period', 'custom_category_id'])->all(),
                'requested_by' => $request->user()->id,
                'allow_late_submission' => $request->boolean('allow_late_submission', true),
            ]);
            foreach ($recipientIds as $userId) {
                $item->recipients()->create(['user_id' => $userId]);
                Notification::create([
                    'user_id' => $userId,
                    'message' => 'Document requested: "'.$item->title.'"'.($item->due_at ? ' — due '.$item->due_at->format('M d, Y g:i A') : ''),
                    'tone' => Notification::TONE_SUCCESS,
                    'action_url' => route('document-requests.index', ['tab' => 'received']),
                    'is_read' => false,
                ]);
            }
            DashboardLog::create([
                'user_id' => $request->user()->id,
                'activity' => 'Requested "'.$item->title.'" from '.$recipientIds->count().' employee(s)',
                'activity_type' => 'document_request_created',
            ]);
            return $item;
        });

        return redirect()->route('document-requests.index', ['tab' => 'sent'])->with('success', 'Document request sent to '.$recipientIds->count().' employee(s).');
    }

    public function manage(Request $request, DocumentRequest $documentRequest, \App\Services\DocumentRequestManagementService $management)
    {
        $management->authorize($request->user(), $documentRequest);
        $documentRequest->load(['recipients.recipient.employee', 'recipients.document', 'changes.actor.employee', 'course', 'destinationFolder', 'schoolYear']);
        $people = $management->eligiblePeople($request->user())->orderBy('username')->get();
        return view('document-requests.manage', compact('documentRequest', 'people'));
    }

    public function change(Request $request, DocumentRequest $documentRequest, \App\Services\DocumentRequestManagementService $management)
    {
        $management->apply($request->user(), $documentRequest, $request->all());
        return redirect()->route('document-requests.manage', $documentRequest)->with('success', 'Request updated. Existing submissions and change history are retained.');
    }

    public function submit(Request $request, DocumentRequestRecipient $recipient)
    {
        try {
            return DB::transaction(function () use ($request, $recipient) {
                $item = DocumentRequest::lockForUpdate()->findOrFail($recipient->document_request_id);
                $recipient->refresh();
                abort_unless((int) $recipient->user_id === (int) $request->user()->id, 403);
                if (in_array($recipient->status, ['submitted', 'approved'], true) && $recipient->submitted_document_id) {
                    return redirect()->route('document-requests.index', ['tab' => 'received'])
                        ->with('success', 'Your document was already submitted. No duplicate file was uploaded. Use View submission to check it.');
                }
                $response = $this->submitLocked($request, $recipient);
                $item->increment('lock_version');
                return $response;
            });
        } catch (ValidationException $e) {
            if ($request->expectsJson()) throw $e;
            return redirect()->route('document-requests.index', ['tab' => 'received'])->withErrors($e->errors());
        }
    }

    private function submitLocked(Request $request, DocumentRequestRecipient $recipient)
    {
        abort_unless((int) $recipient->user_id === (int) $request->user()->id, 403);
        $recipient->load(['request.course', 'request.destinationFolder', 'request.requester.role', 'document']);
        if ($recipient->request->status !== 'open') throw ValidationException::withMessages(['submission' => 'This request is closed. Contact the requester if it needs to be reopened.']);
        if ($recipient->request->due_at?->isPast() && !$recipient->request->allow_late_submission) throw ValidationException::withMessages(['submission' => 'The submission deadline has passed. Ask the requester to extend it or allow late submission.']);
        if (!in_array($recipient->status, ['pending', 'changes_requested'], true)) throw ValidationException::withMessages(['submission' => 'This request is not accepting another submission. Check its current status in the request list.']);

        if ($recipient->request->destinationFolder?->document_category_id) {
            if (!$recipient->request->destinationFolder->managedCategory?->is_active) throw ValidationException::withMessages(['submission' => 'This request category is inactive. Contact the requester.']);
            abort_unless($recipient->request->destinationFolder->canBeViewedBy($request->user()), 404);
        }
        $rules = ['file' => ['required', 'file', 'max:10240']];
        $rules['file'][] = match ($recipient->request->document_type) {
            'pdf' => 'mimes:pdf', 'word' => 'mimes:doc,docx',
            'image' => 'mimes:jpg,jpeg,png,gif,webp', default => 'mimes:pdf,doc,docx,jpg,jpeg,png,gif,webp',
        };
        $validated = $request->validate($rules);
        $file = $validated['file'];

        if ($recipient->status === 'changes_requested' && $recipient->document) {
            app(DocumentVersionService::class)->uploadNewVersion(
                $recipient->document,
                $file,
                $request->user(),
                'Corrected document-request submission',
            );

            DB::transaction(function () use ($recipient, $request) {
                $recipient->update([
                    'status' => 'submitted',
                    'submitted_at' => now(),
                    'review_note' => null,
                    'reviewed_at' => null,
                    'reviewed_by' => null,
                ]);
                Notification::create([
                    'user_id' => $recipient->request->requested_by,
                    'message' => ($request->user()->employee->full_name ?? $request->user()->username).' resubmitted "'.$recipient->request->title.'".',
                    'tone' => Notification::TONE_SUCCESS,
                    'action_url' => route('document-requests.index', ['tab' => 'sent']),
                    'is_read' => false,
                ]);
            });

            return redirect()->route('document-requests.index', ['tab' => 'received'])->with('success', 'Corrected document submitted successfully.');
        }

        $path = UploadStorage::storeAs($file, 'documents/requests', Str::uuid().'.'.strtolower($file->getClientOriginalExtension()));

        try {
            $document = DB::transaction(function () use ($recipient, $request, $file, $path) {
                $category = match ($recipient->request->request_category) {
                    'teaching_guide' => 'Teaching Guides',
                    'exam_questionnaire' => 'Exam Questionnaires',
                    default => 'Other',
                };
                $extension = strtolower($file->getClientOriginalExtension());
                $documentType = match ($extension) {
                    'doc', 'docx' => 'word',
                    'jpg', 'jpeg', 'png', 'gif', 'webp' => 'image',
                    default => 'pdf',
                };
                $origin = $recipient->request->requester?->isProgramCoordinator() ? 'requested-by-coordinator' : 'requested-by-dean';

                $document = Document::create([
                    'uploaded_by' => $request->user()->id,
                    'folder_id' => $recipient->request->destination_folder_id,
                    'document_title' => $recipient->request->title,
                    'file_path' => $path,
                    'original_filename' => \App\Support\DocumentNaming::uploadedFilename($file),
                    'file_size' => $file->getSize() ?: 0,
                    'document_type' => $documentType,
                    'category' => $category,
                    'category_id' => $recipient->request->destinationFolder?->document_category_id,
                    'school_year_id' => $recipient->request->school_year_id,
                    'subject' => $recipient->request->course?->code,
                    'tags' => 'document-request,'.$origin.','.$recipient->request->title,
                    'version' => 1,
                ]);
                $document->recipients()->syncWithoutDetaching([$recipient->request->requested_by]);
                $recipient->update([
                    'submitted_document_id' => $document->document_id,
                    'status' => 'submitted',
                    'submitted_at' => now(),
                    'review_note' => null,
                ]);
                Notification::create([
                    'user_id' => $recipient->request->requested_by,
                    'message' => ($request->user()->employee->full_name ?? $request->user()->username).' submitted "'.$recipient->request->title.'".',
                    'tone' => Notification::TONE_SUCCESS,
                    'action_url' => route('document-requests.index', ['tab' => 'sent']),
                    'is_read' => false,
                ]);
                return $document;
            });
        } catch (\Throwable $e) {
            UploadStorage::delete($path);
            throw $e;
        }

        $realPath = $file->getRealPath();
        DocumentSearchIndex::updateOrCreate(
            ['document_id' => $document->document_id],
            ['file_hash' => $realPath ? (hash_file('sha256', $realPath) ?: null) : null, 'index_status' => 'pending']
        );
        IndexDocumentContentJob::dispatch($document->document_id)->afterResponse();
        return redirect()->route('document-requests.index', ['tab' => 'received'])->with('success', 'Document submitted successfully.');
    }

    public function review(Request $request, DocumentRequestRecipient $recipient)
    {
        abort_unless((int) $recipient->request->requested_by === (int) $request->user()->id, 403);
        return DB::transaction(function () use ($request, $recipient) {
            $item = DocumentRequest::lockForUpdate()->findOrFail($recipient->document_request_id);
            abort_unless($item->status === 'open', 422, 'Reopen this request before reviewing submissions.');
            $recipient->refresh();
            $response = $this->reviewLocked($request, $recipient);
            $item->increment('lock_version');
            return $response;
        });
    }

    private function reviewLocked(Request $request, DocumentRequestRecipient $recipient)
    {
        $recipient->load(['request.destinationFolder', 'request.course', 'document']);
        abort_unless((int) $recipient->request->requested_by === (int) $request->user()->id, 403);
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'changes_requested'])],
            'review_note' => ['nullable', 'string', 'max:1000', Rule::requiredIf($request->input('decision') === 'changes_requested')],
        ]);
        abort_unless($recipient->status === 'submitted', 422, 'Only submitted documents can be reviewed.');

        DB::transaction(function () use ($recipient, $validated, $request) {
            if ($validated['decision'] === 'approved') {
                $this->approveRequestedDocument($recipient, $request->user());
            }

            $recipient->update([
                'status' => $validated['decision'],
                'review_note' => $validated['review_note'] ?? null,
                'reviewed_at' => now(),
                'reviewed_by' => $request->user()->id,
            ]);
            $recipient->request->update([
                'status' => $recipient->request->recipients()->where('id', '!=', $recipient->id)->whereNotIn('status', ['approved', 'exempt'])->exists()
                    || $validated['decision'] !== 'approved' ? 'open' : 'completed',
            ]);
            Notification::create([
                'user_id' => $recipient->user_id,
                'message' => 'Your submission for "'.$recipient->request->title.'" was '.str_replace('_', ' ', $validated['decision']).'.'.(!empty($validated['review_note']) ? ' Note: '.$validated['review_note'] : ''),
                'tone' => $validated['decision'] === 'approved' ? Notification::TONE_SUCCESS : Notification::TONE_DANGER,
                'action_url' => route('document-requests.index'),
                'is_read' => false,
            ]);
        });
        return back()->with('success', $validated['decision'] === 'approved' ? 'Submission approved.' : 'Correction request sent.');
    }

    private function approveRequestedDocument(DocumentRequestRecipient $recipient, User $reviewer): void
    {
        $document = $recipient->document;
        $request = $recipient->request;
        if (!$document) {
            throw ValidationException::withMessages(['decision' => 'The submitted document could not be found.']);
        }

        if ($request->request_category === 'teaching_guide') {
            $folder = $request->destinationFolder;
            $guide = app(TeachingGuideSyncService::class)->syncFromDocument(
                $document,
                $folder,
                [$request->requested_by],
                $request->course?->code,
            );
            $guide?->update([
                'status' => 'approved',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'remarks' => 'Approved through Document Requests.',
            ]);
        }

        if ($request->request_category === 'exam_questionnaire') {
            $folder = $request->destinationFolder;
            $sync = app(ExamQuestionnaireSyncService::class);
            $questionnaire = $sync->createFromFolderUpload(
                $document->uploaded_by,
                $folder,
                $document->document_title,
                $document->file_path,
                $document->document_type,
                app(AcademicHierarchyService::class)->examTypeFromEqUploadFolder($folder),
                $request->course?->code,
                'approved',
                $reviewer->id,
            );
            $questionnaire->update([
                'document_id' => $document->document_id,
                'original_filename' => $document->original_filename,
                'school_year_id' => $request->school_year_id,
            ]);
        }
    }

}
