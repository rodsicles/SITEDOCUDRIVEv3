@extends('layouts.dashboard')

@php
    $user = auth()->user();
    $isFaculty = $user->isFaculty();
    $isCoordinator = $user->isProgramCoordinator();
    $isDean = $user->isDean();
    $isSecretary = $user->isSecretary();
    $isDeanOrSecretary = $user->isDeanOrSecretary();
    $isAdmin = $user->isAdmin();
    $guideSubtitle = $isAdmin
        ? 'Step-by-step guide for the Admin Dashboard'
        : ($isDean
        ? 'Step-by-step guide for the Dean Dashboard'
        : ($isCoordinator
            ? 'Step-by-step guide for the Program Coordinator Dashboard'
            : ($isSecretary
                ? 'Step-by-step guide for the Secretary Dashboard'
                : 'Step-by-step guide for the Faculty Dashboard')));
@endphp

@section('title', 'User Guide')
@section('page-title', 'User Guide')
@section('page-subtitle', $guideSubtitle)

@php
    $guideWelcomeSub = $isAdmin
        ? 'As Admin you handle day-to-day oversight across all programs: faculty accounts, Course Catalog, Recycle Bin, Rejected Files, Activity Log, approvals, and password help. Backups, permanent deletes, school-year archiving, and Coordinator/Admin accounts stay with the Dean.'
        : ($isDean
        ? 'This guide covers Dean tools: approvals, faculty oversight, documents, announcements, analytics, and archives. Follow the steps below for your dashboard.'
        : ($isCoordinator
            ? 'This guide covers Program Coordinator features for your program. Follow the steps below to use your dashboard effectively.'
            : ($isSecretary
                ? 'This guide covers Secretary features that support Dean office operations. Follow the steps below to use your dashboard effectively.'
                : 'This guide covers Faculty features for tasks, uploads, and approvals. Follow the steps below to use your dashboard effectively.')));
@endphp

@section('page-header-extra')
    <div class="guide-header-welcome">
        <div class="guide-header-welcome__icon" aria-hidden="true"><i class="fas fa-book-open"></i></div>
        <div class="guide-header-welcome__body min-w-0">
            <div class="guide-header-welcome__title">Welcome, {{ $user->role->role_name }}</div>
            <div class="guide-header-welcome__sub">{{ $guideWelcomeSub }}</div>
        </div>
    </div>
@endsection

@section('sidebar')
    @if($isFaculty)
        @include('partials.faculty-sidebar')
    @elseif($isCoordinator)
        @include('partials.coordinator-sidebar')
    @elseif($isSecretary)
        @include('partials.secretary-sidebar')
    @else
        @include('partials.dean-sidebar')
    @endif
@endsection

@section('content')
<div class="guide-page">
<div class="guide-welcome-banner guide-welcome-banner--mobile-only">
    <div class="guide-welcome-icon"><i class="fas fa-book-open"></i></div>
    <div>
        <div class="guide-welcome-title">Welcome, {{ $user->role->role_name }}</div>
        <div class="guide-welcome-sub">{{ $guideWelcomeSub }}</div>
    </div>
</div>

{{-- Guide search with autosuggest --}}
<div class="guide-search-wrap">
    <div class="guide-search" id="guide-search">
        <label class="sr-only" for="guide-search-input">Search user guide topics</label>
        <div class="guide-search__field">
            <i class="fas fa-search" aria-hidden="true"></i>
            <input
                type="search"
                id="guide-search-input"
                class="guide-search__input"
                placeholder="Search guide topics…"
                autocomplete="off"
                aria-autocomplete="list"
                aria-controls="guide-search-suggestions"
                aria-expanded="false"
                role="combobox"
            >
        </div>
        <ul id="guide-search-suggestions" class="guide-search__suggestions" role="listbox" hidden></ul>
    </div>
</div>

