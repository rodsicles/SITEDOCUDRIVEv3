<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\ExamQuestionnaire;
use App\Models\SchoolYear;
use App\Models\TeachingGuide;
use App\Services\SchoolYearArchiveDeletionService;
use App\Services\SchoolYearService;
use App\Services\ArchiveContentService;
use App\Support\AcademicYear;
use App\Support\CoordinatorDepartment;
use Illuminate\Http\Request;

class SchoolYearController extends Controller
{
    public function __construct(
        protected SchoolYearService $schoolYearService,
    ) {}

    /**
     * Show the archive management page (Dean only).
     */
    public function index()
    {
        $activeSchoolYear = $this->schoolYearService->getActive();
        $archivedYears = SchoolYear::archived()
            ->with('archivedByUser.employee')
            ->withCount(['documents', 'teachingGuides', 'examQuestionnaires', 'folders'])
            ->get();

        // Archiving, restoring and deleting school years stay with the Dean office; Admin browses only.
        $canManageArchives = !auth()->user()->isAdmin();
        $allowArchiveHardDelete = $canManageArchives && (bool) config('school_year.allow_archive_hard_delete', false);

        // Counts for active year
        $activeDocCount = Document::where(function ($q) use ($activeSchoolYear) {
            $q->where('school_year_id', $activeSchoolYear->id)
              ->orWhereNull('school_year_id');
        })->count();
        $activeYearScope = function ($q) use ($activeSchoolYear) {
            $q->where('school_year_id', $activeSchoolYear->id)
              ->orWhereNull('school_year_id');
        };

        $activeTgCount = TeachingGuide::where($activeYearScope)->approved()->count();
        $activeEqCount = ExamQuestionnaire::where($activeYearScope)->approved()->count();

        $pendingTgCount = TeachingGuide::where($activeYearScope)
            ->where(fn ($q) => $q->where('status', 'pending')->orWhereNull('status'))
            ->count();
        $pendingEqCount = ExamQuestionnaire::where($activeYearScope)
            ->where(fn ($q) => $q->where('status', 'pending')->orWhereNull('status'))
            ->count();
        $rejectedTgCount = TeachingGuide::where($activeYearScope)->where('status', 'rejected')->count();
        $rejectedEqCount = ExamQuestionnaire::where($activeYearScope)->where('status', 'rejected')->count();

        // Suggest next school year
        $suggestedStartYear = $activeSchoolYear->start_year + 1;

        return view('dean.archives', compact(
            'activeSchoolYear', 'archivedYears',
            'activeDocCount', 'activeTgCount', 'activeEqCount',
            'pendingTgCount', 'pendingEqCount', 'rejectedTgCount', 'rejectedEqCount',
            'suggestedStartYear',
            'allowArchiveHardDelete', 'canManageArchives',
        ));
    }

    /**
     * Permanently delete an archived school year and all tagged data (Dean dry-run cleanup).
     */
    public function destroyArchived(Request $request, $id, SchoolYearArchiveDeletionService $deletionService)
    {
        $schoolYear = SchoolYear::findOrFail($id);

        $validated = $request->validate([
            'confirm_name' => 'required|string|max:50',
            'confirm_phrase' => 'required|string|max:50',
        ]);

        if ($validated['confirm_name'] !== $schoolYear->name) {
            return back()->withErrors([
                'confirm_name' => 'School year name does not match.',
            ]);
        }

        if ($validated['confirm_phrase'] !== 'DELETE PERMANENTLY') {
            return back()->withErrors([
                'confirm_phrase' => 'Type DELETE PERMANENTLY in all caps to confirm.',
            ]);
        }

        try {
            $name = $schoolYear->name;
            $summary = $deletionService->permanentlyDeleteArchived($schoolYear, auth()->user());

            $detail = sprintf(
                'Removed %d document(s), %d teaching guide(s), %d exam questionnaire(s), %d exam record(s), %d folder(s).',
                $summary['documents'],
                $summary['teaching_guides'],
                $summary['exam_questionnaires'],
                $summary['exam_records'],
                $summary['folders'],
            );

            return redirect()
                ->route('dean.archives.index')
                ->with('success', "Archived school year \"{$name}\" was permanently deleted. {$detail}");
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors([
                'error' => $e->getMessage() ?: 'Failed to delete archived school year.',
            ]);
        }
    }


