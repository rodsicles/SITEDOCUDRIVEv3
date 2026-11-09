<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DeanController;
use App\Http\Controllers\CoordinatorController;
use App\Http\Controllers\FacultyController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\ProfessionalDevelopmentController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\TeachingGuideController;
use App\Http\Controllers\ExamQuestionnaireController;
use App\Http\Controllers\DocumentFilterController;
use App\Http\Controllers\DocumentRecipientController;
use App\Http\Controllers\DocumentListSearchController;
use App\Http\Controllers\TaskAttachmentController;
use App\Http\Controllers\SchoolYearController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\DeanCourseController;
use App\Http\Controllers\CoordinatorCourseController;
use App\Http\Controllers\TgSubjectFolderController;
use App\Http\Controllers\EqSubjectFolderController;
use App\Http\Controllers\RecycleBinController;
use App\Http\Controllers\ForgotPasswordController;
use App\Http\Controllers\PasswordResetRequestController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\DocumentRequestController;
use App\Http\Controllers\DocumentSearchController;
use App\Http\Controllers\ArchiveContentController;

// Authentication Routes
Route::get('/', [AuthController::class, 'showLogin'])->name('login');
Route::get('/login', [AuthController::class, 'showLogin'])->name('login.page');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1')->name('login.post');
// Public registration disabled – accounts are created by Dean/Secretary via admin panel.
// Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
// Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1')->name('register.post');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// Public forgot-password request flow. The user submits their username and
// the request is queued for the Dean/Secretary to review. Aggressive IP-level
// throttle on top of the per-username throttle inside the controller.
Route::get('/password/forgot', [ForgotPasswordController::class, 'show'])
    ->name('password.forgot.show');
Route::post('/password/forgot', [ForgotPasswordController::class, 'submit'])
    ->middleware('throttle:10,60')
    ->name('password.forgot.submit');

// Mandatory password-change gate (locked overlay) for accounts created by an
// admin with a temporary password. Throttled to mitigate temp-password brute force.
Route::middleware('auth')->group(function () {
    Route::get('/password/force-change', [AuthController::class, 'showForceChange'])
        ->name('password.force-change.show');
    Route::post('/password/force-change', [AuthController::class, 'forceChange'])
        ->middleware('throttle:5,1')
        ->name('password.force-change.update');
});

// Global Search (All authenticated users)
Route::get('/search', [SearchController::class, 'search'])->middleware(['auth', 'no.back', 'throttle:20,1'])->name('search');

Route::middleware(['auth', 'no.back'])->group(function () {
    Route::post('/document-categories', [\App\Http\Controllers\DocumentCategoryController::class, 'store'])->middleware('throttle:20,1')->name('document-categories.store');
    Route::patch('/document-categories/{category}', [\App\Http\Controllers\DocumentCategoryController::class, 'update'])->middleware('throttle:20,1')->name('document-categories.update');
    Route::get('/upload-destinations', [\App\Http\Controllers\UploadDestinationController::class, 'index'])->name('upload-destinations.index');
    Route::post('/upload-destinations/subject', [\App\Http\Controllers\UploadDestinationController::class, 'subject'])->middleware('throttle:30,1')->name('upload-destinations.subject');
    Route::get('/document-requests', [DocumentRequestController::class, 'index'])->name('document-requests.index');
    Route::get('/document-requests/recipients', [DocumentRequestController::class, 'recipients'])->name('document-requests.recipients');
    Route::post('/document-requests', [DocumentRequestController::class, 'store'])->middleware('throttle:30,60')->name('document-requests.store');
    Route::get('/document-requests/{documentRequest}/manage', [DocumentRequestController::class, 'manage'])->name('document-requests.manage');
    Route::patch('/document-requests/{documentRequest}', [DocumentRequestController::class, 'change'])->middleware('throttle:30,1')->name('document-requests.change');
    Route::post('/document-request-recipients/{recipient}/submit', [DocumentRequestController::class, 'submit'])->middleware('throttle:10,60')->name('document-requests.submit');
    Route::post('/document-request-recipients/{recipient}/review', [DocumentRequestController::class, 'review'])->middleware('throttle:30,1')->name('document-requests.review');

    Route::get('/document-search', [DocumentSearchController::class, 'index'])->name('document-search.index');
    Route::post('/document-search/saved', [DocumentSearchController::class, 'save'])->middleware('throttle:20,60')->name('document-search.saved.store');
    Route::delete('/document-search/saved/{savedSearch}', [DocumentSearchController::class, 'destroy'])->name('document-search.saved.destroy');
    Route::post('/document-search/duplicate', [DocumentSearchController::class, 'duplicate'])->middleware('throttle:60,1')->name('document-search.duplicate');
    Route::post('/document-search/reindex/{documentId}', [DocumentSearchController::class, 'reindex'])->middleware('throttle:30,1')->name('document-search.reindex');
    Route::patch('/folders/{folder}/privacy', [FolderController::class, 'privacy'])->middleware('throttle:20,1')->name('folders.privacy');
});