{{-- Table of Contents --}}
<div class="guide-layout">
<nav class="guide-toc-panel" aria-label="User guide topics">
    <div class="card-header">
        <h3 class="card-title">Contents</h3>
    </div>
    <div class="guide-toc">
        @if($isFaculty)
            <a href="#guide-dashboard" class="guide-toc-item"><span class="guide-toc-num">1</span> Dashboard</a>
            <a href="#guide-tasks" class="guide-toc-item"><span class="guide-toc-num">2</span> My Tasks</a>
            <a href="#guide-activity-log" class="guide-toc-item"><span class="guide-toc-num">3</span> Activity Log</a>
            <a href="#guide-performance-analytics" class="guide-toc-item"><span class="guide-toc-num">4</span> Performance Analytics</a>
            <a href="#guide-announcements" class="guide-toc-item"><span class="guide-toc-num">5</span> Announcements</a>
            <a href="#guide-notifications" class="guide-toc-item"><span class="guide-toc-num">6</span> Notifications</a>
            <a href="#guide-documents" class="guide-toc-item"><span class="guide-toc-num">7</span> Documents</a>
            <a href="#guide-recycle-bin" class="guide-toc-item"><span class="guide-toc-num">8</span> Recycle Bin</a>
            <a href="#guide-teaching" class="guide-toc-item"><span class="guide-toc-num">9</span> Teaching Guides</a>
            <a href="#guide-exams" class="guide-toc-item"><span class="guide-toc-num">10</span> Exam Questionnaires</a>
            <a href="#guide-archives" class="guide-toc-item"><span class="guide-toc-num">11</span> Archives</a>
            <a href="#guide-profile" class="guide-toc-item"><span class="guide-toc-num">12</span> Profile</a>
        @elseif($isCoordinator)
            <a href="#guide-dashboard" class="guide-toc-item"><span class="guide-toc-num">1</span> Dashboard</a>
            <a href="#guide-tasks" class="guide-toc-item"><span class="guide-toc-num">2</span> My Tasks</a>
            <a href="#guide-activity-log" class="guide-toc-item"><span class="guide-toc-num">3</span> Activity Log</a>
            <a href="#guide-announcements" class="guide-toc-item"><span class="guide-toc-num">4</span> Announcements</a>
            <a href="#guide-notifications" class="guide-toc-item"><span class="guide-toc-num">5</span> Notifications</a>
            <a href="#guide-faculty" class="guide-toc-item"><span class="guide-toc-num">6</span> Faculty Members</a>
            <a href="#guide-courses" class="guide-toc-item"><span class="guide-toc-num">7</span> Course Catalog</a>
            <a href="#guide-analytics" class="guide-toc-item"><span class="guide-toc-num">8</span> Analytics</a>
            <a href="#guide-documents" class="guide-toc-item"><span class="guide-toc-num">9</span> Documents</a>
            <a href="#guide-recycle-bin" class="guide-toc-item"><span class="guide-toc-num">10</span> Recycle Bin</a>
            <a href="#guide-teaching" class="guide-toc-item"><span class="guide-toc-num">11</span> Teaching Guides</a>
            <a href="#guide-exams" class="guide-toc-item"><span class="guide-toc-num">12</span> Exam Questionnaires</a>
            <a href="#guide-archives" class="guide-toc-item"><span class="guide-toc-num">13</span> Archives</a>
        @elseif($isSecretary)
            <a href="#guide-dashboard" class="guide-toc-item"><span class="guide-toc-num">1</span> Dashboard</a>
            <a href="#guide-tasks" class="guide-toc-item"><span class="guide-toc-num">2</span> Tasks</a>
            <a href="#guide-activity-log" class="guide-toc-item"><span class="guide-toc-num">3</span> Activity Log</a>
            <a href="#guide-notifications" class="guide-toc-item"><span class="guide-toc-num">4</span> Notifications</a>
            <a href="#guide-announcements" class="guide-toc-item"><span class="guide-toc-num">5</span> Announcements</a>
            <a href="#guide-faculty" class="guide-toc-item"><span class="guide-toc-num">6</span> Faculty Members</a>
            <a href="#guide-courses" class="guide-toc-item"><span class="guide-toc-num">7</span> Course Catalog</a>
            <a href="#guide-analytics" class="guide-toc-item"><span class="guide-toc-num">8</span> Analytics</a>
            <a href="#guide-documents" class="guide-toc-item"><span class="guide-toc-num">9</span> Documents</a>
            <a href="#guide-teaching" class="guide-toc-item"><span class="guide-toc-num">10</span> Pending Teaching Guides</a>
            <a href="#guide-exams" class="guide-toc-item"><span class="guide-toc-num">11</span> Pending Exam Questionnaires</a>
            <a href="#guide-archives" class="guide-toc-item"><span class="guide-toc-num">12</span> Archives</a>
        @else
            <a href="#guide-dashboard" class="guide-toc-item"><span class="guide-toc-num">1</span> Dashboard</a>
            <a href="#guide-tasks" class="guide-toc-item"><span class="guide-toc-num">2</span> Tasks</a>
            <a href="#guide-activity-log" class="guide-toc-item"><span class="guide-toc-num">3</span> Activity Log</a>
            <a href="#guide-notifications" class="guide-toc-item"><span class="guide-toc-num">4</span> Notifications</a>
            <a href="#guide-announcements" class="guide-toc-item"><span class="guide-toc-num">5</span> Announcements</a>
            <a href="#guide-faculty" class="guide-toc-item"><span class="guide-toc-num">6</span> Faculty Members</a>
            <a href="#guide-courses" class="guide-toc-item"><span class="guide-toc-num">7</span> Course Catalog</a>
            <a href="#guide-analytics" class="guide-toc-item"><span class="guide-toc-num">8</span> Analytics</a>
            <a href="#guide-documents" class="guide-toc-item"><span class="guide-toc-num">9</span> Documents</a>
            <a href="#guide-recycle-bin" class="guide-toc-item"><span class="guide-toc-num">10</span> Recycle Bin</a>
            <a href="#guide-teaching" class="guide-toc-item"><span class="guide-toc-num">11</span> Pending Teaching Guides</a>
            <a href="#guide-exams" class="guide-toc-item"><span class="guide-toc-num">12</span> Pending Exam Questionnaires</a>
            <a href="#guide-archives" class="guide-toc-item"><span class="guide-toc-num">13</span> Archives</a>
        @endif
    </div>
</nav>
<article class="guide-article">

