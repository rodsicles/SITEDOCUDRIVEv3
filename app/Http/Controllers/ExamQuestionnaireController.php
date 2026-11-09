<?php

namespace App\Http\Controllers;

use App\Models\ExamQuestionnaire;
use App\Models\SchoolYear;
use App\Services\AcademicHierarchyService;
use App\Services\ExamQuestionnaireSyncService;
use App\Models\Document;
use App\Services\DocumentService;
use App\Services\NotificationService;
use App\Support\AcademicYear;
use App\Support\CoordinatorDepartment;
use App\Support\IteSubjects;
use App\Support\SubmissionLocation;
use App\Support\UploadStorage;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExamQuestionnaireController extends Controller
{
    use \App\Http\Controllers\Concerns\AppliesListSort;
    use \App\Http\Controllers\Concerns\AuthorizesSubmissionReview;
    use \App\Http\Controllers\Concerns\HandlesUploadExceptions;
    use \App\Http\Controllers\Concerns\LogsSubmissionActivity;

    public function __construct(
        protected AcademicHierarchyService $hierarchy,
        protected NotificationService $notificationService,
    ) {}

    public function index(Request $request)
    {
        $user = auth()->user();

        if ($user->isProgramCoordinator()) {
            CoordinatorDepartment::require($user);
        }

        $search = $request->query('search');
        $sort = $this->normalizeListSort($request->query('sort'));
        $statusFilter = $request->query('status');
        $examTypeFilter = $request->query('exam_type');
        $semesterFilter = $request->query('semester');
        $academicYearStart = AcademicYear::startYearFromQuery($request->query('academic_year'));
        $archiveYears = array_filter(
            AcademicYear::availableStartYears(),
            fn ($y) => AcademicYear::isArchived($y)
        );
        $role = $this->getViewRole($user);

        if ($user->isFaculty()) {
            $pendingSubmissions = $this->facultyOwnedQuestionnairesQuery($user, $request, 'pending')
                ->with('document.folder.parent')
                ->orderByDesc('created_at')
                ->get();

            $rejectedSubmissions = $this->facultyOwnedQuestionnairesQuery($user, $request, 'rejected')
                ->with('document.folder')
                ->orderByDesc('created_at')
                ->get();

            $questionnairesQuery = ExamQuestionnaire::with('submitter.employee', 'reviewer.employee', 'document.folder')
                ->ownedBy((int) $user->id)
                ->approved();
            $this->applyExamQuestionnaireListFilters($questionnairesQuery, $request);
            $this->applyListSort($questionnairesQuery, $sort);
            $questionnaires = $questionnairesQuery->paginate(15)->appends($request->query());

            $activeId = SchoolYear::activeId();
            $pendingCount = 0;

            return view('faculty.exam-questionnaires', compact(
                'questionnaires', 'search', 'sort', 'examTypeFilter', 'semesterFilter',
                'academicYearStart', 'archiveYears', 'pendingCount', 'pendingSubmissions',
                'rejectedSubmissions'
            ));
        }

        $query = ExamQuestionnaire::with('submitter.employee', 'reviewer.employee')
            ->visibleTo($user);

        $this->applyExamQuestionnaireListFilters($query, $request);

        if ($statusFilter) {
            $query->where('status', $statusFilter);
        }

        $this->applyListSort($query, $sort);
        $questionnaires = $query->paginate(15)->appends($request->query());

        $pendingSubmissions = collect();
        $rejectedSubmissions = collect();

        $pendingCount = \App\Support\SubmissionPendingCounts::examQuestionnairesFor($user);
        $canReviewSubmissions = $user->isDeanOrSecretary() || $user->isProgramCoordinator();

        return view("{$role}.exam-questionnaires", compact(
            'questionnaires', 'search', 'sort', 'statusFilter', 'examTypeFilter',
            'semesterFilter', 'academicYearStart', 'archiveYears', 'pendingCount',
            'pendingSubmissions', 'rejectedSubmissions', 'canReviewSubmissions',
        ));
    }

    public function store(Request $request)
    {
        if (auth()->user()->isFaculty()) {
            abort(403, 'Upload exam questionnaires from Documents → Exam Questionnaires.');
        }

        $validated = $request->validate([
            'subject' => ['required', 'string', Rule::in(IteSubjects::labelsForUser($user))],
            'exam_type' => 'required|in:Quiz,Prelim,Midterm,Pre-Final,Final',
            'submission_type' => 'required|in:tos,toq',
            'academic_year_start' => 'nullable|integer',
            'semester' => 'nullable|in:1st,2nd',
            'file' => 'required|file|max:10240|mimes:pdf|mimetypes:application/pdf',
        ]);

        $startYear = (int) ($validated['academic_year_start'] ?? AcademicYear::currentStartYear());
        abort_if(SchoolYear::where('start_year', $startYear)->first()?->isArchived(), 422, 'Add files to an archived year from its Archive page.');
        $semester = $validated['semester'] ?? AcademicYear::currentSemester();
        $academicYear = AcademicYear::rangeString($startYear);

        $file = $request->file('file');

        if (preg_match('/\.(php|phtml|exe|sh|bat|cmd|com|vbs|js|jsp|asp|aspx)(\.|$)/i', $file->getClientOriginalName())) {
            return back()->with('error', 'Invalid file type.');
        }

        $quotaService = app(\App\Services\StorageQuotaService::class);
        if (!$quotaService->hasQuotaForBytes(auth()->id(), (int) ($file->getSize() ?? 0))) {
            return back()->with('error', 'Storage quota exceeded (limit: ' . $quotaService->formatBytes(\App\Services\StorageQuotaService::DEFAULT_QUOTA_BYTES) . ').');
        }

        $title = $validated['subject'] . ' - ' . $validated['exam_type'] . ' Questionnaire';

        try {
            $extension = strtolower($file->getClientOriginalExtension());
            $fileType = $extension === 'pdf' ? 'pdf' : 'word';
            $filename = time() . '_' . $file->hashName();
            $storedPath = UploadStorage::storeAs($file, 'exam-questionnaires', $filename);

            ExamQuestionnaire::create([
                'submitted_by' => auth()->id(),
                'title' => $title,
                'file_path' => $storedPath,
                'original_filename' => \App\Support\DocumentNaming::uploadedFilename($file),
                'file_type' => $fileType,
                'subject' => $validated['subject'],
                'exam_type' => $validated['exam_type'],
                'submission_type' => $validated['submission_type'],
                'school_year_id' => SchoolYear::activeId(),
                'semester' => $semester,
                'academic_year' => $academicYear,
                'status' => 'pending',
            ]);
        } catch (\Throwable $e) {
            return $this->uploadFailedResponse($request, $e);
        }

        app(\App\Services\NotificationService::class)->notifyDeanOnFileUpload(
            auth()->user(),
            1,
            $title,
            'Exam Questionnaires',
            true,
        );

        return back()->with('success', 'Exam questionnaire submitted for Dean approval.');
    }

    public function view(Request $request, $id)
    {
        $user = auth()->user();
        $questionnaire = ExamQuestionnaire::with('document.folder.parent.parent')->visibleTo($user)->findOrFail($id);

        UploadStorage::assertPathAllowed($questionnaire->file_path);

        if (!UploadStorage::exists($questionnaire->file_path)) {
            return back()->with('error', 'This file is no longer available. It was uploaded to a previous storage provider and no longer exists in the current storage.');
        }

        if ($request->boolean('stream')) {
            $this->logSubmissionActivity($user, 'Viewed exam questionnaire: '.$questionnaire->title, 'exam_questionnaire_viewed');

            return UploadStorage::inlineResponse(
                $questionnaire->file_path,
                \App\Support\DocumentNaming::downloadFilename($questionnaire->title, $questionnaire->file_path, $questionnaire->original_filename),
                UploadStorage::mimeType($questionnaire->file_path) ?? 'application/octet-stream'
            );
        }

        $routePrefix = $this->submissionRoutePrefix($user);

        return view('submissions.file-preview', [
            'title' => $questionnaire->title,
            'folderPath' => SubmissionLocation::examQuestionnairePath($questionnaire),
            'documentsUrl' => SubmissionLocation::examQuestionnaireDocumentsUrl($user, $questionnaire),
            'streamUrl' => route($routePrefix.'.exam-questionnaires.view', ['id' => $id, 'stream' => 1]),
            'downloadUrl' => route($routePrefix.'.exam-questionnaires.download', $id),
            'backUrl' => route($routePrefix.'.exam-questionnaires.index'),
        ]);
    }

    public function download($id)
    {
        $user = auth()->user();
        $questionnaire = ExamQuestionnaire::visibleTo($user)->findOrFail($id);

        UploadStorage::assertPathAllowed($questionnaire->file_path);

        if (!UploadStorage::exists($questionnaire->file_path)) {
            return back()->with('error', 'This file is no longer available. It was uploaded to a previous storage provider and no longer exists in the current storage.');
        }

        $this->logSubmissionActivity($user, 'Downloaded exam questionnaire: '.$questionnaire->title, 'exam_questionnaire_downloaded');

        return UploadStorage::downloadResponse(
            $questionnaire->file_path,
            \App\Support\DocumentNaming::downloadFilename($questionnaire->title, $questionnaire->file_path, $questionnaire->original_filename),
        );
    }

    public function approve(Request $request, $id)
    {
        $user = $this->submissionReviewer();

        $questionnaire = ExamQuestionnaire::query()->visibleTo($user)->findOrFail($id);

        if (!$questionnaire->isPending()) {
            if ($questionnaire->isApproved() && !$questionnaire->document_id) {
                $sync = app(ExamQuestionnaireSyncService::class);
                $document = $sync->syncToDocument($questionnaire->fresh());
                if ($document) {
                    return back()->with('success', 'Approved file synced to Documents folder.');
                }

                return back()->with('warning', 'Submission is approved but could not be synced. Ensure TOS/TOQ folders exist for the active school year.');
            }

            return back()->with('info', 'This submission has already been reviewed.');
        }

        $questionnaire->update([
            'status' => 'approved',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'remarks' => $request->input('remarks'),
        ]);

        $fresh = $questionnaire->fresh();
        $sync = app(ExamQuestionnaireSyncService::class);
        $document = $sync->syncToDocument($fresh);

        $this->notificationService->notifyExamQuestionnaireApproved($fresh, $user);

        if (!$document) {
            return back()->with('warning', 'Questionnaire approved, but it could not be placed in the Documents folder. Ensure TOS/TOQ folders exist for the active school year.');
        }

        return back()->with('success', 'Questionnaire approved and added to Documents.');
    }

    public function reject(Request $request, $id)
    {
        $user = $this->submissionReviewer();

        $request->validate(['remarks' => 'required|string|max:500']);

        $questionnaire = ExamQuestionnaire::query()->visibleTo($user)->findOrFail($id);

        if (!$questionnaire->isPending()) {
            return back()->with('info', 'This submission has already been reviewed.');
        }

        $questionnaire->update([
            'status' => 'rejected',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'remarks' => $request->input('remarks'),
        ]);

        $this->notificationService->notifyExamQuestionnaireRejected($questionnaire->fresh(), $user);

        return back()->with('success', 'Questionnaire rejected.');
    }

    public function rename(\App\Http\Requests\RenameDocumentRequest $request, $id)
    {
        $user = auth()->user();
        $questionnaire = ExamQuestionnaire::visibleTo($user)->findOrFail($id);

        if ($user->isFaculty()) {
            if ((int) $questionnaire->submitted_by !== (int) $user->id) {
                abort(403, 'You can only rename your own exam questionnaires.');
            }
            if (!$questionnaire->isPending()) {
                abort(403, 'Only pending submissions can be renamed.');
            }
        }

        $title = $request->validated('document_title');

        if ($questionnaire->document_id) {
            $document = app(DocumentService::class)->renameDocument((int) $questionnaire->document_id, $user, $title);
            $title = $document->document_title;
        } else {
            $questionnaire->update(['title' => $title]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Exam questionnaire renamed successfully.',
            'document_title' => $title,
        ]);
    }

    public function destroy($id)
    {
        $user = auth()->user();
        $questionnaire = ExamQuestionnaire::visibleTo($user)->findOrFail($id);

        if ($user->isFaculty()) {
            if ((int) $questionnaire->submitted_by !== (int) $user->id || !$questionnaire->isRejected()) {
                abort(403, 'You can only delete rejected exam questionnaires.');
            }
        }

        if ($questionnaire->document_id) {
            $document = Document::find($questionnaire->document_id);
            if ($document && ($user->isDeanOrSecretary() || (int) $document->uploaded_by === (int) $user->id)) {
                app(DocumentService::class)->deleteDocument((int) $questionnaire->document_id, $user);
            }
        }

        if ($questionnaire->file_path && UploadStorage::exists($questionnaire->file_path)) {
            UploadStorage::delete($questionnaire->file_path);
        }

        $questionnaire->delete();

        return back()->with('success', 'Questionnaire deleted.');
    }

    private function getViewRole($user): string
    {
        if ($user->isDeanOrSecretary()) {
            return 'dean';
        }
        if ($user->isProgramCoordinator()) {
            return 'coordinator';
        }

        return 'faculty';
    }

    private function submissionRoutePrefix($user): string
    {
        return $this->getViewRole($user) === 'dean' ? 'dean' : $this->getViewRole($user);
    }

    private function facultyOwnedQuestionnairesQuery($user, Request $request, string $status)
    {
        $query = ExamQuestionnaire::query()
            ->ownedBy((int) $user->id)
            ->where('status', $status);

        $this->applyExamQuestionnaireListFilters($query, $request);

        return $query;
    }

    private function applyExamQuestionnaireListFilters($query, Request $request): void
    {
        $search = $request->query('search');
        $examTypeFilter = $request->query('exam_type');
        $semesterFilter = $request->query('semester');
        $academicYearStart = AcademicYear::startYearFromQuery($request->query('academic_year'));

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%");
            });
        }

        if ($examTypeFilter) {
            $query->where('exam_type', $examTypeFilter);
        }

        if ($semesterFilter) {
            $query->where('semester', $semesterFilter);
        }

        if ($academicYearStart) {
            $query->where('academic_year', AcademicYear::rangeString($academicYearStart));
        } else {
            $activeId = SchoolYear::activeId();
            $query->where(function ($q) use ($activeId) {
                $q->where('school_year_id', $activeId)
                    ->orWhereNull('school_year_id');
            });
        }
    }
}