// Late additions to an archived school year retain its year tag and current role permissions.
Route::middleware(['auth', 'no.back'])->prefix('archive-contents/{year}')->name('archive-contents.')->group(function () {
    Route::get('/destinations', [ArchiveContentController::class, 'destinations'])->name('destinations');
    Route::post('/subjects', [ArchiveContentController::class, 'subject'])->middleware('throttle:30,1')->name('subjects');
    Route::post('/documents', [ArchiveContentController::class, 'upload'])->middleware('throttle:6,60')->name('upload');
    Route::post('/folders', [ArchiveContentController::class, 'createFolder'])->middleware('throttle:30,60')->name('folders.store');
    Route::patch('/folders/{folder}', [ArchiveContentController::class, 'renameFolder'])->middleware('throttle:30,60')->name('folders.rename');
    Route::patch('/documents/{document}', [ArchiveContentController::class, 'renameDocument'])->middleware('throttle:60,1')->name('documents.rename');
    Route::patch('/teaching-guides/{guide}', [ArchiveContentController::class, 'renameGuide'])->middleware('throttle:60,1')->name('guides.rename');
    Route::patch('/exam-questionnaires/{questionnaire}', [ArchiveContentController::class, 'renameQuestionnaire'])->middleware('throttle:60,1')->name('questionnaires.rename');
});

// Teaching Guides: open or create subject folder (TG/LB) from semester picker
Route::middleware(['auth', 'no.back', 'role:Dean,Admin,Program Coordinator,Faculty Employee'])->prefix('teacher-loads')->name('teacher-loads.')->group(function () {
    $controller = \App\Http\Controllers\TeacherLoadController::class;
    Route::get('/', [$controller, 'index'])->name('index');
    Route::get('/options', [$controller, 'options'])->name('options');
    Route::post('/preview', [$controller, 'preview'])->middleware('throttle:30,1')->name('preview');
    Route::post('/', [$controller, 'store'])->middleware('throttle:30,1')->name('store');
    Route::get('/{id}', [$controller, 'show'])->whereNumber('id')->name('show');
    Route::patch('/{id}', [$controller, 'update'])->whereNumber('id')->middleware('throttle:30,1')->name('update');
    Route::post('/{id}/finalize', [$controller, 'finalize'])->whereNumber('id')->middleware('throttle:20,1')->name('finalize');
    Route::post('/{id}/change', [$controller, 'change'])->whereNumber('id')->middleware('throttle:20,1')->name('change');
    Route::get('/{id}/history', [$controller, 'history'])->whereNumber('id')->name('history');
    Route::get('/{id}/history/{revision}/pdf', [$controller, 'revisionPdf'])->whereNumber(['id', 'revision'])->name('revision-pdf');
    Route::get('/{id}/pdf', [$controller, 'export'])->whereNumber('id')->middleware('throttle:30,1')->name('pdf');
});

Route::middleware(['auth', 'no.back', 'role:Dean,Admin,Program Coordinator'])->prefix('course-sections')->name('course-sections.')->group(function () {
    Route::post('/', [\App\Http\Controllers\CourseSectionController::class, 'store'])->name('store');
    Route::patch('/{id}', [\App\Http\Controllers\CourseSectionController::class, 'update'])->name('update');
    Route::patch('/{id}/status', [\App\Http\Controllers\CourseSectionController::class, 'status'])->name('status');
});

Route::post('/documents/open-tg-subject', [TgSubjectFolderController::class, 'store'])
    ->middleware(['auth', 'no.back', 'throttle:30,1'])
    ->name('documents.open-tg-subject');

Route::post('/documents/open-eq-subject', [EqSubjectFolderController::class, 'store'])
    ->middleware(['auth', 'no.back', 'throttle:30,1'])
    ->name('documents.open-eq-subject');

// Profile Management (All authenticated users)
Route::middleware(['auth', 'no.back'])->prefix('profile')->name('profile.')->group(function () {
    Route::get('/edit', [ProfileController::class, 'edit'])->name('edit');
    Route::post('/update', [ProfileController::class, 'update'])->middleware('throttle:10,1')->name('update');
    Route::post('/avatar', [ProfileController::class, 'uploadAvatar'])->middleware('throttle:10,1')->name('avatar');
    Route::post('/change-password', [ProfileController::class, 'changePassword'])->middleware('throttle:10,1')->name('change-password');
});

Route::middleware(['auth', 'no.back'])->prefix('document-filters')->name('document-filters.')->group(function () {
    Route::post('/', [DocumentFilterController::class, 'store'])->middleware('throttle:10,60')->name('store');
    Route::delete('/{id}', [DocumentFilterController::class, 'destroy'])->name('destroy');
});

Route::middleware(['auth', 'no.back'])->prefix('task-attachments')->name('task-attachments.')->group(function () {
    Route::get('/{id}/download', [TaskAttachmentController::class, 'download'])->middleware('throttle:30,1')->name('download');
});

// Document version download/view — shared across all roles (auth + canView checked in controller)
Route::middleware(['auth', 'no.back'])->prefix('document-versions')->name('document-versions.')->group(function () {
    Route::get('/{id}/view', [\App\Http\Controllers\DocumentVersionController::class, 'viewVersion'])
        ->middleware('throttle:60,1')
        ->name('view');
    Route::get('/{id}/download', [\App\Http\Controllers\DocumentVersionController::class, 'downloadVersion'])
        ->middleware('throttle:30,1')
        ->name('download');
});