{{-- ===== DASHBOARD ===== --}}
<div id="guide-dashboard" class="content-card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-chart-line mr-2"></i> 1. Dashboard</h3>
    </div>
    <p class="guide-intro">The Dashboard is your home page. It summarizes your workload, recent activity, and quick actions.</p>
    <ol class="guide-steps">
        <li>Click <strong>Dashboard</strong> in the left sidebar.</li>
        <li>Review the <strong>statistics cards</strong> at the top for tasks, documents, and other counts relevant to your role.</li>
        @if($isFaculty)
        <li>Check <strong>My Tasks</strong> and recent updates assigned to you.</li>
        <li>Open shortcuts from the dashboard when you need to upload files or view pending submissions.</li>
        @elseif($isCoordinator)
        <li>Review program overview widgets and use quick links such as <strong>Post Announcement</strong> when needed.</li>
        @else
        <li>Use <strong>Show/Hide</strong> toggles on dashboard sections to expand or collapse details.</li>
        <li>From the Dean dashboard, use <strong>Create New Task</strong> to assign work across roles.</li>
        @endif
    </ol>
    <div class="guide-tip"><i class="fas fa-lightbulb mr-1"></i> <strong>Tip:</strong> The dashboard reloads each time you open it, so counts and lists stay current.</div>
</div>

{{-- ===== TASKS ===== --}}
<div id="guide-tasks" class="content-card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-tasks mr-2"></i> 2. {{ $isFaculty || $isCoordinator ? 'My Tasks' : 'Tasks' }}</h3>
    </div>
    <p class="guide-intro">Tasks help you track assignments, deadlines, and completion status.</p>
    <ol class="guide-steps">
        <li>Click <strong>{{ $isFaculty || $isCoordinator ? 'My Tasks' : 'Tasks' }}</strong> in the sidebar.</li>
        <li>Each task shows its <strong>title</strong>, <strong>status</strong>, <strong>priority</strong>, and <strong>due date</strong>.</li>
        @if($isFaculty || $isCoordinator)
        <li>Update the <strong>status</strong> of tasks assigned to you (for example, Pending → In Progress → Completed).</li>
        <li>Open a task to view details, comments, or attachments when provided.</li>
        @else
        <li>Click <strong>Create New Task</strong> to assign work. Set the title, description, assignee, priority, and due date.</li>
        <li>Edit or delete tasks you manage using the actions on the task list or detail page.</li>
        @endif
        <li>Use <strong>search and filters</strong> to find tasks quickly.</li>
    </ol>
    <div class="guide-tip"><i class="fas fa-lightbulb mr-1"></i> <strong>Tip:</strong> Update task status promptly so coordinators and the Dean see accurate progress.</div>
</div>

{{-- ===== ACTIVITY LOG ===== --}}
<div id="guide-activity-log" class="content-card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-history mr-2"></i> 3. Activity Log</h3>
    </div>
    <p class="guide-intro">The Activity Log records actions performed in the system, such as uploads, approvals, and task updates.</p>
    <ol class="guide-steps">
        <li>Click <strong>Activity Log</strong> in the sidebar.</li>
        <li>Browse entries to see <strong>who</strong> performed an action, <strong>what</strong> changed, and <strong>when</strong> it happened.</li>
        @if($isDeanOrSecretary)
        <li>Use this page to review program-wide activity and audit important changes.</li>
        @else
        <li>Use this page to review your own recent actions and track your work history.</li>
        @endif
        <li>Use available <strong>filters or search</strong> (if shown) to narrow the list.</li>
    </ol>
    <div class="guide-tip"><i class="fas fa-lightbulb mr-1"></i> <strong>Tip:</strong> Check the Activity Log when you need to confirm whether a file was uploaded or a submission was processed.</div>
</div>

{{-- ===== PERFORMANCE ANALYTICS (Faculty) ===== --}}
@if($isFaculty)
<div id="guide-performance-analytics" class="content-card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-chart-pie mr-2"></i> 4. Performance Analytics</h3>
    </div>
    <p class="guide-intro">Performance Analytics shows charts and summaries related to your own work and submissions.</p>
    <ol class="guide-steps">
        <li>Click <strong>Performance Analytics</strong> in the sidebar.</li>
        <li>Review charts for task progress, document activity, and related metrics.</li>
        <li>Hover over chart elements to see exact values.</li>
    </ol>
    <div class="guide-tip"><i class="fas fa-lightbulb mr-1"></i> <strong>Tip:</strong> Use this page to monitor your productivity over the term.</div>
</div>
@endif

{{-- ===== ANNOUNCEMENTS ===== --}}
<div id="guide-announcements" class="content-card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-bullhorn mr-2"></i>
            @if($isFaculty) 5. @elseif($isCoordinator) 4. @elseif($isSecretary) 5. @else 5. @endif
            Announcements
        </h3>
    </div>
    <p class="guide-intro">Announcements share important news, reminders, and updates with selected roles.</p>
    <ol class="guide-steps">
        <li>Click <strong>Announcements</strong> in the sidebar.</li>
        <li>Unread items are marked with a <strong>blue dot</strong> and are auto-marked read after you view them for about 1.5 seconds.</li>
        <li>Click <strong>Mark as read</strong> to clear an item immediately.</li>
        <li>Read <strong>pinned</strong> announcements first — they stay at the top with a pin icon and highlighted border.</li>
        @if($isDean || $isAdmin || $isCoordinator)
        <li>Use the <strong>composer</strong> at the top of the feed (or <strong>Post Announcement</strong>) to publish. Choose audience, write the message, set optional expiry, then post.</li>
        <li>Use the <strong>three-dot menu (⋮)</strong> on your own posts to edit or delete them.</li>
        @endif
    </ol>
    <div class="guide-tip"><i class="fas fa-lightbulb mr-1"></i> <strong>Tip:</strong>
        @if($isDean || $isCoordinator)
            Pin important Dean or program notices so they stay at the top of the feed.
        @else
            Faculty and Secretary users can read announcements but cannot post them unless your role includes that button.
        @endif
    </div>
