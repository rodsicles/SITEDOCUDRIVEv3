<?php

namespace App\Http\Controllers;

use App\Models\Folder;
use App\Models\SchoolYear;
use App\Models\TeachingGuide;
use App\Models\Document;
use App\Services\AcademicHierarchyService;
use App\Services\DocumentService;
use App\Services\NotificationService;
use App\Services\TeachingGuideSyncService;
use App\Support\AcademicYear;
use App\Support\CoordinatorDepartment;
use App\Support\IteSubjects;
use App\Support\SubmissionLocation;
use App\Support\UploadStorage;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeachingGuideController extends Controller
{
    use \App\Http\Controllers\Concerns\AppliesListSort;
    use \App\Http\Controllers\Concerns\AuthorizesSubmissionReview;
    use \App\Http\Controllers\Concerns\HandlesUploadExceptions;
    use \App\Http\Controllers\Concerns\LogsSubmissionActivity;

    public function __construct(
        protected NotificationService $notificationService,
        protected TeachingGuideSyncService $teachingGuideSync,
        protected AcademicHierarchyService $hierarchy,
    ) {}

    public function index(Request $request)
    {
        $user = auth()->user();

        if ($user->isProgramCoordinator()) {
            CoordinatorDepartment::require($user);
        }

        $search = $request->query('search');
        $sort = $this->normalizeListSort($request->query('sort'));
        $semesterFilter = $request->query('semester');
        $academicYearStart = AcademicYear::startYearFromQuery($request->query('academic_year'));
        $archiveYears = array_filter(
            AcademicYear::availableStartYears(),
            fn ($y) => AcademicYear::isArchived($y)
        );
        $role = $this->getViewRole($user);

        if ($user->isFaculty()) {
            $pendingGuides = $this->facultyOwnedGuidesQuery($user, $request, 'pending')
                ->with(['folder.parent', 'document.folder'])
                ->orderByDesc('created_at')
                ->get();

            $rejectedGuides = $this->facultyOwnedGuidesQuery($user, $request, 'rejected')
                ->with(['folder.parent', 'document.folder'])
                ->orderByDesc('created_at')
                ->get();

            $guidesQuery = TeachingGuide::with('uploader.employee', 'folder.parent', 'document.folder')
                ->forUser($user)
                ->approved();
            $this->applyTeachingGuideListFilters($guidesQuery, $request);
            $this->applyListSort($guidesQuery, $sort);
            $guides = $guidesQuery->paginate(15)->appends($request->query());

            return view('faculty.teaching-guides', compact(
                'guides', 'search', 'sort', 'semesterFilter', 'academicYearStart',
                'archiveYears', 'pendingGuides', 'rejectedGuides'
            ));
        }

        $statusFilter = $request->query('status');

        $query = TeachingGuide::with('uploader.employee', 'reviewer.employee', 'folder')->forUser($user);
        $this->applyTeachingGuideListFilters($query, $request);

        if ($statusFilter) {
            $query->where('status', $statusFilter);
        }

        $this->applyListSort($query, $sort);
        $guides = $query->paginate(15)->appends($request->query());

        $pendingCount = \App\Support\SubmissionPendingCounts::teachingGuidesFor($user);
        $canReviewSubmissions = $user->isDeanOrSecretary() || $user->isProgramCoordinator();

        return view("{$role}.teaching-guides", compact(
            'guides', 'search', 'sort', 'statusFilter', 'semesterFilter', 'academicYearStart',
            'archiveYears', 'pendingCount', 'canReviewSubmissions',
        ));
    }

    public function store(Request $request)
    {
        $user = auth()->user();

        if ($user->isFaculty()) {
            abort(403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:150',
            'subject' => ['required', 'string', Rule::in(IteSubjects::labelsForUser($user))],
            'academic_year_start' => 'required|integer',
            'semester' => 'required|in:1st,2nd',
            'guide_type' => 'required|in:teaching-guides,lesson,lab-manual',
            'files' => 'required|array|min:1',
            'files.*' => 'required|file|max:10240|mimes:pdf,doc,docx|mimetypes:application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'recipient_ids' => 'required|array|min:1',
            'recipient_ids.*' => 'integer|exists:users,id',
        ]);

        $startYear = (int) $validated['academic_year_start'];
        $folder = $this->hierarchy->resolveTeachingGuideFolder(
            $startYear,
            $validated['semester'],
            $validated['guide_type'],
        );

        if (!$folder) {
            return back()->with('error', 'Could not create folder structure. Please contact the administrator.');
        }
        abort_if($folder->schoolYear?->isArchived(), 422, 'Add files to an archived year from its Archive page.');

        $recipientIds = array_values(array_unique(array_map('intval', $validated['recipient_ids'])));
        $quotaService = app(\App\Services\StorageQuotaService::class);
        $uploadedCount = 0;
        $academicYear = AcademicYear::rangeString($startYear);

        try {
            foreach ($request->file('files') as $file) {
                if (preg_match('/\.(php|phtml|exe|sh|bat|cmd|com|vbs|js|jsp|asp|aspx)(\.|$)/i', $file->getClientOriginalName())) {
                    continue;
                }

                if (!$quotaService->hasQuotaForBytes($user->id, (int) ($file->getSize() ?? 0))) {
                    return back()->with('error', 'Storage quota exceeded (limit: ' . $quotaService->formatBytes(\App\Services\StorageQuotaService::DEFAULT_QUOTA_BYTES) . ').');
                }

                $extension = strtolower($file->getClientOriginalExtension());
                $fileType = $extension === 'pdf' ? 'pdf' : 'word';
                $filename = time() . '_' . $file->hashName();
                $storedPath = UploadStorage::storeAs($file, 'teaching-guides', $filename);

                $guide = TeachingGuide::create([
                    'user_id' => $user->id,
                    'title' => $validated['title'],
                    'file_path' => $storedPath,
                    'original_filename' => \App\Support\DocumentNaming::uploadedFilename($file),
                    'file_type' => $fileType,
                    'subject' => $validated['subject'],
                    'folder_id' => $folder->folder_id,
                    'school_year_id' => SchoolYear::activeId(),
                    'semester' => $validated['semester'],
                    'academic_year' => $academicYear,
                    'status' => 'pending',
                ]);

                $this->teachingGuideSync->syncRecipients($guide, $recipientIds);
                $uploadedCount++;
            }
        } catch (\Throwable $e) {
            return $this->uploadFailedResponse($request, $e);
        }

        if ($uploadedCount === 0) {
            return back()->with('error', 'No valid files were uploaded.');
        }

        if (!empty($recipientIds)) {
            $label = $uploadedCount === 1
                ? "\"{$validated['title']}\" ({$folder->folder_name})"
                : "{$uploadedCount} teaching guides in \"{$folder->folder_name}\"";
            $this->notificationService->notifyMany(
                $recipientIds,
                "New teaching guide uploaded: {$label}. Awaiting Dean approval.",
                null,
                null,
                function (\App\Models\User $recipient) {
                    $prefix = $recipient->isProgramCoordinator() ? 'coordinator' : 'faculty';

                    return route($prefix.'.teaching-guides.index');
                },
            );
        }

        $this->notificationService->notifyDeanOnFileUpload(
            $user,
            $uploadedCount,
            (string) $validated['title'],
            'Teaching Guides',
            true,
        );

        $msg = $uploadedCount === 1
            ? 'Teaching guide submitted for Dean approval.'
            : "{$uploadedCount} teaching guides submitted for Dean approval.";

        return back()->with('success', $msg);
    }

    public function approve(Request $request, $id)
    {
        $user = $this->submissionReviewer();

        $guide = TeachingGuide::query()->visibleTo($user)->findOrFail($id);

        if (!$guide->isPending()) {
            return back()->with('info', 'This guide has already been reviewed.');
        }

        $guide->update([
            'status' => 'approved',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'remarks' => $request->input('remarks'),
        ]);

        $fresh = $guide->fresh();
        $recipientIds = $fresh->recipients()->pluck('users.id')->all();
        $this->teachingGuideSync->syncDocumentFromGuide($fresh, $user, $recipientIds);

        $this->notificationService->notifyTeachingGuideApproved($fresh, $user);

        return back()->with('success', 'Teaching guide approved and now visible in Documents.');
    }

    public function reject(Request $request, $id)
    {
        $user = $this->submissionReviewer();

        $request->validate(['remarks' => 'required|string|max:500']);

        $guide = TeachingGuide::query()->visibleTo($user)->findOrFail($id);

        if (!$guide->isPending()) {
            return back()->with('info', 'This guide has already been reviewed.');
        }

        $guide->update([
            'status' => 'rejected',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'remarks' => $request->input('remarks'),
        ]);

        $this->notificationService->notifyTeachingGuideRejected($guide->fresh(), $user);

        return back()->with('success', 'Teaching guide rejected.');
    }

    public function view(Request $request, $id)
    {
        $user = auth()->user();
        $guide = TeachingGuide::with('folder.parent.parent')->visibleTo($user)->findOrFail($id);

        if ($user->isFaculty()) {
            $isOwner = (int) $guide->user_id === (int) $user->id;
            if (!$guide->isApproved() && !$isOwner) {
                abort(403);
            }
        }

        $storageDir = str_starts_with($guide->file_path, 'documents/') ? 'documents' : 'teaching-guides';
        UploadStorage::assertResolvedPath($guide->file_path, $storageDir);

        if (!UploadStorage::exists($guide->file_path)) {
            return back()->with('error', 'This file is no longer available. It was uploaded to a previous storage provider and no longer exists in the current storage.');
        }

        $mime = $guide->file_type === 'pdf'
            ? 'application/pdf'
            : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

        if ($request->boolean('stream')) {
            $this->logSubmissionActivity($user, 'Viewed teaching guide: '.$guide->title, 'teaching_guide_viewed');

            return UploadStorage::inlineResponse($guide->file_path, \App\Support\DocumentNaming::downloadFilename($guide->title, $guide->file_path, $guide->original_filename), $mime);
        }

        $routePrefix = $this->submissionRoutePrefix($user);

        return view('submissions.file-preview', [
            'title' => $guide->title,
            'folderPath' => SubmissionLocation::folderBreadcrumb($guide->folder),
            'documentsUrl' => SubmissionLocation::documentsUrl($user, $guide->folder),
            'streamUrl' => route($routePrefix.'.teaching-guides.view', ['id' => $id, 'stream' => 1]),
            'downloadUrl' => route($routePrefix.'.teaching-guides.download', $id),
            'backUrl' => route($routePrefix.'.teaching-guides.index'),
        ]);
    }

    public function download($id)
    {
        $user = auth()->user();
        $guide = TeachingGuide::visibleTo($user)->findOrFail($id);

        if ($user->isFaculty()) {
            $isOwner = (int) $guide->user_id === (int) $user->id;
            if (!$guide->isApproved() && !$isOwner) {
                abort(403);
            }
        }

        UploadStorage::assertPathAllowed($guide->file_path);

        if (!UploadStorage::exists($guide->file_path)) {
            return back()->with('error', 'This file is no longer available. It was uploaded to a previous storage provider and no longer exists in the current storage.');
        }

        $this->logSubmissionActivity($user, 'Downloaded teaching guide: '.$guide->title, 'teaching_guide_downloaded');

        return UploadStorage::downloadResponse(
            $guide->file_path,
            \App\Support\DocumentNaming::downloadFilename($guide->title, $guide->file_path, $guide->original_filename),
        );
    }

    public function rename(\App\Http\Requests\RenameDocumentRequest $request, $id)
    {
        $user = auth()->user();
        $guide = TeachingGuide::visibleTo($user)->findOrFail($id);

        if ($user->isFaculty()) {
            if ((int) $guide->user_id !== (int) $user->id) {
                abort(403, 'You can only rename your own teaching guides.');
            }
            if (!$guide->isPending()) {
                abort(403, 'Only pending submissions can be renamed.');
            }
        }

        $title = $request->validated('document_title');

        if ($guide->document_id) {
            $document = app(DocumentService::class)->renameDocument((int) $guide->document_id, $user, $title);
            $title = $document->document_title;
        } else {
            $guide->update(['title' => $title]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Teaching guide renamed successfully.',
            'document_title' => $title,
        ]);
    }

    public function destroy($id)
    {
        $user = auth()->user();
        $guide = TeachingGuide::visibleTo($user)->findOrFail($id);

        if ($user->isFaculty()) {
            if ((int) $guide->user_id !== (int) $user->id || !$guide->isRejected()) {
                abort(403, 'You can only delete rejected teaching guides.');
            }
        }

        if ($guide->document_id) {
            $document = Document::find($guide->document_id);
            if ($document && ($user->isDeanOrSecretary() || (int) $document->uploaded_by === (int) $user->id)) {
                app(DocumentService::class)->deleteDocument((int) $guide->document_id, $user);
            }
        }

        if ($guide->file_path && UploadStorage::exists($guide->file_path)) {
            UploadStorage::delete($guide->file_path);
        }

        $guide->delete();

        return back()->with('success', 'Teaching guide deleted.');
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

    private function facultyOwnedGuidesQuery($user, Request $request, string $status)
    {
        $query = TeachingGuide::query()
            ->ownedBy((int) $user->id)
            ->where('status', $status);

        $this->applyTeachingGuideListFilters($query, $request);

        return $query;
    }

    private function applyTeachingGuideListFilters($query, Request $request): void
    {
        $search = $request->query('search');
        $semesterFilter = $request->query('semester');
        $academicYearStart = AcademicYear::startYearFromQuery($request->query('academic_year'));

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%");
            });
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