// Announcements (All authenticated users can view, only Dean/Coordinator can create/edit/delete)
Route::middleware(['auth', 'no.back'])->prefix('announcements')->name('announcements.')->group(function () {
    Route::get('/', [AnnouncementController::class, 'index'])->name('index');
    Route::post('/{id}/read', [AnnouncementController::class, 'markAsRead'])->middleware('throttle:60,1')->name('read');
    Route::post('/{id}/react', [AnnouncementController::class, 'toggleReaction'])
        ->middleware('throttle:60,1')
        ->name('react');
    Route::get('/{id}/receipts', [AnnouncementController::class, 'receipts'])
        ->middleware('throttle:60,1')
        ->name('receipts');


    // Only Dean, Admin and Coordinator can create/edit/delete announcements
    Route::middleware('role:Dean,Admin,Program Coordinator')->group(function () {
        Route::get('/create', [AnnouncementController::class, 'create'])->name('create');
        Route::post('/', [AnnouncementController::class, 'store'])->middleware('throttle:10,60')->name('store');
        Route::get('/{id}/edit', [AnnouncementController::class, 'edit'])->name('edit');
        Route::put('/{id}', [AnnouncementController::class, 'update'])->name('update');
        Route::delete('/{id}', [AnnouncementController::class, 'destroy'])->name('destroy');
    });
});

// Professional Development (All roles view, Faculty manages own)
Route::middleware(['auth', 'no.back'])->prefix('professional-development')->name('professional-development.')->group(function () {
    Route::get('/', [ProfessionalDevelopmentController::class, 'index'])->name('index');
    Route::post('/', [ProfessionalDevelopmentController::class, 'store'])->middleware('role:Faculty Employee')->name('store');
    Route::put('/{id}', [ProfessionalDevelopmentController::class, 'update'])->middleware('role:Faculty Employee')->name('update');
    Route::delete('/{id}', [ProfessionalDevelopmentController::class, 'destroy'])->middleware('role:Faculty Employee')->name('destroy');
    Route::get('/{id}/certificate', [ProfessionalDevelopmentController::class, 'certificate'])->name('certificate');
});

// User Guide (All authenticated users)
Route::middleware(['auth', 'no.back'])->get('/user-guide', function () {
    return view('user-guide');
})->name('user-guide');

// Course edit history uses the same program permissions as the catalog.
Route::middleware(['auth', 'no.back'])->get('/courses/{course}/history', \App\Http\Controllers\CourseHistoryController::class)->name('courses.history');

// Faculty report files (owner, Dean/Secretary, Coordinator)
Route::middleware(['auth', 'no.back'])->prefix('reports')->name('reports.')->group(function () {
    Route::get('/{id}/view', [ReportController::class, 'view'])->middleware('throttle:60,1')->name('view');
    Route::get('/{id}/download', [ReportController::class, 'download'])->middleware('throttle:30,1')->name('download');
});

// Password Reset Requests (Dean + Secretary + Admin review queue). Kept outside the
// dean.* group so the URL stays generic (/password-reset-requests) and can be
// linked to from every sidebar without prefix surprises. Who may handle which
// request is enforced in PasswordResetRequestService::canHandle().
Route::middleware(['auth', 'no.back', 'role:Dean,Secretary,Admin'])
    ->prefix('password-reset-requests')
    ->name('password-reset-requests.')
    ->group(function () {
        Route::get('/', [PasswordResetRequestController::class, 'index'])->name('index');
        Route::post('/{id}/approve', [PasswordResetRequestController::class, 'approve'])
            ->middleware('throttle:30,1')
            ->name('approve');
        Route::post('/{id}/deny', [PasswordResetRequestController::class, 'deny'])
            ->middleware('throttle:30,1')
            ->name('deny');
    });