</div>

{{-- ===== NOTIFICATIONS ===== --}}
@if($isFaculty || $isCoordinator || $isDeanOrSecretary)
<div id="guide-notifications" class="content-card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-bell mr-2"></i>
            @if($isFaculty) 6. @elseif($isCoordinator) 5. @elseif($isSecretary) 4. @else 4. @endif
            Notifications
        </h3>
    </div>
    <p class="guide-intro">Notifications alert you to task assignments, approvals, rejections, and other system events.</p>
    <ol class="guide-steps">
        <li>Click <strong>Notifications</strong> in the sidebar. A red badge shows your unread count.</li>
        <li>Open a notification to see details and follow any linked action.</li>
        <li>Mark individual notifications as read, or use <strong>Mark all as read</strong> to clear the list.</li>
        @if($isFaculty)
        <li>You receive notifications when teaching guides or exam questionnaires are <strong>approved</strong> or <strong>rejected</strong> by the Dean.</li>
        @elseif($isDeanOrSecretary)
        <li>You receive alerts when faculty submit teaching guides or exam questionnaires that need review, plus task and account events.</li>
        @endif
    </ol>
    <div class="guide-tip"><i class="fas fa-lightbulb mr-1"></i> <strong>Tip:</strong>
        @if($isDeanOrSecretary)
            Clear pending-review badges from the sidebar by finishing Approve/Reject actions on the pending pages.
        @else
            Check notifications after uploading submissions so you catch approval results quickly.
        @endif
    </div>
</div>
@endif

{{-- ===== FACULTY MEMBERS ===== --}}
@if(!$isFaculty)
<div id="guide-faculty" class="content-card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-users mr-2"></i> 6. Faculty Members</h3>
    </div>
    <p class="guide-intro">
        @if($isDean)
            Faculty Members is your directory of program instructors. Open a profile to review details and browse that faculty member's document tree.
        @else
            Faculty Members lists employee accounts in your program.
        @endif
    </p>
    <ol class="guide-steps">
        <li>Click <strong>Faculty Members</strong> in the sidebar.</li>
        <li>Browse the directory for employee number, name, email, program, and status.</li>
        <li>Click <strong>View Profile</strong> to open a faculty member's full record.</li>
        @if($isDean)
        <li>On the profile, use <strong>Browse by Folder</strong> (Expand all / Collapse all) to inspect Teaching Guides and Exam Questionnaires. Each file shows its <strong>file name</strong>, status, folder path, and date.</li>
        <li>Click <strong>Edit</strong> on a profile to update employee information.</li>
        <li>Use <strong>search or filters</strong> to find someone by name or program.</li>
        <li>Use <strong>Create Admin</strong> to add an Admin who handles day-to-day oversight when you are busy. Only you can create, deactivate, or reset Admin accounts.</li>
        <li>If you forget your own password, use <strong>Forgot Password</strong> on the login page; an Admin will give you a temporary password.</li>
        @elseif($isAdmin)
        <li>Use <strong>Create Faculty</strong> to add faculty accounts. Coordinator and Admin accounts are created by the Dean.</li>
        <li>On a faculty profile, use <strong>Edit</strong>, <strong>Deactivate</strong>, or <strong>Generate temporary password</strong> when a faculty member forgets their password. The password is shown once; give it to them in person.</li>
        <li>Open <strong>Password Resets</strong> to approve Forgot Password requests from faculty, coordinators, and the Dean. A reason is required before resetting the Dean's password.</li>
        @elseif($isCoordinator)
        <li>Coordinators can view profiles but manage accounts through the Dean when changes are required.</li>
        @else
        <li>Secretary users can view profiles to support office operations; account edits are handled by the Dean.</li>
        @endif
    </ol>
    <div class="guide-tip"><i class="fas fa-lightbulb mr-1"></i> <strong>Tip:</strong>
        @if($isDean)
            Use Expand all on a faculty profile when you need the exact uploaded file name, not only the folder path.
        @else
            Accurate faculty records help with analytics and document ownership.
        @endif
    </div>
</div>
@endif