    /**
     * Restore an archived school year as the active year (Dean only).
     */
    public function restoreArchived(Request $request, $id)
    {
        $schoolYear = SchoolYear::findOrFail($id);

        $validated = $request->validate([
            'confirm_name' => 'required|string|max:50',
            'confirm_phrase' => 'required|string|max:50',
        ]);

        if ($validated['confirm_name'] !== $schoolYear->name) {
            return back()->withErrors([
                'confirm_name' => 'School year name does not match.',
            ]);
        }

        if ($validated['confirm_phrase'] !== 'RESTORE AS ACTIVE') {
            return back()->withErrors([
                'confirm_phrase' => 'Type RESTORE AS ACTIVE in all caps to confirm.',
            ]);
        }

        try {
            $result = $this->schoolYearService->restoreArchivedAsActive($schoolYear, auth()->user());
            $restored = $result['schoolYear'];
            $removed = $result['removed'];

            $message = "School year restored. Now active: {$restored->name}. Faculty performance and analytics will use this school year.";

            if ($removed) {
                $message .= sprintf(
                    ' Removed the previous active year: %d document(s), %d teaching guide(s), %d exam questionnaire(s), %d folder(s).',
                    $removed['documents'],
                    $removed['teaching_guides'],
                    $removed['exam_questionnaires'],
                    $removed['folders'],
                );
            }

            return redirect()
                ->route('dean.archives.index')
                ->with('success', $message);
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors([
                'error' => $e->getMessage() ?: 'Failed to restore archived school year.',
            ]);
        }
    }
    /**
     * Execute the archive action (Dean only).
     */
    public function archive(Request $request)
    {
        $request->validate([
            'archive_name' => 'required|string|max:50',
            'new_name' => 'required|string|max:50',
            'new_start_year' => 'required|integer|min:2020|max:2099',
        ]);

        $user = auth()->user();

        // Prevent archiving if a year with that start_year already exists
        if (SchoolYear::where('start_year', $request->new_start_year)->exists()) {
            return back()->withInput()->with('error', 'A school year starting in ' . $request->new_start_year . ' already exists.');
        }

        $result = $this->schoolYearService->archive(
            $user,
            $request->archive_name,
            $request->new_name,
            (int) $request->new_start_year
        );

        $newSchoolYear = $result['schoolYear'];
        $message = "School year archived successfully. Now active: {$newSchoolYear->name}";

        if (($result['detached']['total'] ?? 0) > 0) {
            $message .= ' Pending and rejected teaching guides and exam questionnaires were not archived; they remain in the active year for review or faculty cleanup.';
        }

        return redirect()->route('dean.archives.index')->with('success', $message);
    }

    /**
     * Browse an archived school year's content (all roles).
     */
    public function show($id, ArchiveContentService $archiveContent)
    {
        $schoolYear = SchoolYear::findOrFail($id);
        $user = auth()->user();

        if ($user->isProgramCoordinator()) {
            CoordinatorDepartment::require($user);
        }

        if (!$schoolYear->isArchived()) {
            return redirect()->route('dean.archives.index');
        }

        $canAddToArchive = $archiveContent->canAdd($user);
        $selectedArchiveFolder = request()->filled('folder') && $canAddToArchive
            ? $archiveContent->folderOrFail($schoolYear, (int) request('folder'), $user)
            : null;
        $archiveFolders = $canAddToArchive
            ? $archiveContent->folders($schoolYear, $user, $selectedArchiveFolder?->folder_id)
            : collect();

        $documentsQuery = Document::where('school_year_id', $schoolYear->id)
            ->visibleTo($user)
            ->with('uploader.employee', 'folder');
        if ($selectedArchiveFolder) {
            $documentsQuery->whereIn('folder_id', Folder::descendantIdsIncludingSelf($selectedArchiveFolder->folder_id));
        }

        $archiveSearch = trim((string) request('q', ''));

        if ($archiveSearch !== '') {
            $this->applyArchiveDocumentSearch($documentsQuery, $archiveSearch);
        }

        $documents = $documentsQuery->latest()->paginate(20)->withQueryString();

        $teachingGuidesQuery = TeachingGuide::where('school_year_id', $schoolYear->id)
            ->approved()
            ->visibleTo($user)
            ->where(fn ($q) => $q->whereNull('folder_id')->orWhereHas('folder', fn ($folder) => $folder->visibleTo($user)))
            ->with('uploader.employee');
        if ($selectedArchiveFolder) {
            $teachingGuidesQuery->whereIn('folder_id', Folder::descendantIdsIncludingSelf($selectedArchiveFolder->folder_id));
        }

        if ($archiveSearch !== '') {
            $this->applyArchiveTeachingGuideSearch($teachingGuidesQuery, $archiveSearch);
        }

        $teachingGuides = $teachingGuidesQuery->latest()->paginate(20)->withQueryString();

        $examQuestionnairesQuery = ExamQuestionnaire::where('school_year_id', $schoolYear->id)
            ->approved()
            ->visibleTo($user)
            ->where(fn ($q) => $q->whereDoesntHave('document')->orWhereHas('document', fn ($document) => $document->visibleTo($user)))
            ->with('submitter.employee');
        if ($selectedArchiveFolder) {
            $examQuestionnairesQuery->whereHas('document', fn ($q) => $q->whereIn('folder_id', Folder::descendantIdsIncludingSelf($selectedArchiveFolder->folder_id)));
        }

        if ($archiveSearch !== '') {
            $this->applyArchiveExamQuestionnaireSearch($examQuestionnairesQuery, $archiveSearch);
        }

        $examQuestionnaires = $examQuestionnairesQuery->latest()->paginate(20)->withQueryString();

        $role = $this->getViewRole();
        $sidebarRole = $user->isSecretary() ? 'secretary' : $role;

        return view('archives.show', compact(
            'schoolYear', 'documents', 'teachingGuides', 'examQuestionnaires', 'role', 'archiveSearch',
            'canAddToArchive', 'archiveFolders', 'selectedArchiveFolder', 'sidebarRole'
        ));
    }