// Dean office routes: Dean, Secretary and Admin. Routes marked 'role:Dean,Secretary'
// (or checked with isDean() in the controller) stay unavailable to Admin.
Route::middleware(['auth', 'no.back', 'role:Dean,Secretary,Admin'])->prefix('dean')->name('dean.')->group(function () {
    Route::get('/dashboard', [DeanController::class, 'dashboard'])->name('dashboard');
    Route::get('/employees', [DeanController::class, 'employees'])->name('employees');
    Route::get('/employees/{id}/profile', [DeanController::class, 'viewEmployeeProfile'])->name('employee-profile');

    // Account Management
    Route::post('/accounts/coordinator', [DeanController::class, 'storeCoordinator'])->middleware(['throttle:10,60', 'role:Dean,Secretary'])->name('store-coordinator');
    Route::post('/accounts/admin', [DeanController::class, 'storeAdmin'])->middleware(['throttle:10,60', 'role:Dean'])->name('store-admin');
    Route::post('/accounts/{id}/temporary-password', [DeanController::class, 'issueTemporaryPassword'])->middleware('throttle:10,1')->name('temporary-password');
    Route::post('/accounts/faculty', [DeanController::class, 'storeFaculty'])->middleware('throttle:10,60')->name('store-faculty');
    Route::get('/accounts/{id}/edit', [DeanController::class, 'editEmployee'])->name('edit-employee');
    Route::patch('/accounts/{id}', [DeanController::class, 'updateEmployee'])->middleware('throttle:10,60')->name('update-employee');
    Route::post('/accounts/{id}/deactivate', [DeanController::class, 'deactivateEmployee'])->middleware('throttle:10,60')->name('deactivate-employee');
    Route::post('/accounts/{id}/reactivate', [DeanController::class, 'reactivateEmployee'])->middleware('throttle:10,60')->name('reactivate-employee');
    Route::post('/accounts/{id}/permanent-delete', [DeanController::class, 'destroyEmployee'])->middleware(['throttle:5,60', 'role:Dean,Secretary'])->name('destroy-employee');
    Route::post('/accounts/{id}/reset-password', [DeanController::class, 'resetEmployeePassword'])->middleware('throttle:5,1')->name('reset-password');

    Route::get('/courses/by-department', [\App\Http\Controllers\DeanController::class, 'coursesByDepartment'])->name('courses.by-department');
    Route::get('/courses/by-program', [\App\Http\Controllers\DeanController::class, 'coursesByDepartment'])->name('courses.by-program');
    Route::get('/courses', [DeanCourseController::class, 'index'])->name('courses');
    Route::post('/courses', [DeanCourseController::class, 'store'])->name('courses.store');
    Route::patch('/courses/{course}', [DeanCourseController::class, 'update'])->name('courses.update');
    Route::delete('/courses/{course}', [DeanCourseController::class, 'destroy'])->name('courses.destroy');
    Route::post('/courses/{course}/restore', [DeanCourseController::class, 'restore'])->name('courses.restore');

    Route::get('/reports', [DeanController::class, 'reports'])->name('reports');
    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics');
    Route::post('/analytics/close-month', [AnalyticsController::class, 'closeMonth'])->middleware('throttle:3,60')->name('analytics.close-month');
    Route::post('/analytics/reset-this-month', [AnalyticsController::class, 'resetThisMonth'])->middleware('throttle:3,60')->name('analytics.reset-this-month');
    Route::get('/activity-log', [DeanController::class, 'activityLog'])->name('activity-log');
    Route::get('/audit-trail', [DeanController::class, 'auditTrail'])->name('audit-trail');
    Route::post('/audit-trail/reset', [DeanController::class, 'resetActivityLog'])->middleware('throttle:3,60')->name('audit-trail.reset');

    Route::get('/notifications', [DeanController::class, 'notifications'])->name('notifications');
    Route::get('/notifications/unread-count', [DeanController::class, 'unreadNotificationCount'])->name('notifications.unread-count');
    Route::get('/notifications/recent', [DeanController::class, 'recentNotificationsJson'])->name('notifications.recent');
    Route::post('/notifications/{id}/read-json', [DeanController::class, 'markNotificationReadJson'])->middleware('throttle:120,1')->name('notifications.read-json');
    Route::post('/notifications/{id}/read', [DeanController::class, 'markNotificationRead'])->middleware('throttle:120,1')->name('mark-notification-read');
    Route::post('/notifications/mark-all-read', [DeanController::class, 'markAllNotificationsRead'])->middleware('throttle:30,1')->name('notifications.mark-all-read');
    Route::post('/notifications/mark-all-read-json', [DeanController::class, 'markAllNotificationsReadJson'])->middleware('throttle:30,1')->name('notifications.mark-all-read-json');
    Route::post('/notifications/clear-json', [DeanController::class, 'clearNotificationsJson'])->middleware('throttle:5,1')->name('notifications.clear-json');

    Route::post('/insight/refresh', [DeanController::class, 'refreshInsight'])
        ->middleware('throttle:15,1')
        ->name('insight.refresh');

    // Tasks
    Route::get('/tasks', [DeanController::class, 'tasks'])->name('tasks');
    Route::get('/tasks/create', [DeanController::class, 'createTask'])->name('create-task');
    Route::post('/tasks', [DeanController::class, 'storeTask'])->name('store-task');
    Route::patch('/tasks/{id}', [DeanController::class, 'updateTask'])->middleware('throttle:30,1')->name('update-task');
    // NOTE: Dean attachment-upload UI was removed; this route is intentionally not exposed.

    Route::get('/documents', [DeanController::class, 'documents'])->name('documents');
    Route::get('/documents/list-search', [DocumentListSearchController::class, 'suggest'])->name('documents.list-search');
    Route::get('/documents/recipient-search', [DocumentRecipientController::class, 'search'])->name('documents.recipient-search');
    Route::post('/documents', [DeanController::class, 'uploadDocument'])->middleware('throttle:20,60')->name('upload-document');
    Route::get('/documents/{id}/view', [DeanController::class, 'viewDocument'])->name('view-document');
    Route::get('/documents/{id}/download', [DeanController::class, 'downloadDocument'])->name('download-document');
    Route::delete('/documents/{id}', [DeanController::class, 'deleteDocument'])->name('delete-document');
    Route::delete('/documents/{id}/permanent-delete', [RecycleBinController::class, 'forceDeleteActive'])->middleware(['throttle:10,60', 'role:Dean,Secretary'])->name('documents.force-delete');
    Route::get('/documents/{id}/delete-impact', [RecycleBinController::class, 'deleteImpact'])->middleware('role:Dean,Secretary')->name('documents.delete-impact');
    Route::patch('/documents/{id}/rename', [DeanController::class, 'renameDocument'])->middleware('throttle:60,1')->name('rename-document');
    Route::post('/documents/{id}/favorite', [DeanController::class, 'toggleFavorite'])->middleware('throttle:60,1')->name('toggle-favorite');

    Route::get('/recycle-bin', [RecycleBinController::class, 'index'])->name('recycle-bin.index');
    Route::post('/recycle-bin/{id}/restore', [RecycleBinController::class, 'restore'])->middleware('throttle:60,1')->name('recycle-bin.restore');
    Route::delete('/recycle-bin/{id}', [RecycleBinController::class, 'forceDelete'])->middleware(['throttle:60,1', 'role:Dean,Secretary'])->name('recycle-bin.force-delete');
    Route::post('/recycle-bin/bulk-force-delete', [RecycleBinController::class, 'bulkForceDelete'])->middleware(['throttle:10,1', 'role:Dean,Secretary'])->name('recycle-bin.bulk-force-delete');

    // Rejected Teaching Guides / Exam Questionnaires across all programs (Dean + Admin oversight)
    Route::get('/rejected-files', [\App\Http\Controllers\RejectedFilesController::class, 'index'])->middleware('role:Dean,Admin')->name('rejected-files.index');

    // Folder Management - Rate Limited: 3 folders per hour
    Route::post('/folders', [FolderController::class, 'store'])->middleware('throttle:30,60')->name('folders.store');
    Route::patch('/folders/{folder}', [FolderController::class, 'update'])->middleware('throttle:30,60')->name('folders.update');
    Route::delete('/folders/{folder}', [FolderController::class, 'destroy'])->middleware('throttle:30,60')->name('folders.destroy');
    Route::get('/folders/list', [FolderController::class, 'getUserFolders'])->name('folders.list');
    Route::post('/documents/{document}/move', [FolderController::class, 'moveDocument'])->name('documents.move');
    Route::post('/documents/{document}/copy', [FolderController::class, 'copyDocument'])->middleware('throttle:30,1')->name('documents.copy');
    Route::post('/documents/{document}/version', [\App\Http\Controllers\DocumentVersionController::class, 'store'])->middleware('throttle:10,60')->name('documents.version');

    // Backup & Restore - rate limited because operations are destructive / heavy (not available to Admin)
    Route::middleware('role:Dean,Secretary')->group(function () {
        Route::get('/backup', [BackupController::class, 'index'])->name('backup');
        Route::post('/backup/create', [BackupController::class, 'create'])->middleware('throttle:3,60')->name('backup.create');
        Route::get('/backup/download/{filename}', [BackupController::class, 'download'])->middleware('throttle:20,60')->name('backup.download');
        Route::post('/backup/restore', [BackupController::class, 'restore'])->middleware('throttle:2,60')->name('backup.restore');
        Route::delete('/backup/{filename}', [BackupController::class, 'destroy'])->middleware('throttle:10,60')->name('backup.destroy');
    });

    // Teaching Guides (Dean + Secretary can upload, approve, reject, delete)
    Route::get('/teaching-guides', [TeachingGuideController::class, 'index'])->name('teaching-guides.index');
    Route::post('/teaching-guides', [TeachingGuideController::class, 'store'])->middleware('throttle:6,60')->name('teaching-guides.store');
    Route::get('/teaching-guides/{id}/view', [TeachingGuideController::class, 'view'])->name('teaching-guides.view');
    Route::get('/teaching-guides/{id}/download', [TeachingGuideController::class, 'download'])->name('teaching-guides.download');
    Route::post('/teaching-guides/{id}/approve', [TeachingGuideController::class, 'approve'])->middleware('throttle:30,1')->name('teaching-guides.approve');
    Route::post('/teaching-guides/{id}/reject', [TeachingGuideController::class, 'reject'])->middleware('throttle:30,1')->name('teaching-guides.reject');
    Route::delete('/teaching-guides/{id}', [TeachingGuideController::class, 'destroy'])->name('teaching-guides.destroy');

    // Exam Questionnaires
    Route::get('/exam-questionnaires', [ExamQuestionnaireController::class, 'index'])->name('exam-questionnaires.index');
    Route::get('/exam-questionnaires/{id}/view', [ExamQuestionnaireController::class, 'view'])->name('exam-questionnaires.view');
    Route::get('/exam-questionnaires/{id}/download', [ExamQuestionnaireController::class, 'download'])->name('exam-questionnaires.download');
    Route::post('/exam-questionnaires/{id}/approve', [ExamQuestionnaireController::class, 'approve'])->middleware('throttle:30,1')->name('exam-questionnaires.approve');
    Route::post('/exam-questionnaires/{id}/reject', [ExamQuestionnaireController::class, 'reject'])->middleware('throttle:30,1')->name('exam-questionnaires.reject');

    // Archives (Dean manages + browses)
    Route::get('/archives', [SchoolYearController::class, 'index'])->name('archives.index');
    Route::post('/archives', [SchoolYearController::class, 'archive'])->middleware(['throttle:5,60', 'role:Dean,Secretary'])->name('archives.archive');
    Route::get('/archives/list', [SchoolYearController::class, 'list'])->name('archives.list');
    Route::post('/archives/{id}/restore', [SchoolYearController::class, 'restoreArchived'])->middleware(['throttle:3,60', 'role:Dean,Secretary'])->name('archives.restore');
    Route::post('/archives/{id}/permanent-delete', [SchoolYearController::class, 'destroyArchived'])->middleware(['throttle:3,60', 'role:Dean,Secretary'])->name('archives.destroy');
    Route::get('/archives/{id}', [SchoolYearController::class, 'show'])->name('archives.show');
});