{{-- ===== COURSE CATALOG (Coordinator / Dean / Secretary) ===== --}}
@if($isCoordinator || $isDeanOrSecretary)
<div id="guide-courses" class="content-card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-book mr-2"></i>
            @if($isCoordinator) 7. @else 7. @endif
            Course Catalog
        </h3>
    </div>
    @if($isCoordinator)
    <p class="guide-intro">The Course Catalog lists courses for your home program and any additional programs assigned by the Dean. Manage official course details within these programs.</p>
    <ol class="guide-steps">
        <li>Click <strong>Course Catalog</strong> in the sidebar under Management.</li>
        <li>Add a course with <strong>course code</strong> and <strong>title</strong>. Choose a handled program when more than one is available.</li>
        <li>Use tabs: <strong>All courses</strong>, your <strong>program name</strong>, or <strong>Inactive</strong> for removed courses.</li>
        <li>Use <strong>Edit course</strong> to update the code, title, year level, semester, and lecture/lab units. Open <strong>Change history</strong> to review previous edits. Remove deactivates a course; Restore makes it selectable again.</li>
    </ol>
    @else
    <p class="guide-intro">The Course Catalog defines verified courses for BLIS, BSEnSE, BSIT and BSCpE. Empty programs remain available without placeholder courses.</p>
    <ol class="guide-steps">
        <li>Click <strong>Course Catalog</strong> in the sidebar.</li>
        <li>Add a course with <strong>course code</strong>, <strong>title</strong>, and <strong>program</strong>, then click <strong>Add Course</strong>.</li>
        <li>Filter by program or search by code/title to find existing entries.</li>
        <li>Use <strong>Edit course</strong> for code/title, semester, year level, and lecture/lab units. Open <strong>Change history</strong> to see who changed each value. Deactivate or restore courses as offerings change.</li>
    </ol>
    @endif
    <div class="guide-tip"><i class="fas fa-lightbulb mr-1"></i> <strong>Tip:</strong> Keep the catalog updated before each term so upload pickers show the correct subjects.</div>
</div>
@endif

{{-- ===== ANALYTICS (Coordinator / Dean / Secretary) ===== --}}
@if($isDean || $isCoordinator || $isFaculty || $isSecretary || $isAdmin)
<div id="guide-corrections" class="content-card">
    <div class="card-header"><h3 class="card-title">Teaching loads and request corrections</h3></div>
    <ol class="guide-steps">
        @if($isFaculty)
        <li><strong>Need a file from the Secretary</strong> (e.g. a certificate or memo)? Open <strong>Document Requests → New request</strong>, choose the Secretary, describe the file, and send. You will be notified when it is uploaded; open it under <strong>Sent</strong>, then <strong>Approve</strong> or <strong>Request changes</strong>.</li>
        @elseif($isSecretary)
        <li>Requests sent to you appear under <strong>Document Requests → Received</strong>. Click <strong>Submit document</strong> to upload the requested file; only the requester and the Dean office can open it. If the requester asks for changes, upload a corrected file the same way.</li>
        @else
        <li>To get a file from the Dean office, choose a <strong>Secretary</strong> as the recipient of a General Document request. They upload it for you under your <strong>Sent</strong> requests.</li>
        @endif
    </ol>
    @if($isDean || $isCoordinator || $isSecretary || $isAdmin)
        <ol class="guide-steps">
            <li>In <strong>Teacher's Load</strong>, choose <strong>Revise</strong> on a published load and enter a reason. Edit/save the revision, preview it, then finalize. The employee keeps seeing the published schedule until finalization.</li>
            <li>Use <strong>History</strong> to download earlier published revisions. <strong>Void</strong> withdraws a load while retaining its history; <strong>Prepare replacement</strong> starts a new revision. Only unpublished drafts can be deleted.</li>
            <li>For requests you created, open <strong>Document Requests → Sent → Manage request</strong>. Edit instructions/deadlines, add recipients, or exempt an outstanding recipient with a reason. Exemptions keep submitted files and can be reinstated with their previous review status.</li>
            <li><strong>Cancel request</strong> stops further submissions/reviews. Reopen when work should resume, setting a future deadline or allowing late submissions if needed. Existing approved submissions stay approved.</li>
        </ol>
    @else
        <p class="guide-intro">Your published schedule stays available while a manager prepares a revision. Open <strong>History</strong> in Teacher's Load to see earlier published versions. A voided load no longer supplies your current timetable.</p>
        <p class="guide-intro">Cancelled document requests do not accept uploads. An exemption excuses an outstanding requirement and retains your submitted file. You will be notified when the requester changes or reopens a request.</p>
    @endif
</div>
@endif

{{-- ===== ANALYTICS (Coordinator / Dean / Secretary) ===== --}}
@if($isCoordinator || $isDeanOrSecretary)
<div id="guide-analytics" class="content-card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-chart-pie mr-2"></i>
            @if($isCoordinator) 8. @elseif($isSecretary) 8. @else 8. @endif
            Analytics
        </h3>
    </div>
    <p class="guide-intro">Analytics provides charts and summaries for program activity, tasks, and document trends.</p>
    <ol class="guide-steps">
        <li>Click <strong>Analytics</strong> in the sidebar.</li>
        <li>Review charts for task completion, uploads, and faculty activity.</li>
        <li>Hover over chart segments for detailed values.</li>
        <li>Apply any available <strong>date or program filters</strong> to focus the view.</li>
    </ol>
    <div class="guide-tip"><i class="fas fa-lightbulb mr-1"></i> <strong>Tip:</strong> Use Analytics at month-end to review overall program performance.</div>
</div>
@endif

