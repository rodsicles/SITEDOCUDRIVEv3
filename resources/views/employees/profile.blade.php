@extends('layouts.dashboard')

@section('title', 'Employee Profile')

@section('page-title', 'Employee Profile')
@section('page-subtitle', 'Detailed employee information and history')

@section('sidebar')
    @if(auth()->user()->isDeanOrSecretary())
        @include('partials.dean-sidebar')
    @else
        @include('partials.coordinator-sidebar')
    @endif
@endsection

@section('content')
@php
    $assignedCourses = optional($employee->user)->assignedCourses ?? collect();
    $isInactive = $employee->user->status === 'Inactive';
    $viewer = auth()->user();
    $target = $employee->user;
    $viewerIsDeanOffice = $viewer->isDeanOrSecretary();
    // Mirrors DeanController::authorizeAccountTarget(): never the Dean, Admin accounts only by the Dean, Admin manages Faculty only.
    $canManageAccount = $viewerIsDeanOffice && !$target->isDean()
        && (!$target->isAdmin() || $viewer->isDean())
        && (!$viewer->isAdmin() || $target->isFaculty());
    $canEditIdentity = $canManageAccount && !$target->isAdmin();
    $canPermanentDelete = ($viewer->isDean() || $viewer->isSecretary()) && config('employee.allow_hard_delete')
        && ($target->isFaculty() || $target->isProgramCoordinator());
    $isSelf = (int) $employee->user_id === (int) $viewer->id;
@endphp

