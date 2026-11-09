<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Employee;
use App\Models\Task;
use App\Models\DashboardLog;
use App\Models\Document;
use App\Models\DocumentView;
use App\Models\Program;
use App\Services\DashboardService;
use App\Services\DocumentService;
use App\Services\FolderService;
use App\Services\TaskService;
use App\Services\EmployeeService;
use App\Support\CoordinatorDepartment;
use App\Support\CourseAssignment;
use App\Support\EmployeeNumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class CoordinatorController extends Controller
{
    use \App\Http\Controllers\Concerns\HandlesUploadExceptions;
    use \App\Http\Controllers\Concerns\PreventsDuplicateUpload;
    use \App\Http\Controllers\Concerns\ValidatesDocumentUpload;
    use \App\Http\Controllers\Concerns\ManagesUserNotifications;

    public function __construct(
        protected DashboardService $dashboardService,
        protected DocumentService $documentService,
        protected TaskService $taskService,
        protected EmployeeService $employeeService,
        protected FolderService $folderService
    ) {}

    /**
     * Get the authenticated coordinator's department.
     */
    private function getCoordinatorDepartment(): ?string
    {
        return CoordinatorDepartment::name(auth()->user());
    }

    private function requireCoordinatorDepartment(): string
    {
        return CoordinatorDepartment::require(auth()->user());
    }

    /**
     * Faculty across every SITE program. Coordinators manage faculty department-wide.
     */
    private function scopedFacultyQuery()
    {
        return User::with('employee')
            ->where('role_id', 3)
            ->whereHas('employee', fn ($q) => $q->whereIn('program', Program::codes()));
    }

    /**
     * Verify that a faculty employee belongs to one of the SITE programs.
     */
    private function verifyDepartmentAccess(Employee $employee): void
    {
        if (!in_array($employee->program, Program::codes(), true)) {
            abort(403, 'You do not have access to faculty members outside the SITE department.');
        }
    }

    public function dashboard()
    {
        $user = auth()->user();
        $this->requireCoordinatorDepartment();
        $stats = $this->dashboardService->getCoordinatorStats($user->id);

        $recentTasks = Task::with(['assignedBy.employee'])
            ->where('assigned_to', $user->id)
            ->latest()
            ->take(5)
            ->get();

        $facultyList = $this->scopedFacultyQuery()
            ->take(10)
            ->get();

        $recentActivities = $this->dashboardService->getRecentActivities($user, 10);
        $announcements = $this->dashboardService->getAnnouncements($user, 5);
        $docAnalyticsData = $this->dashboardService->getCoordinatorDocumentAnalytics($user->id);
        $operationalInsights = $this->dashboardService->getOperationalInsights($user);

        return view('coordinator.dashboard', array_merge($stats, $docAnalyticsData, compact(
            'recentTasks',
            'facultyList',
            'recentActivities',
            'announcements',
            'operationalInsights'
        )));
    }

    public function activityLog(Request $request)
    {
        $filters = $request->validate([
            'q' => 'nullable|string|max:100',
            'activity_type' => 'nullable|string|max:100',
        ]);

        $user = auth()->user();
        $activities = DashboardLog::getPaginatedLogs($user, 20, $filters);
        $activityTypes = DashboardLog::visibleActivityTypes($user);

        return view('activity-log', compact('activities', 'activityTypes', 'filters'));
    }

    public function tasks()
    {
        $tasks = Task::with(['assignedBy.employee', 'attachments.uploader.employee'])
            ->where('assigned_to', auth()->id())
            ->latest('created_at')
            ->paginate(15);
        return view('coordinator.tasks', compact('tasks'));
    }

    public function updateTask(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:Pending,In Progress,Completed',
        ]);

        $this->taskService->updateTaskByAssignee($id, $validated['status'], auth()->id());

        return redirect()->back()->with('success', 'Task status updated successfully');
    }

    protected function notificationsView(): string
    {
        return 'coordinator.notifications';
    }

    public function faculty()
    {
        $facultyMembers = $this->scopedFacultyQuery()
            ->latest('created_at')
            ->paginate(15);
        return view('coordinator.faculty', compact('facultyMembers'));
    }

    public function createFaculty()
    {
        $facultyNumberPreview = app(EmployeeNumberGenerator::class)->previewMap(EmployeeNumberGenerator::ROLE_FACULTY);
        $defaultProgram = $this->getCoordinatorDepartment();

        return view('coordinator.create-faculty', compact('facultyNumberPreview', 'defaultProgram'));
    }

    public function storeFaculty(Request $request)
    {
        $nameRules = \App\Support\EmployeeNameInput::prepare($request);
        $validated = $request->validate([
            'username'     => 'required|string|unique:users,username|max:20',
            'password'     => 'required|string|min:8|max:40',
            'program'      => ['required', Rule::in(Program::codes())],
            'faculty_type' => 'required|in:full_time,shared',
        ] + $nameRules + CourseAssignment::rules($request->input('program'), true), CourseAssignment::messages());

        try {
            $this->employeeService->createFaculty($validated, auth()->id());

            return redirect()->route('coordinator.faculty')
                ->with('success', 'Faculty account created successfully');
        } catch (\Exception $e) {
            \Log::error('Faculty creation error: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to create faculty account. Please try again.'])
                ->withInput();
        }
    }

    public function documents(Request $request)
    {
        $this->requireCoordinatorDepartment();

        $categoryFilter = $request->query('category');
        $folderFilter = $request->query('folder');
        $tab = $request->query('tab', 'accreditation');

        $folderTree = $this->folderService->getSystemFolderTree(auth()->user());
        $uploadableFolders = $this->folderService->getUploadableFolders();
        $currentFolder = $folderFilter && $folderFilter !== 'uncategorized'
            ? $this->folderService->visibleFolderOrFail((int) $folderFilter, auth()->user())
            : null;
        $breadcrumbs = $currentFolder ? $currentFolder->getAncestors() : [];

        // Scope the list to the active tab so each tab only shows its own files.
        $globalSearch = $request->query('scope') === 'all';
        $effectiveCategory = $globalSearch ? null : $categoryFilter;
        if (!$globalSearch && $effectiveCategory === null && $folderFilter === null) {
            $effectiveCategory = $this->documentService->categoryForTab($tab, $folderTree);
        }

        $documents = $this->documentService->getFilteredDocuments(
            auth()->user(), $effectiveCategory, $globalSearch ? null : $folderFilter, $request->query()
        );
        $recentDocuments = $this->documentService->getRecentDocuments(auth()->id(), 5);
        $favoriteDocuments = $this->documentService->getFavoriteDocuments(auth()->user());
        $categories = $this->documentService->getCategories();
        $uploaders = $this->documentService->getAvailableUploaders(auth()->user());
        $savedFilters = auth()->user()->documentFilters()->latest()->get();
        $searchOptions = $this->documentService->getSearchFilterOptions(auth()->user());

        return view('coordinator.documents', compact(
            'documents', 'recentDocuments', 'favoriteDocuments', 'categories',
            'categoryFilter', 'folderFilter', 'folderTree', 'uploadableFolders',
            'currentFolder', 'breadcrumbs', 'tab', 'uploaders', 'savedFilters'
        ), $searchOptions);
    }

    public function uploadDocument(Request $request)
    {
        if ($duplicate = $this->rejectDuplicateUpload($request)) {
            return $duplicate;
        }

        $validated = $this->validateDocumentUpload($request);
        $recipientIds = $validated['recipient_ids'] ?? [];

        // Block dangerous file extensions (double-extension attack prevention)
        $files = $request->file('documents');
        foreach ($files as $file) {
            $originalName = $file->getClientOriginalName();
            if (preg_match('/\.(php|phtml|phar|exe|sh|bat|cmd|com|cgi|pl|py|jsp|asp|aspx|htaccess)/i', pathinfo($originalName, PATHINFO_FILENAME))) {
                $msg = 'File contains a forbidden extension in its name.';
                return $request->expectsJson()
                    ? response()->json(['success' => false, 'message' => $msg], 422)
                    : back()->with('error', $msg);
            }
        }

        $quotaService = app(\App\Services\StorageQuotaService::class);
        $needed = $quotaService->sumUploadedSizes($files);
        if (!$quotaService->hasQuotaForBytes(auth()->id(), $needed)) {
            $msg = 'Storage quota exceeded (limit: ' . $quotaService->formatBytes(\App\Services\StorageQuotaService::DEFAULT_QUOTA_BYTES) . ').';
            return $request->expectsJson()
                ? response()->json(['success' => false, 'message' => $msg], 422)
                : back()->with('error', $msg);
        }

        try {
            $result = $this->documentService->uploadDocuments(
                $validated, $files, auth()->id(), $recipientIds
            );
        } catch (\Throwable $e) {
            return $this->uploadFailedResponse($request, $e);
        }

        $count = $result['count'];
        $message = $result['submitted_for_approval']
            ? "{$count} file(s) submitted for Dean approval."
            : "{$count} document(s) uploaded and shared successfully.";

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
            ]);
        }

        return redirect()->back()->with('success', $message);
    }

    public function coursesByProgram(Request $request)
    {
        return response()->json(CourseAssignment::coursesFor($request->query('dept')));
    }

    public function editFaculty($id)
    {
        $employee = Employee::with(['user.role', 'user.assignedCourses'])
            ->where('employee_id', $id)
            ->firstOrFail();

        if (!$employee->user->isFaculty()) {
            abort(403, 'Unauthorized access');
        }

        $this->verifyDepartmentAccess($employee);

        $courses           = CourseAssignment::coursesFor($employee->program);
        $assignedCourseIds = $employee->user->assignedCourses->pluck('id')->all();

        return view('coordinator.edit-faculty', compact('employee', 'courses', 'assignedCourseIds'));
    }

    public function updateFaculty(Request $request, $id)
    {
        $employee = Employee::with('user')
            ->where('employee_id', $id)
            ->firstOrFail();

        if (!$employee->user->isFaculty()) {
            abort(403, 'Unauthorized access');
        }

        $this->verifyDepartmentAccess($employee);

        $nameRules = \App\Support\EmployeeNameInput::prepareForUpdate($request, $employee);
        $validated = $request->validate([
            'employee_no'  => 'nullable|string|max:20|unique:employees,employee_no,' . $employee->employee_id . ',employee_id',
            'program'      => ['required', Rule::in(Program::codes())],
            'faculty_type' => 'required|in:full_time,shared',
            'email'        => 'nullable|email|max:45',
            'position'     => 'nullable|string|max:100',
            'hire_date'    => 'nullable|date|before_or_equal:today',
        ] + $nameRules + CourseAssignment::rules($request->input('program')), CourseAssignment::messages());

        try {
            $this->employeeService->updateFaculty($employee, $validated, auth()->id());

            return redirect()->route('coordinator.faculty-profile', $id)
                ->with('success', 'Faculty information updated successfully');
        } catch (\Exception $e) {
            \Log::error('Faculty update error: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to update faculty information. Please try again.'])
                ->withInput();
        }
    }

    public function resetFacultyPassword(Request $request, $id)
    {
        $employee = Employee::with('user')
            ->where('employee_id', $id)
            ->firstOrFail();

        if (!$employee->user->isFaculty()) {
            abort(403, 'Unauthorized access');
        }

        $this->verifyDepartmentAccess($employee);

        $validated = $request->validate([
            'new_password' => 'required|string|min:8|max:40|confirmed',
        ]);

        try {
            $this->employeeService->resetFacultyPassword($employee, $validated['new_password'], auth()->user());

            return redirect()->route('coordinator.faculty-profile', $id)
                ->with('success', 'Password reset successfully. A notification has been sent to the faculty member.');
        } catch (\Exception $e) {
            \Log::error('Password reset error: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to reset password. Please try again.']);
        }
    }

    public function viewEmployeeProfile($id)
    {
        $employee = Employee::with(['user.role'])
            ->where('employee_id', $id)
            ->firstOrFail();

        if (!$employee->user->isFaculty()) {
            abort(403, 'Unauthorized access');
        }

        $this->verifyDepartmentAccess($employee);

        $profileData = $this->employeeService->getEmployeeProfileForCoordinator($id);
        return view('employees.profile', $profileData);
    }

    public function viewDocument($id, Request $request)
    {
        try {
            if ($request->boolean('stream')) {
                return $this->documentService->viewDocument($id, auth()->user(), true);
            }

            return view('submissions.file-preview', $this->documentService->documentPreviewPage($id, auth()->user()));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function downloadDocument($id, Request $request)
    {
        $format = in_array($request->query('format'), ['pdf', 'word']) ? $request->query('format') : 'word';
        try {
            return $this->documentService->downloadDocument($id, auth()->user(), $format);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function deleteDocument($id)
    {
        $this->documentService->deleteDocument($id, auth()->user());
        return redirect()->back()->with('success', 'Document moved to Recycle Bin.');
    }

    public function renameDocument(\App\Http\Requests\RenameDocumentRequest $request, $id)
    {
        $document = $this->documentService->renameDocument(
            (int) $id,
            auth()->user(),
            $request->validated('document_title')
        );

        return response()->json([
            'success' => true,
            'message' => 'Document renamed successfully.',
            'document_title' => $document->document_title,
        ]);
    }

    public function toggleFavorite($id)
    {
        $result = $this->documentService->toggleFavorite($id, auth()->id());
        return response()->json(['success' => true, 'favorited' => $result['favorited'], 'message' => $result['message']]);
    }
}