{{-- ===== DOCUMENTS ===== --}}
<div id="guide-documents" class="content-card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-folder mr-2"></i>
            @if($isFaculty) 7.
            @elseif($isCoordinator) 9.
            @elseif($isSecretary) 9.
            @else 9.
            @endif
            Documents
        </h3>
    </div>
    <p class="guide-intro">Documents is the central file library. Files are grouped by category tabs and an academic folder hierarchy (school year, semester, subject, and type).</p>
    <ol class="guide-steps">
        <li>Click <strong>Documents</strong> in the sidebar.</li>
        <li>Switch between top-level <strong>category tabs</strong> (for example Academics, Accreditation, Custom Folders).</li>
        <li>Open folders using the cards or breadcrumb trail until you reach the correct upload location.</li>
        @if($isFaculty)
        <li>For <strong>Teaching Guides</strong> and <strong>Exam Questionnaires</strong>, navigate to the correct school year → semester → subject (and assessment type for exams), then upload. Submissions stay <strong>pending</strong> until the Dean approves them.</li>
        <li>For personal or program files, use <strong>Academics</strong> or <strong>Custom Folders</strong> as appropriate.</li>
        @elseif($isDean)
        <li>Review shared Teaching Guides and Exam Questionnaires folders, upload program files when needed, and assign recipients when prompted.</li>
        <li>Use <strong>Recent</strong>, <strong>Favorites</strong>, and filters (uploader/date) to find files quickly across the library.</li>
        <li>Use <strong>Custom Folders</strong> for general Dean office files.</li>
        @else
        <li>Coordinators and Dean office roles can upload shared documents in Teaching Guides and Exam Questionnaires folders and assign recipients when prompted.</li>
        <li>Use <strong>Custom Folders</strong> to create your own subfolders for general files.</li>
        @endif
        <li>Click <strong>Upload</strong> at a leaf folder, choose your file(s), complete required fields (title, subject, type), and submit.</li>
        <li>Click a file name to <strong>view</strong> or use <strong>Download</strong>. Delete moves the file to the Recycle Bin (not permanent until removed from there).</li>
        <li>Use the <strong>filter panel and search</strong> below the tabs to find files by name, category, or uploader.</li>
    </ol>
    <div class="guide-tip"><i class="fas fa-lightbulb mr-1"></i> <strong>Tip:</strong>
        @if($isDean)
            To inspect one instructor's uploads by file name, open <strong>Faculty Members → View Profile → Browse by Folder</strong>.
        @else
            Follow the folder path shown on Teaching Guides and Exam Questionnaires pages to jump back to the upload location.
        @endif
    </div>
</div>

{{-- ===== RECYCLE BIN ===== --}}
@if($isFaculty || $isCoordinator || $isDean || $isSecretary)
<div id="guide-recycle-bin" class="content-card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-recycle mr-2"></i>
            @if($isFaculty) 8. @elseif($isCoordinator) 10. @else 10. @endif
            Recycle Bin
        </h3>
    </div>
    <p class="guide-intro">Deleted files from Documents are moved here first. You can restore them or remove them permanently.</p>
    <ol class="guide-steps">
        <li>Click <strong>Recycle Bin</strong> in the sidebar.</li>
        <li>Review deleted files with their <strong>original folder</strong> and <strong>deleted date</strong>.</li>
        <li>Click <strong>Restore</strong> to return a file to its folder (or to <strong>Uncategorized</strong> if that folder no longer exists).</li>
        @if($isDean)
        <li>As Dean, you may <strong>delete permanently</strong> items in the Recycle Bin. This cannot be undone.</li>
        @else
        <li>Permanent deletion from the Recycle Bin is limited to the Dean role.</li>
        @endif
    </ol>
    <div class="guide-tip"><i class="fas fa-lightbulb mr-1"></i> <strong>Tip:</strong> Restore files promptly if they were removed by mistake.</div>
</div>
@endif

{{-- ===== TEACHING GUIDES ===== --}}
<div id="guide-teaching" class="content-card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-book-open mr-2"></i>
            @if($isFaculty) 9.
            @elseif($isCoordinator) 11.
            @elseif($isSecretary) 10.
            @else 11.
            @endif
            @if($isDeanOrSecretary) Pending Teaching Guides @else Teaching Guides @endif
        </h3>
    </div>
    @if($isDeanOrSecretary)
    <p class="guide-intro">Review faculty teaching guide submissions, approve valid files, or reject submissions that need revision.</p>
    <ol class="guide-steps">
        <li>Click <strong>Pending Teaching Guides</strong> in the sidebar. The badge shows how many items await review.</li>
        <li>Filter by <strong>Status</strong> (pending, approved, rejected) and <strong>Semester</strong>, or search by title, subject, or faculty name.</li>
        <li><strong>View</strong> or <strong>Download</strong> a submission before deciding.</li>
        <li>Click <strong>Approve</strong> to publish the guide to the shared library and notify the uploader.</li>
        <li>Click <strong>Reject</strong>, enter a required reason, and submit. The faculty member is notified to revise and re-upload.</li>
    </ol>
    @elseif($isCoordinator)
    <p class="guide-intro">Upload and manage teaching guides for your program. You can also browse approved guides shared in your program.</p>
    <ol class="guide-steps">
        <li>Open <strong>Teaching Guides</strong> in the sidebar.</li>
        <li>Use the upload form: enter a <strong>title</strong>, pick the <strong>school year, semester, and subject</strong>, select <strong>recipients</strong>, and attach PDF or Word files.</li>
        <li>Browse the list of approved guides. Use <strong>View</strong> and <strong>Download</strong> to access files.</li>
        <li>You may also upload through <strong>Documents → Teaching Guides</strong> using the same folder hierarchy.</li>
    </ol>
    @else
    <p class="guide-intro">View approved teaching guides and track your own submissions through the Dean approval workflow.</p>
    <ol class="guide-steps">
        <li>Upload files from <strong>Documents → Teaching Guides</strong> (school year → semester → subject → TG/LB folder).</li>
        <li>After upload, the file is <strong>Pending</strong> until the Dean approves it. A badge on the sidebar shows your pending count.</li>
        <li>Open <strong>Teaching Guides</strong> to see pending items at the top and approved guides in the main list.</li>
        <li>While pending, you can <strong>rename</strong> the submission title. If rejected, read the remarks, fix the file, and upload again (or delete rejected items).</li>
        <li>Approved guides appear in the list with <strong>View</strong> and <strong>Download</strong>. The folder path under each title links back to Documents.</li>
    </ol>
    @endif
    <div class="guide-tip"><i class="fas fa-lightbulb mr-1"></i> <strong>Tip:</strong> Only approved teaching guides are visible to the wider program library.</div>