    /**
     * List archived years for browsing (all roles).
     */
    public function list()
    {
        $user = auth()->user();

        if ($user->isProgramCoordinator()) {
            CoordinatorDepartment::require($user);
        }

        $archivedYears = SchoolYear::archived()
            ->withCount([
                'documents' => fn ($q) => $q->visibleTo($user),
                'teachingGuides as teaching_guides_count' => fn ($q) => $q->approved()->visibleTo($user),
                'examQuestionnaires as exam_questionnaires_count' => fn ($q) => $q->approved()->visibleTo($user),
            ])
            ->get();

        $role = $this->getViewRole();
        $sidebarRole = $user->isSecretary() ? 'secretary' : $role;

        return view('archives.index', compact('archivedYears', 'role', 'sidebarRole'));
    }

    private function getViewRole(): string
    {
        $user = auth()->user();
        if ($user->isDeanOrSecretary()) return 'dean';
        if ($user->isProgramCoordinator()) return 'coordinator';
        return 'faculty';
    }

    private function applyArchiveDocumentSearch($query, string $search): void
    {
        $query->where(function ($q) use ($search) {
            $q->where('document_title', 'like', '%'.$search.'%')
                ->orWhere('category', 'like', '%'.$search.'%')
                ->orWhereHas('uploader', function ($uploaderQuery) use ($search) {
                    $uploaderQuery->where('username', 'like', '%'.$search.'%')
                        ->orWhereHas('employee', fn ($employeeQuery) => $employeeQuery->where('full_name', 'like', '%'.$search.'%'));
                });
        });
    }

    private function applyArchiveTeachingGuideSearch($query, string $search): void
    {
        $query->where(function ($q) use ($search) {
            $q->where('title', 'like', '%'.$search.'%')
                ->orWhere('subject', 'like', '%'.$search.'%')
                ->orWhereHas('uploader', function ($uploaderQuery) use ($search) {
                    $uploaderQuery->where('username', 'like', '%'.$search.'%')
                        ->orWhereHas('employee', fn ($employeeQuery) => $employeeQuery->where('full_name', 'like', '%'.$search.'%'));
                });
        });
    }

    private function applyArchiveExamQuestionnaireSearch($query, string $search): void
    {
        $query->where(function ($q) use ($search) {
            $q->where('title', 'like', '%'.$search.'%')
                ->orWhere('subject', 'like', '%'.$search.'%')
                ->orWhere('exam_type', 'like', '%'.$search.'%')
                ->orWhereHas('submitter', function ($submitterQuery) use ($search) {
                    $submitterQuery->where('username', 'like', '%'.$search.'%')
                        ->orWhereHas('employee', fn ($employeeQuery) => $employeeQuery->where('full_name', 'like', '%'.$search.'%'));
                });
        });
    }
}