<div class="employee-record">
    @if(session('temp_password'))
    <div class="ui-notice ui-notice--warning" role="status">
        <div>
            <strong><i class="fas fa-key"></i> Temporary password (shown once)</strong>
            <p class="text-sm mt-1">Give this to <strong>{{ session('temp_password_user') }}</strong> in person. It will not be shown again, and they must change it at next login.</p>
            <code class="temporary-password-value">{{ session('temp_password') }}</code>
        </div>
    </div>
    @endif

    <div class="mb-0">
        @if($viewerIsDeanOffice)
            <a href="{{ route('dean.employees', $isInactive ? ['tab' => 'deactivated'] : []) }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> {{ $isInactive ? 'Back to Deactivated Accounts' : 'Back to Faculty Members' }}
            </a>
        @else
            <a href="{{ route('coordinator.faculty') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Faculty
            </a>
        @endif
    </div>

    <header class="employee-record__head">
        <div>
            <h2 class="employee-record__name">{{ $employee->full_name }}</h2>
            <div class="employee-record__meta">
                <span>{{ $employee->employee_no ?? 'No employee number' }}</span>
                <span>{{ \App\Models\Program::OPTIONS[$employee->program] ?? ($employee->program ?? 'N/A') }}</span>
                <span>{{ $employee->user->role->role_name }}</span>
                <span>{{ $employee->position }}</span>
            </div>
        </div>
        <div class="employee-record__actions">
            <span class="badge {{ $employee->user->status === 'Active' ? 'badge-success' : 'badge-danger' }}">
                {{ $employee->user->status }}
            </span>
            @if($canManageAccount)
                @if($canEditIdentity)
                <a href="{{ route('dean.edit-employee', $employee->employee_id) }}" class="btn btn-primary text-sm">
                    <i class="fas fa-edit"></i> Edit
                </a>
                @endif
                @if($employee->user->status === 'Inactive')
                <form action="{{ route('dean.reactivate-employee', $employee->employee_id) }}" method="POST" class="m-0">
                    @csrf
                    <button type="submit" class="btn btn-primary text-sm">
                        <i class="fas fa-user-check"></i> Reactivate Account
                    </button>
                </form>
                @endif
            @elseif($viewer->isProgramCoordinator())
                <a href="{{ route('coordinator.edit-faculty', $employee->employee_id) }}" class="btn btn-primary text-sm">
                    <i class="fas fa-edit"></i> Edit
                </a>
            @endif
        </div>
    </header>

    @if($viewerIsDeanOffice && $isInactive)
    <div class="ui-notice ui-notice--warning">
        <div>
            This account is inactive. The user cannot sign in or appear in task assignment and user search.
            Uploaded documents and folders remain in the system for records.
        </div>
    </div>
    @endif

    <div class="employee-record__stats">
        <div class="employee-record__stat">
            <div class="employee-record__stat-value">{{ $taskStats['total'] }}</div>
            <div class="employee-record__stat-label">Tasks</div>
        </div>
        <div class="employee-record__stat">
            <div class="employee-record__stat-value">{{ $taskStats['completed'] }}</div>
            <div class="employee-record__stat-label">Completed</div>
        </div>
        <div class="employee-record__stat">
            <div class="employee-record__stat-value">{{ $documentStats['total'] }}</div>
            <div class="employee-record__stat-label">Documents</div>
        </div>
        <div class="employee-record__stat">
            @if(auth()->user()->isDean() && $performanceReports->count() > 0)
                <div class="employee-record__stat-value">{{ number_format($performanceReports->avg('rating'), 1) }}</div>
                <div class="employee-record__stat-label">Average rating</div>
            @else
                <div class="employee-record__stat-value">{{ $reportStats['total'] ?? 0 }}</div>
                <div class="employee-record__stat-label">Reports</div>
            @endif
        </div>
    </div>

    <section class="employee-record__panel">
        <div class="employee-record__panel-head">
            <h3>Identity and account</h3>
            @if($canEditIdentity)
                <a href="{{ route('dean.edit-employee', $employee->employee_id) }}" class="btn btn-primary text-sm">
                    <i class="fas fa-edit"></i> Edit identity
                </a>
            @elseif($viewer->isProgramCoordinator())
                <a href="{{ route('coordinator.edit-faculty', $employee->employee_id) }}" class="btn btn-primary text-sm">
                    <i class="fas fa-edit"></i> Edit identity
                </a>
            @endif
        </div>
        <dl class="employee-record__fields">
            <div class="employee-record__field">
                <dt>Employee number</dt>
                <dd>{{ $employee->employee_no ?? 'N/A' }}</dd>
            </div>
            <div class="employee-record__field">
                <dt>Full name</dt>
                <dd>{{ $employee->full_name }}</dd>
            </div>
            <div class="employee-record__field">
                <dt>Program</dt>
                <dd>{{ \App\Models\Program::OPTIONS[$employee->program] ?? ($employee->program ?? 'N/A') }}</dd>
            </div>
            @if($employee->user->isProgramCoordinator() && ($extraPrograms = $employee->extraProgramCodes()))
                <div class="employee-record__field">
                    <dt>Also handles</dt>
                    <dd>{{ implode(', ', $extraPrograms) }}</dd>
                </div>
            @endif
            <div class="employee-record__field">
                <dt>Position</dt>
                <dd>{{ $employee->position }}</dd>
            </div>
            <div class="employee-record__field">
                <dt>Role</dt>
                <dd>{{ $employee->user->role->role_name }}</dd>
            </div>
            <div class="employee-record__field">
                <dt>Hire date</dt>
                <dd>{{ $employee->hire_date ? $employee->hire_date->format('M d, Y') : 'N/A' }}</dd>
            </div>
            <div class="employee-record__field">
                <dt>Years of service</dt>
                <dd>
                    @if($employee->getYearsOfService() !== null)
                        {{ $employee->getYearsOfService() }} year(s)
                        @if($employee->getServiceMilestone())
                            <span class="milestone-badge milestone-{{ $employee->getServiceMilestone() }}">
                                <i class="fas fa-award"></i> {{ $employee->getServiceMilestone() }} Years
                            </span>
                        @endif
                    @else
                        N/A
                    @endif
                </dd>
            </div>
            <div class="employee-record__field">
                <dt>Username</dt>
                <dd>{{ $employee->user->username }}</dd>
            </div>
            <div class="employee-record__field">
                <dt>Email</dt>
                <dd>{{ $employee->user->email ?: '—' }}</dd>
            </div>
            <div class="employee-record__field">
                <dt>Account created</dt>
                <dd>{{ $employee->user->created_at->format('M d, Y h:i A') }}</dd>
            </div>
        </dl>
    </section>

    <section class="employee-record__panel">
        <div class="employee-record__panel-head">
            <h3>Assigned courses</h3>
            <span class="badge badge-info">{{ $assignedCourses->count() }}</span>
        </div>
        @if($assignedCourses->count() > 0)
            <div class="employee-record__courses">
                @foreach($assignedCourses as $course)
                    <span class="employee-record__course">{{ $course->code }} — {{ $course->title }}</span>
                @endforeach
            </div>
        @else
            @include('partials.ui.empty-state', ['title' => 'No assigned courses', 'text' => 'This account has no subjects on file.'])
        @endif
    </section>

    @if(auth()->user()->isDean())
    <section class="employee-record__panel">
        <div class="employee-record__panel-head">
            <h3>Performance history</h3>
            <span class="badge badge-info">{{ $performanceReports->count() }} Reviews</span>
        </div>
        @if($performanceReports->count() > 0)
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Evaluator</th>
                        <th>Rating</th>
                        <th>Remarks</th>
                        <th>Review Date</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($performanceReports as $report)
                    <tr>
                        <td><strong>{{ $report->evaluator->employee->full_name ?? $report->evaluator->username }}</strong></td>
                        <td>
                            <span class="badge {{ $report->rating >= 4 ? 'badge-success' : ($report->rating >= 3 ? 'badge-warning' : 'badge-danger') }}">
                                {{ $report->rating }}/5
                            </span>
                        </td>
                        <td>{{ $report->remarks ?? 'No remarks provided' }}</td>
                        <td>{{ $report->report_date->format('M d, Y') }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            @include('partials.ui.empty-state', ['title' => 'No performance reviews', 'text' => 'Reviews will appear here when they are recorded.'])
        @endif
    </section>
    @endif

    <section class="employee-record__panel employee-record__panel--compact">
        <div class="employee-record__panel-head">
            <h3>Submitted documents</h3>
            <span class="badge badge-info">{{ $documentStats['total'] }} Documents</span>
        </div>
        @if($documentStats['total'] > 0)
            @include('partials.faculty-profile-documents', [
                'documents' => $documents,
                'documentStats' => $documentStats,
                'documentTree' => $documentTree ?? [],
            ])
        @else
            @include('partials.ui.empty-state', ['title' => 'No documents submitted', 'text' => 'Uploads from this employee will appear here.'])
        @endif
    </section>

    <section class="employee-record__panel employee-record__panel--compact">
        <div class="employee-record__panel-head">
            <h3>Submitted reports</h3>
            <span class="badge badge-info">{{ $reportStats['total'] ?? 0 }}</span>
        </div>
        @if(isset($reports) && $reports->count() > 0)
            <div class="employee-record__courses mb-3">
                @foreach($reportStats['byCategory'] as $category => $count)
                    <span class="employee-record__course">{{ $category ?? 'Other' }}: {{ $count }}</span>
                @endforeach
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Report Title</th>
                        <th>Category</th>
                        <th>File Name</th>
                        <th>Submission Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($reports as $report)
                    <tr>
                        <td><strong>{{ $report->report_title }}</strong></td>
                        <td>
                            <span class="badge badge-info">{{ $report->report_category ?? 'N/A' }}</span>
                        </td>
                        <td class="font-mono text-xs">{{ $report->displayFilename() }}</td>
                        <td>{{ $report->created_at->format('M d, Y h:i A') }}</td>
                        <td>
                            <a href="{{ route('reports.view', $report->report_id) }}" target="_blank" class="btn btn-primary py-1 px-2.5 text-xs mr-1">
                                <i class="fas fa-eye"></i> View
                            </a>
                            <a href="{{ route('reports.download', $report->report_id) }}" class="btn btn-secondary py-1 px-2.5 text-xs">
                                <i class="fas fa-download"></i> Download
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="employee-record__empty">No reports submitted.</p>
        @endif
    </section>

    <section class="employee-record__panel">
        <div class="employee-record__panel-head">
            <h3>Task history</h3>
            <span class="badge badge-info">{{ $tasks->count() }} Tasks</span>
        </div>
        @if($tasks->count() > 0)
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Task Title</th>
                        <th>Assigned By</th>
                        <th>Due Date</th>
                        <th>Status</th>
                        <th>Created Date</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($tasks as $task)
                    <tr>
                        <td><strong>{{ $task->task_title }}</strong></td>
                        <td>{{ $task->assignedBy->employee->full_name ?? $task->assignedBy->username }}</td>
                        <td>
                            {{ $task->due_date ? $task->due_date->format('M d, Y') : 'N/A' }}
                            @if($task->due_date && $task->due_date->isPast() && $task->status !== 'Completed')
                                <span class="badge badge-danger">Overdue</span>
                            @endif
                        </td>
                        <td>
                            @if($task->status === 'Completed')
                                <span class="badge badge-success">Completed</span>
                            @elseif($task->status === 'In Progress')
                                <span class="badge badge-warning">In Progress</span>
                            @else
                                <span class="badge badge-danger">Pending</span>
                            @endif
                        </td>
                        <td>{{ $task->created_at->format('M d, Y') }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            @include('partials.ui.empty-state', ['title' => 'No tasks assigned', 'text' => 'Assigned work will appear in this history.'])
        @endif
    </section>

    @if($canManageAccount && !$isSelf && ($employee->user->status === 'Active' || $canPermanentDelete))
    <section class="employee-record__danger" aria-labelledby="account-actions-heading">
        <h3 id="account-actions-heading">Account actions</h3>
        @if($employee->user->status === 'Active')
            <p class="text-sm text-gray-700 dark:text-gray-300 mb-3">
                Forgot their password? Generate a one-time temporary password and give it to them in person. They must change it at next login.
            </p>
            <form action="{{ route('dean.temporary-password', $employee->employee_id) }}" method="POST" class="m-0 mb-4" id="temporaryPasswordForm">
                @csrf
                <button type="button" class="btn btn-secondary text-sm" onclick="confirmTemporaryPassword()">
                    <i class="fas fa-key"></i> Generate temporary password
                </button>
            </form>

            <p class="text-sm text-gray-700 dark:text-gray-300 mb-3">
                Deactivate to block sign-in while keeping documents and folders for records.
            </p>
            <form action="{{ route('dean.deactivate-employee', $employee->employee_id) }}" method="POST" class="m-0 mb-4" id="deactivateAccountForm">
                @csrf
                <button type="button" class="btn btn-danger text-sm" onclick="confirmDeactivateAccount()">
                    <i class="fas fa-user-slash"></i> Deactivate Account
                </button>
            </form>
        @endif

        @if($canPermanentDelete)
            <p class="text-sm text-gray-700 dark:text-gray-300 mb-3">
                <strong>Temporary feature.</strong> Permanent delete removes the account and all related data:
                documents, teaching guides, exam questionnaires, tasks, notifications, custom folders,
                announcements they authored, and activity tied to this user. This cannot be undone.
            </p>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                Prefer Deactivate if you only need to block login but keep files for archiving.
            </p>

            @error('confirm_username')
                <p class="text-red-600 text-sm mb-2">{{ $message }}</p>
            @enderror
            @error('confirm_phrase')
                <p class="text-red-600 text-sm mb-2">{{ $message }}</p>
            @enderror
            @error('error')
                <p class="text-red-600 text-sm mb-2">{{ $message }}</p>
            @enderror

            <form action="{{ route('dean.destroy-employee', $employee->employee_id) }}" method="POST" id="permanentDeleteForm" class="max-w-lg">
                @csrf
                <div class="form-group">
                    <label class="form-label" for="confirm_username">Type username to confirm</label>
                    <input type="text" name="confirm_username" id="confirm_username" class="form-control"
                           value="{{ old('confirm_username') }}"
                           placeholder="{{ $employee->user->username }}" autocomplete="off" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="confirm_phrase">Type DELETE PERMANENTLY</label>
                    <input type="text" name="confirm_phrase" id="confirm_phrase" class="form-control"
                           value="{{ old('confirm_phrase') }}"
                           placeholder="DELETE PERMANENTLY" autocomplete="off" required>
                </div>
                <button type="button" class="btn btn-danger" onclick="confirmPermanentDeleteAccount()">
                    <i class="fas fa-trash-alt"></i> Permanently delete account and all data
                </button>
            </form>
        @endif
    </section>
    @endif
</div>
@endsection

@push('scripts')
<script>
function confirmTemporaryPassword() {
    var form = document.getElementById('temporaryPasswordForm');
    if (!form) return;
    var message = 'Replace this employee\'s current password with a one-time temporary password? It will be shown once, and they must change it at next login.';
    if (typeof Swal === 'undefined') { if (confirm(message)) form.submit(); return; }
    Swal.fire({ title: 'Generate temporary password?', text: message, icon: 'warning', showCancelButton: true,
        confirmButtonText: 'Generate', cancelButtonText: 'Cancel', confirmButtonColor: '#0d5c3b',
        customClass: { popup: 'swal-flat' } }).then(function (result) { if (result.isConfirmed) form.submit(); });
}

function confirmPermanentDeleteAccount() {
    var form = document.getElementById('permanentDeleteForm');
    if (!form) return;

    var expectedUser = @json($employee->user->username);
    var usernameInput = document.getElementById('confirm_username');
    var phraseInput = document.getElementById('confirm_phrase');

    if (usernameInput && usernameInput.value.trim() !== expectedUser) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({ title: 'Username mismatch', text: 'Enter the exact username: ' + expectedUser, icon: 'error', confirmButtonColor: '#0d5c3b', customClass: { popup: 'swal-flat' } });
        } else {
            alert('Username does not match.');
        }
        return;
    }

    if (phraseInput && phraseInput.value.trim() !== 'DELETE PERMANENTLY') {
        if (typeof Swal !== 'undefined') {
            Swal.fire({ title: 'Confirmation required', text: 'Type DELETE PERMANENTLY in all caps.', icon: 'error', confirmButtonColor: '#0d5c3b', customClass: { popup: 'swal-flat' } });
        } else {
            alert('Type DELETE PERMANENTLY to confirm.');
        }
        return;
    }

    var message = 'This will permanently delete every file, task, and record for this person. This cannot be undone.';

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Permanently delete account?',
            text: message,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, delete everything',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#6b7280',
            customClass: { popup: 'swal-flat' }
        }).then(function (result) {
            if (result.isConfirmed) form.submit();
        });
        return;
    }

    if (confirm(message)) {
        form.submit();
    }
}

function confirmDeactivateAccount() {
    var form = document.getElementById('deactivateAccountForm');
    if (!form) return;

    var message = 'Deactivate this account? They will not be able to sign in or be selected for new tasks. Uploaded documents and folders stay in the system for records.';

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Deactivate account?',
            text: message,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, deactivate',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#6b7280',
            customClass: { popup: 'swal-flat' }
        }).then(function (result) {
            if (result.isConfirmed) form.submit();
        });
        return;
    }

    if (confirm(message)) {
        form.submit();
    }
}
</script>
@endpush