</div>

{{-- ===== EXAM QUESTIONNAIRES ===== --}}
<div id="guide-exams" class="content-card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-file-alt mr-2"></i>
            @if($isFaculty) 10.
            @elseif($isCoordinator) 12.
            @elseif($isSecretary) 11.
            @else 12.
            @endif
            @if($isDeanOrSecretary) Pending Exam Questionnaires @else Exam Questionnaires @endif
        </h3>
    </div>
    @if($isDeanOrSecretary)
    <p class="guide-intro">Review exam questionnaire submissions (TOQ, MCQ, and related types) before they are published.</p>
    <ol class="guide-steps">
        <li>Click <strong>Pending Exam Questionnaires</strong> in the sidebar. The badge shows pending items.</li>
        <li>Use the compact toolbar: filter by <strong>Status</strong> and <strong>Exam Type</strong>, then search by title, subject, or faculty name beside those filters.</li>
        <li><strong>View</strong> or <strong>Download</strong> each file, then <strong>Approve</strong> or <strong>Reject</strong> with remarks.</li>
        <li>Approved questionnaires become available in the shared library; rejected ones return to the uploader for correction.</li>
    </ol>
    @elseif($isCoordinator)
    <p class="guide-intro">Upload and manage exam questionnaires for your program using the academic hierarchy.</p>
    <ol class="guide-steps">
        <li>Open <strong>Exam Questionnaires</strong> in the sidebar.</li>
        <li>Complete the upload form with title, school year, semester, subject, exam type, recipients, and files.</li>
        <li>Browse approved questionnaires with search and sort tools.</li>
        <li>Alternatively, upload through <strong>Documents → Exam Questionnaires</strong> down to the correct assessment folder.</li>
    </ol>
    @else
    <p class="guide-intro">View approved exam questionnaires and track your submissions through Dean approval.</p>
    <ol class="guide-steps">
        <li>Upload from <strong>Documents → Exam Questionnaires</strong> (school year → semester → subject → assessment type such as TOQ/MCQ).</li>
        <li>New uploads are <strong>Pending</strong> until approved. Check the sidebar badge and the pending section on this page.</li>
        <li>Rename pending submissions if needed. If rejected, review remarks and re-upload.</li>
        <li>Approved items appear in the list with <strong>View</strong>, <strong>Download</strong>, and a link to their folder in Documents.</li>
    </ol>
    @endif
    <div class="guide-tip"><i class="fas fa-lightbulb mr-1"></i> <strong>Tip:</strong> Label uploads with the correct course and term so reviewers can approve them faster.</div>
</div>

{{-- ===== ARCHIVES ===== --}}
@if($isFaculty || $isCoordinator || $isDean)
<div id="guide-archives" class="content-card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-box-archive mr-2"></i>
            @if($isFaculty) 11. @elseif($isCoordinator) 13. @elseif($isSecretary) 12. @else 13. @endif
            Archives
        </h3>
    </div>
    @if($isDean)
    <p class="guide-intro">Archives let you close out a school year and browse past terms. Only the Dean can archive the active school year. Archives keep all documents and only <strong>approved</strong> teaching guides and exam questionnaires; pending and rejected submissions stay in the new active year.</p>
    <ol class="guide-steps">
        <li>Click <strong>Archives</strong> in the sidebar to open School Year Archives.</li>
        <li>Review the <strong>current school year</strong> counts (approved TG/EQ totals) before archiving.</li>
        <li>When a term ends, click <strong>Archive This School Year</strong>, confirm the archive name, and submit. Pending and rejected submissions are not archived.</li>
        <li>Browse archived years and click <strong>Browse</strong>. Use <strong>Search this school year</strong> to find files within that archive only.</li>
        <li>To add late files, open an archived year, choose <strong>Add files</strong>, select an archive destination, then choose files. Create a folder there when needed. Rename an eligible file or folder from its archive row.</li>
    </ol>
    @else
    <p class="guide-intro">Archives store completed school years with official documents and approved teaching guides and exam questionnaires only.</p>
    <ol class="guide-steps">
        <li>Click <strong>Archives</strong> in the sidebar.</li>
        <li>Select an archived school year and click <strong>Browse</strong>.</li>
        <li>View or download files from that year. To add late files, use <strong>Add files</strong> on the archive page, select a destination, and then choose files. You can create an archive folder and rename eligible files or folders there.</li>
        <li>Use <strong>Search this school year</strong> on the browse page to filter within that archive only.</li>
    </ol>
    @endif
    <div class="guide-tip"><i class="fas fa-lightbulb mr-1"></i> <strong>Tip:</strong> Academic files added later still follow their usual approval process. The approved archive list updates after review.</div>
</div>
@endif