// Program Coordinator Routes
Route::middleware(['auth', 'no.back', 'role:Program Coordinator'])->prefix('coordinator')->name('coordinator.')->group(function () {
    Route::post('/documents/{id}/favorite', [CoordinatorController::class, 'toggleFavorite'])->middleware('throttle:60,1')->name('toggle-favorite');
    Route::get('/dashboard', [CoordinatorController::class, 'dashboard'])->name('dashboard');
    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics');
    
    // Tasks (assigned to coordinator)
    Route::get('/tasks', [CoordinatorController::class, 'tasks'])->name('tasks');
    Route::patch('/tasks/{id}', [CoordinatorController::class, 'updateTask'])->middleware('throttle:30,1')->name('update-task');
    Route::post('/tasks/{id}/attachments', [TaskAttachmentController::class, 'store'])->middleware('throttle:30,1')->name('tasks.attachments.store');

    Route::get('/notifications', [CoordinatorController::class, 'notifications'])->name('notifications');
    Route::get('/notifications/unread-count', [CoordinatorController::class, 'unreadNotificationCount'])->name('notifications.unread-count');
    Route::get('/notifications/recent', [CoordinatorController::class, 'recentNotificationsJson'])->name('notifications.recent');
    Route::post('/notifications/{id}/read-json', [CoordinatorController::class, 'markNotificationReadJson'])->middleware('throttle:120,1')->name('notifications.read-json');
    Route::post('/notifications/{id}/read', [CoordinatorController::class, 'markNotificationRead'])->middleware('throttle:120,1')->name('mark-notification-read');
    Route::post('/notifications/mark-all-read', [CoordinatorController::class, 'markAllNotificationsRead'])->middleware('throttle:30,1')->name('notifications.mark-all-read');
    Route::post('/notifications/mark-all-read-json', [CoordinatorController::class, 'markAllNotificationsReadJson'])->middleware('throttle:30,1')->name('notifications.mark-all-read-json');
    Route::post('/notifications/clear-json', [CoordinatorController::class, 'clearNotificationsJson'])->middleware('throttle:5,1')->name('notifications.clear-json');
    
    // Course Catalog (scoped to coordinator department)
    Route::get('/courses/by-program', [CoordinatorController::class, 'coursesByProgram'])->name('courses.by-program');
    Route::get('/courses', [CoordinatorCourseController::class, 'index'])->name('courses');
    Route::post('/courses', [CoordinatorCourseController::class, 'store'])->name('courses.store');
    Route::patch('/courses/{course}', [CoordinatorCourseController::class, 'update'])->name('courses.update');
    Route::delete('/courses/{course}', [CoordinatorCourseController::class, 'destroy'])->name('courses.destroy');
    Route::post('/courses/{course}/restore', [CoordinatorCourseController::class, 'restore'])->name('courses.restore');

    // Faculty Management
    Route::get('/faculty', [CoordinatorController::class, 'faculty'])->name('faculty');
    Route::get('/faculty/create', [CoordinatorController::class, 'createFaculty'])->name('create-faculty');
    Route::post('/faculty', [CoordinatorController::class, 'storeFaculty'])->middleware('throttle:10,60')->name('store-faculty');
    Route::get('/faculty/{id}/profile', [CoordinatorController::class, 'viewEmployeeProfile'])->name('faculty-profile');
    Route::get('/faculty/{id}/edit', [CoordinatorController::class, 'editFaculty'])->name('edit-faculty');
    Route::patch('/faculty/{id}', [CoordinatorController::class, 'updateFaculty'])->middleware('throttle:10,60')->name('update-faculty');
    
    // Documents - Rate Limited: 6 uploads per hour
    Route::get('/documents', [CoordinatorController::class, 'documents'])->name('documents');
    Route::get('/documents/list-search', [DocumentListSearchController::class, 'suggest'])->name('documents.list-search');
    Route::get('/documents/recipient-search', [DocumentRecipientController::class, 'search'])->name('documents.recipient-search');
    Route::post('/documents', [CoordinatorController::class, 'uploadDocument'])->middleware('throttle:6,60')->name('upload-document');
    Route::get('/documents/{id}/view', [CoordinatorController::class, 'viewDocument'])->name('view-document');
    Route::get('/documents/{id}/download', [CoordinatorController::class, 'downloadDocument'])->name('download-document');
    Route::delete('/documents/{id}', [CoordinatorController::class, 'deleteDocument'])->name('delete-document');
    Route::patch('/documents/{id}/rename', [CoordinatorController::class, 'renameDocument'])->middleware('throttle:60,1')->name('rename-document');

    Route::get('/recycle-bin', [RecycleBinController::class, 'index'])->name('recycle-bin.index');
    Route::post('/recycle-bin/{id}/restore', [RecycleBinController::class, 'restore'])->middleware('throttle:60,1')->name('recycle-bin.restore');

    // Folder Management - Rate Limited: 3 folders per hour
    Route::post('/folders', [FolderController::class, 'store'])->middleware('throttle:30,60')->name('folders.store');
    Route::patch('/folders/{folder}', [FolderController::class, 'update'])->middleware('throttle:30,60')->name('folders.update');
    Route::delete('/folders/{folder}', [FolderController::class, 'destroy'])->middleware('throttle:30,60')->name('folders.destroy');
    Route::get('/folders/list', [FolderController::class, 'getUserFolders'])->name('folders.list');
    Route::post('/documents/{document}/move', [FolderController::class, 'moveDocument'])->name('documents.move');
    Route::post('/documents/{document}/copy', [FolderController::class, 'copyDocument'])->middleware('throttle:30,1')->name('documents.copy');
    Route::post('/documents/{document}/version', [\App\Http\Controllers\DocumentVersionController::class, 'store'])->middleware('throttle:10,60')->name('documents.version');

    // Teaching Guides (Coordinator can also upload)
    Route::get('/teaching-guides', [TeachingGuideController::class, 'index'])->name('teaching-guides.index');
    Route::post('/teaching-guides', [TeachingGuideController::class, 'store'])->middleware('throttle:6,60')->name('teaching-guides.store');
    Route::get('/teaching-guides/{id}/view', [TeachingGuideController::class, 'view'])->name('teaching-guides.view');
    Route::get('/teaching-guides/{id}/download', [TeachingGuideController::class, 'download'])->name('teaching-guides.download');
    Route::post('/teaching-guides/{id}/approve', [TeachingGuideController::class, 'approve'])->middleware('throttle:30,1')->name('teaching-guides.approve');
    Route::post('/teaching-guides/{id}/reject', [TeachingGuideController::class, 'reject'])->middleware('throttle:30,1')->name('teaching-guides.reject');
    Route::delete('/teaching-guides/{id}', [TeachingGuideController::class, 'destroy'])->name('teaching-guides.destroy');

    // Exam Questionnaires (coordinator reviews department faculty submissions)
    Route::get('/exam-questionnaires', [ExamQuestionnaireController::class, 'index'])->name('exam-questionnaires.index');
    Route::post('/exam-questionnaires', [ExamQuestionnaireController::class, 'store'])->middleware('throttle:6,60')->name('exam-questionnaires.store');
    Route::get('/exam-questionnaires/{id}/view', [ExamQuestionnaireController::class, 'view'])->name('exam-questionnaires.view');
    Route::get('/exam-questionnaires/{id}/download', [ExamQuestionnaireController::class, 'download'])->name('exam-questionnaires.download');
    Route::post('/exam-questionnaires/{id}/approve', [ExamQuestionnaireController::class, 'approve'])->middleware('throttle:30,1')->name('exam-questionnaires.approve');
    Route::post('/exam-questionnaires/{id}/reject', [ExamQuestionnaireController::class, 'reject'])->middleware('throttle:30,1')->name('exam-questionnaires.reject');
    Route::get('/activity-log', [CoordinatorController::class, 'activityLog'])->name('activity-log');

    // Archives (browse only)
    Route::get('/archives', [SchoolYearController::class, 'list'])->name('archives.list');
    Route::get('/archives/{id}', [SchoolYearController::class, 'show'])->name('archives.show');
});

