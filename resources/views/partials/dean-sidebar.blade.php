{{-- Consistent Dean Sidebar - Appears on ALL Dean and Admin pages --}}
@php
    try {
        $pendingResetCount = app(\App\Services\PasswordResetRequestService::class)
            ->handleableQuery(auth()->user())
            ->pending()
            ->where('expires_at', '>', now())
            ->count();
    } catch (\Throwable $e) {
        $pendingResetCount = 0;
    }
@endphp

{{-- Resources & Development --}}
<div class="sidebar-group">
    <button type="button" class="sidebar-section-label" aria-expanded="false" data-target="group-resources">
        <span>Resources &amp; Development</span>
        <i class="fas fa-chevron-down sidebar-chevron" aria-hidden="true"></i>
    </button>
    <div class="sidebar-group-items" id="group-resources">
        <a href="{{ route('dean.documents') }}" class="menu-item {{ request()->routeIs('dean.documents') ? 'active' : '' }}">
            <i class="fas fa-folder"></i> Documents
        </a>
        <a href="{{ route('document-requests.index') }}" class="menu-item {{ request()->routeIs('document-requests.*') ? 'active' : '' }}">
            <i class="fas fa-file-circle-question"></i> Document Requests
        </a>
        <a href="{{ route('teacher-loads.index') }}" class="menu-item {{ request()->routeIs('teacher-loads.*') ? 'active' : '' }}">
            <i class="fas fa-chalkboard-teacher"></i> Teacher’s Load
        </a>
        <a href="{{ route('dean.teaching-guides.index') }}" class="menu-item {{ request()->routeIs('dean.teaching-guides.*') ? 'active' : '' }}">
            <i class="fas fa-book-open"></i> Pending Teaching Guides
            @if(($pendingTeachingGuidesCount ?? 0) > 0)
            <span class="badge badge-danger ml-auto">{{ $pendingTeachingGuidesCount }}</span>
            @endif
        </a>
        <a href="{{ route('dean.exam-questionnaires.index') }}" class="menu-item {{ request()->routeIs('dean.exam-questionnaires.*') ? 'active' : '' }}">
            <i class="fas fa-file-alt"></i> Pending Exam Questionnaires
            @if(($pendingExamQuestionnairesCount ?? 0) > 0)
            <span class="badge badge-danger ml-auto">{{ $pendingExamQuestionnairesCount }}</span>
            @endif
        </a>
        <a href="{{ route('dean.archives.index') }}" class="menu-item {{ request()->routeIs('dean.archives.*') ? 'active' : '' }}">
            <i class="fas fa-box-archive"></i> Archives
        </a>
        <a href="{{ route('dean.recycle-bin.index') }}" class="menu-item {{ request()->routeIs('dean.recycle-bin.*') ? 'active' : '' }}">
            <i class="fas fa-recycle"></i> Recycle Bin
        </a>
        @if(auth()->user()->hasOversightAccess())
        <a href="{{ route('dean.rejected-files.index') }}" class="menu-item {{ request()->routeIs('dean.rejected-files.*') ? 'active' : '' }}">
            <i class="fas fa-circle-xmark"></i> Rejected Files
        </a>
        @endif
    </div>
</div>

{{-- Communication --}}
<div class="sidebar-group">
    <button type="button" class="sidebar-section-label" aria-expanded="false" data-target="group-communication">
        <span>Communication</span>
        <i class="fas fa-chevron-down sidebar-chevron" aria-hidden="true"></i>
    </button>
    <div class="sidebar-group-items" id="group-communication">
        <a href="{{ route('announcements.index') }}" class="menu-item {{ request()->routeIs('announcements.index', 'announcements.show') ? 'active' : '' }}">
            <i class="fas fa-bullhorn"></i> Announcements
        </a>
    </div>
</div>

{{-- Core Functions --}}
<div class="sidebar-group">
    <button type="button" class="sidebar-section-label" aria-expanded="false" data-target="group-core">
        <span>Core Functions</span>
        <i class="fas fa-chevron-down sidebar-chevron" aria-hidden="true"></i>
    </button>
    <div class="sidebar-group-items" id="group-core">
        <a href="{{ route('dean.dashboard') }}" class="menu-item {{ request()->routeIs('dean.dashboard') ? 'active' : '' }}">
            <i class="fas fa-chart-line"></i> Dashboard
        </a>
        <a href="{{ route('dean.tasks') }}" class="menu-item {{ request()->routeIs('dean.tasks', 'dean.create-task') ? 'active' : '' }}">
            <i class="fas fa-tasks"></i> Tasks
        </a>
        <a href="{{ route('dean.audit-trail') }}" class="menu-item {{ request()->routeIs('dean.audit-trail', 'dean.activity-log') ? 'active' : '' }}">
            <i class="fas fa-history"></i> Activity Log
        </a>
    </div>
</div>

{{-- Management --}}
<div class="sidebar-group">
    <button type="button" class="sidebar-section-label" aria-expanded="false" data-target="group-management">
        <span>Management</span>
        <i class="fas fa-chevron-down sidebar-chevron" aria-hidden="true"></i>
    </button>
    <div class="sidebar-group-items" id="group-management">
        <a href="{{ route('dean.employees') }}" class="menu-item {{ request()->routeIs('dean.employees', 'dean.employee-profile', 'dean.edit-employee') ? 'active' : '' }}">
            <i class="fas fa-users"></i> Faculty Members
        </a>
        <a href="{{ route('password-reset-requests.index') }}" class="menu-item {{ request()->routeIs('password-reset-requests.*') ? 'active' : '' }}">
            <i class="fas fa-key"></i> Password Resets
            @if($pendingResetCount > 0)
            <span class="badge badge-danger ml-auto">{{ $pendingResetCount }}</span>
            @endif
        </a>
        <a href="{{ route('dean.courses') }}" class="menu-item {{ request()->routeIs('dean.courses*') ? 'active' : '' }}">
            <i class="fas fa-book"></i> Course Catalog
        </a>
        <a href="{{ route('dean.analytics') }}" class="menu-item {{ request()->routeIs('dean.analytics') ? 'active' : '' }}">
            <i class="fas fa-chart-pie"></i> Analytics
        </a>
    </div>
</div>