{{-- ===== PROFILE (Faculty only) ===== --}}
@if($isFaculty)
<div id="guide-profile" class="content-card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-user mr-2"></i> 12. Profile</h3>
    </div>
    <p class="guide-intro">Your Profile contains personal and professional information visible to administrators.</p>
    <ol class="guide-steps">
        <li>Open the account menu using your <strong>name or avatar at the bottom of the sidebar</strong>, then choose <strong>Edit Profile</strong>.</li>
        <li>Review your account information and <strong>Current teaching assignments</strong> for this semester.</li>
        <li>Subjects assigned before a timetable was prepared still appear by name. Sections, days, times, and rooms appear after the Dean or Program Coordinator finalizes your <strong>Teacher's Load</strong>.</li>
        <li>Use the <strong>Teacher's Load</strong> link to view your assignments and download finalized loads. Ask the Dean or Program Coordinator to update subjects or prepare upcoming schedules.</li>
        <li>Update your email under <strong>Personal details</strong>, or use <strong>Change photo</strong> to upload a profile picture.</li>
        <li>To change your <strong>password</strong>, open the Password tab, enter your current and new passwords, confirm the new password, and save.</li>
    </ol>
    <div class="guide-tip"><i class="fas fa-lightbulb mr-1"></i> <strong>Tip:</strong> Keep your contact information current so the Dean and coordinators can reach you.</div>
</div>
@endif

</article>
</div>

{{-- Back to Top --}}
<div class="guide-back-top">
    <a href="#" onclick="event.preventDefault(); window.scrollTo({top:0, behavior:'smooth'});" class="btn btn-secondary border-0"><i class="fas fa-arrow-up mr-1"></i> Back to Top</a>
</div>
</div>

<script>
(function () {
    const input = document.getElementById('guide-search-input');
    const list = document.getElementById('guide-search-suggestions');
    if (!input || !list) return;

    const topics = Array.from(document.querySelectorAll('.guide-toc-item')).map(function (link) {
        const numEl = link.querySelector('.guide-toc-num');
        const label = link.textContent.replace(/^\s*\d+\s*/, '').trim();
        return {
            href: link.getAttribute('href') || '',
            label: label,
            num: numEl ? numEl.textContent.trim() : '',
            search: (label + ' ' + (numEl ? numEl.textContent.trim() : '')).toLowerCase(),
        };
    }).filter(function (t) { return t.href && t.label; });

    let activeIndex = -1;
    let visible = [];

    function hideSuggestions() {
        list.hidden = true;
        list.innerHTML = '';
        activeIndex = -1;
        input.setAttribute('aria-expanded', 'false');
    }

    function goToTopic(topic) {
        hideSuggestions();
        input.value = topic.label;
        const target = document.querySelector(topic.href);
        if (!target) return;
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        target.classList.add('guide-section-highlight');
        window.setTimeout(function () {
            target.classList.remove('guide-section-highlight');
        }, 1600);
    }

    function renderSuggestions(query) {
        const q = query.trim().toLowerCase();
        if (!q) {
            hideSuggestions();
            return;
        }

        visible = topics.filter(function (t) {
            return t.search.indexOf(q) !== -1;
        }).slice(0, 8);

        list.innerHTML = '';
        if (!visible.length) {
            const empty = document.createElement('li');
            empty.className = 'guide-search__empty';
            empty.textContent = 'No matching topics';
            list.appendChild(empty);
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            activeIndex = -1;
            return;
        }

        visible.forEach(function (topic, index) {
            const li = document.createElement('li');
            li.setAttribute('role', 'option');
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'guide-search__suggestion';
            btn.dataset.index = String(index);
            if (topic.num) {
                const num = document.createElement('span');
                num.className = 'guide-search__suggestion-num';
                num.textContent = topic.num;
                btn.appendChild(num);
            }
            const text = document.createElement('span');
            text.textContent = topic.label;
            btn.appendChild(text);
            btn.addEventListener('mousedown', function (e) {
                e.preventDefault();
                goToTopic(topic);
            });
            li.appendChild(btn);
            list.appendChild(li);
        });

        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        activeIndex = -1;
    }

    function setActive(index) {
        const buttons = list.querySelectorAll('.guide-search__suggestion');
        buttons.forEach(function (btn) { btn.classList.remove('is-active'); });
        if (index < 0 || index >= buttons.length) {
            activeIndex = -1;
            return;
        }
        activeIndex = index;
        buttons[index].classList.add('is-active');
        buttons[index].scrollIntoView({ block: 'nearest' });
    }

    input.addEventListener('input', function () {
        renderSuggestions(input.value);
    });

    input.addEventListener('keydown', function (e) {
        if (list.hidden) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive(activeIndex + 1 >= visible.length ? 0 : activeIndex + 1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive(activeIndex <= 0 ? visible.length - 1 : activeIndex - 1);
        } else if (e.key === 'Enter') {
            if (activeIndex >= 0 && visible[activeIndex]) {
                e.preventDefault();
                goToTopic(visible[activeIndex]);
            } else if (visible.length === 1) {
                e.preventDefault();
                goToTopic(visible[0]);
            }
        } else if (e.key === 'Escape') {
            hideSuggestions();
        }
    });

    input.addEventListener('blur', function () {
        window.setTimeout(hideSuggestions, 120);
    });
})();
</script>
@endsection