// Faculty Employee Routes
Route::middleware(['auth', 'no.back', 'role:Faculty Employee'])->prefix('faculty')->name('faculty.')->group(function () {
    Route::get('/dashboard', [FacultyController::class, 'dashboard'])->name('dashboard');
    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics');
    Route::get('/tasks', [FacultyController::class, 'tasks'])->name('tasks');
    Route::patch('/tasks/{id}/status', [FacultyController::class, 'updateTaskStatus'])->middleware('throttle:30,1')->name('update-task-status');
    Route::post('/tasks/{id}/attachments', [TaskAttachmentController::class, 'store'])->middleware('throttle:30,1')->name('tasks.attachments.store');
    Route::get('/notifications', [FacultyController::class, 'notifications'])->name('notifications');
    Route::get('/notifications/unread-count', [FacultyController::class, 'unreadNotificationCount'])->name('notifications.unread-count');
    Route::get('/notifications/recent', [FacultyController::class, 'recentNotificationsJson'])->name('notifications.recent');
    Route::post('/notifications/{id}/read-json', [FacultyController::class, 'markNotificationReadJson'])->middleware('throttle:120,1')->name('notifications.read-json');
    
    // Folder Management - Rate Limited: 3 folders per hour
    Route::post('/folders', [FolderController::class, 'store'])->middleware('throttle:30,60')->name('folders.store');
    Route::patch('/folders/{folder}', [FolderController::class, 'update'])->middleware('throttle:30,60')->name('folders.update');
    Route::delete('/folders/{folder}', [FolderController::class, 'destroy'])->middleware('throttle:30,60')->name('folders.destroy');
    Route::get('/folders/list', [FolderController::class, 'getUserFolders'])->name('folders.list');
    Route::post('/documents/{document}/move', [FolderController::class, 'moveDocument'])->name('documents.move');
    Route::post('/documents/{document}/copy', [FolderController::class, 'copyDocument'])->middleware('throttle:30,1')->name('documents.copy');
    Route::post('/documents/{document}/version', [\App\Http\Controllers\DocumentVersionController::class, 'store'])->middleware('throttle:10,60')->name('documents.version');
    Route::post('/notifications/{id}/read', [FacultyController::class, 'markNotificationRead'])->middleware('throttle:120,1')->name('mark-notification-read');
    Route::post('/notifications/mark-all-read', [FacultyController::class, 'markAllNotificationsRead'])->middleware('throttle:30,1')->name('notifications.mark-all-read');
    Route::post('/notifications/mark-all-read-json', [FacultyController::class, 'markAllNotificationsReadJson'])->middleware('throttle:30,1')->name('notifications.mark-all-read-json');
    Route::post('/notifications/clear-json', [FacultyController::class, 'clearNotificationsJson'])->middleware('throttle:5,1')->name('notifications.clear-json');
    
    // Documents - Rate Limited: 6 uploads per hour
    Route::get('/documents', [FacultyController::class, 'documents'])->name('documents');
    Route::get('/documents/list-search', [DocumentListSearchController::class, 'suggest'])->name('documents.list-search');
    Route::post('/documents', [FacultyController::class, 'uploadDocument'])->middleware('throttle:6,60')->name('upload-document');
    Route::get('/documents/{id}/view', [FacultyController::class, 'viewDocument'])->name('view-document');
    Route::post('/documents/{id}/favorite', [FacultyController::class, 'toggleFavorite'])->middleware('throttle:60,1')->name('toggle-favorite');
    Route::get('/documents/{id}/download', [FacultyController::class, 'downloadDocument'])->name('download-document');
    Route::delete('/documents/{id}', [FacultyController::class, 'deleteDocument'])->name('delete-document');
    Route::patch('/documents/{id}/rename', [FacultyController::class, 'renameDocument'])->middleware('throttle:60,1')->name('rename-document');

    Route::get('/recycle-bin', [RecycleBinController::class, 'index'])->name('recycle-bin.index');
    Route::post('/recycle-bin/{id}/restore', [RecycleBinController::class, 'restore'])->middleware('throttle:60,1')->name('recycle-bin.restore');

    Route::get('/profile', [FacultyController::class, 'profile'])->name('profile');

    // Teaching Guides (Faculty: view approved + own pending/rejected)
    Route::get('/teaching-guides', [TeachingGuideController::class, 'index'])->name('teaching-guides.index');
    Route::get('/teaching-guides/{id}/view', [TeachingGuideController::class, 'view'])->name('teaching-guides.view');
    Route::get('/teaching-guides/{id}/download', [TeachingGuideController::class, 'download'])->name('teaching-guides.download');
    Route::patch('/teaching-guides/{id}/rename', [TeachingGuideController::class, 'rename'])->middleware('throttle:60,1')->name('teaching-guides.rename');
    Route::delete('/teaching-guides/{id}', [TeachingGuideController::class, 'destroy'])->name('teaching-guides.destroy');

    // Exam Questionnaires (Faculty: upload via Documents; view approved + track pending)
    Route::get('/exam-questionnaires', [ExamQuestionnaireController::class, 'index'])->name('exam-questionnaires.index');
    Route::get('/exam-questionnaires/{id}/view', [ExamQuestionnaireController::class, 'view'])->name('exam-questionnaires.view');
    Route::get('/exam-questionnaires/{id}/download', [ExamQuestionnaireController::class, 'download'])->name('exam-questionnaires.download');
    Route::patch('/exam-questionnaires/{id}/rename', [ExamQuestionnaireController::class, 'rename'])->middleware('throttle:60,1')->name('exam-questionnaires.rename');
    Route::delete('/exam-questionnaires/{id}', [ExamQuestionnaireController::class, 'destroy'])->name('exam-questionnaires.destroy');
    Route::get('/activity-log', [FacultyController::class, 'activityLog'])->name('activity-log');

    Route::get('/reports', [ReportController::class, 'index'])->name('reports');
    Route::post('/reports', [ReportController::class, 'store'])->middleware('throttle:10,60')->name('store-report');

    // Archives (browse only)
    Route::get('/archives', [SchoolYearController::class, 'list'])->name('archives.list');
    Route::get('/archives/{id}', [SchoolYearController::class, 'show'])->name('archives.show');
});
