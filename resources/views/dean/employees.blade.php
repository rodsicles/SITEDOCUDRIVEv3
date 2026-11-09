@extends('layouts.dashboard')

@section('title', 'Employee Management - Dean')

@section('page-title', 'Employee Management')
@section('page-subtitle', 'Manage all employee accounts')

@section('sidebar')
    @include('partials.dean-sidebar')
@endsection

@section('content')
@php
    $viewer = auth()->user();
    $canCreateCoordinator = !$viewer->isAdmin();
    $canCreateAdmin = $viewer->isDean();
    $tabNames = array_values(array_filter(['list', $canCreateCoordinator ? 'createCoord' : null, 'createFaculty', $canCreateAdmin ? 'createAdmin' : null, 'deactivated']));
@endphp
<div class="ui-page ui-page--employees">
    <!-- Tab Navigation -->
    <div class="mb-6">
        <div class="ui-segmented" role="tablist" aria-label="Employee management">
            <button type="button" class="tab-button" onclick="switchTab('list')" id="listTab" role="tab" aria-selected="false">
                <i class="fas fa-users"></i> Employee Directory
            </button>
            @if($canCreateCoordinator)
            <button type="button" class="tab-button" onclick="switchTab('createCoord')" id="createCoordTab" role="tab" aria-selected="false">
                <i class="fas fa-user-tie"></i> Create Coordinator
            </button>
            @endif
            <button type="button" class="tab-button" onclick="switchTab('createFaculty')" id="createFacultyTab" role="tab" aria-selected="false">
                <i class="fas fa-user-plus"></i> Create Faculty
            </button>
            @if($canCreateAdmin)
            <button type="button" class="tab-button" onclick="switchTab('createAdmin')" id="createAdminTab" role="tab" aria-selected="false">
                <i class="fas fa-user-shield"></i> Create Admin
            </button>
            @endif
            <button type="button" class="tab-button" onclick="switchTab('deactivated')" id="deactivatedTab" role="tab" aria-selected="false">
                <i class="fas fa-user-slash"></i> Deactivated Accounts
                @if(($deactivatedEmployees->total() ?? 0) > 0)
                <span class="badge badge-danger text-[10px] py-0.5 px-1.5">{{ $deactivatedEmployees->total() }}</span>
                @endif
            </button>
        </div>
    </div>

    <!-- Tab 1: Employee Directory -->
    <div class="tab-content active" id="listContent">
        <div class="content-card">
            <div class="card-header">
                <h3 class="card-title">Employee Directory</h3>
                <span class="badge badge-info">{{ $employees->total() }} Active</span>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 px-4 -mt-2 mb-3">Active accounts only. Deactivated accounts are listed under <strong>Deactivated Accounts</strong>.</p>

            <table class="data-table">
                <thead>
                    <tr>
                        <th>Employee No.</th>
                        <th>Full Name</th>
                        <th>Program</th>
                        <th>Role</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($employees as $employee)
                    <tr>
                        <td><strong>{{ $employee->employee_no ?? 'N/A' }}</strong></td>
                        <td>
                            <span class="inline-block w-2 h-2 rounded-full mr-1 {{ optional($employee->user)->isOnline() ? 'bg-green-500' : 'bg-gray-300 dark:bg-gray-600' }}"
                                  title="{{ optional($employee->user)->isOnline() ? 'Online' : 'Offline' }}"></span>
                            {{ $employee->full_name }}
                        </td>
                        <td>@include('partials.program-name', ['program' => $employee->program])</td>
                        <td>
                            <span class="badge badge-info">{{ $employee->user->role->role_name ?? ($employee->position ?? 'N/A') }}</span>
                        </td>
                        <td>
                            <a href="{{ route('dean.employee-profile', $employee->employee_id) }}" class="btn btn-primary text-xs">
                                <i class="fas fa-eye"></i> View
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="text-center text-gray-500 dark:text-gray-400">No active employees found</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>

            <div class="mt-5">{{ $employees->links() }}</div>
        </div>
    </div>

    <!-- Tab: Deactivated Accounts -->
    <div class="tab-content" id="deactivatedContent" hidden>
        <div class="content-card">
            <div class="card-header">
                <h3 class="card-title">Deactivated Accounts</h3>
                <span class="badge badge-danger">{{ $deactivatedEmployees->total() }} Deactivated</span>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 px-4 -mt-2 mb-3">These accounts cannot sign in. Open a profile and use <strong>Reactivate Account</strong> to restore access.</p>

            <table class="data-table">
                <thead>
                    <tr>
                        <th>Employee No.</th>
                        <th>Full Name</th>
                        <th>Program</th>
                        <th>Role</th>
                        <th>Action</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($deactivatedEmployees as $employee)
                    <tr>
                        <td><strong>{{ $employee->employee_no ?? 'N/A' }}</strong></td>
                        <td>{{ $employee->full_name }}</td>
                        <td>@include('partials.program-name', ['program' => $employee->program])</td>
                        <td>
                            <span class="badge badge-info">{{ $employee->user->role->role_name ?? ($employee->position ?? 'N/A') }}</span>
                        </td>
                        <td>
                            <a href="{{ route('dean.employee-profile', $employee->employee_id) }}" class="btn btn-primary text-xs">
                                <i class="fas fa-eye"></i> View
                            </a>
                        </td>
                        <td>
                            <span class="badge badge-danger">Inactive</span>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="text-center text-gray-500 dark:text-gray-400">No deactivated accounts</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>

            <div class="mt-5">{{ $deactivatedEmployees->appends(['tab' => 'deactivated'])->links() }}</div>
        </div>
    </div>

    <!-- Tab 2: Create Coordinator -->
    @if($canCreateCoordinator)
    <div class="tab-content" id="createCoordContent" hidden>
        @include('partials.account-create-form', [
            'formKey' => 'coordinator',
            'action' => route('dean.store-coordinator'),
            'coursesUrl' => route('dean.courses.by-program'),
            'numberPreview' => $coordinatorNumberPreview ?? [],
            'title' => 'Create coordinator account',
            'intro' => 'Enter employee details, set sign-in credentials, and assign subjects.',
            'submitLabel' => 'Create Coordinator Account',
            'submitIcon' => 'fa-user-tie',
            'cancelOnclick' => "switchTab('list')",
            'numberExample' => 'SITE-IT-COOR001',
        ])
    </div>
    @endif

    <!-- Tab 3: Create Faculty -->
    <div class="tab-content" id="createFacultyContent" hidden>
        @include('partials.account-create-form', [
            'formKey' => 'faculty',
            'action' => route('dean.store-faculty'),
            'coursesUrl' => route('dean.courses.by-program'),
            'numberPreview' => $facultyNumberPreview ?? [],
            'title' => 'Create faculty account',
            'intro' => 'Enter employee details, set sign-in credentials, and assign subjects.',
            'submitLabel' => 'Create Faculty Account',
            'submitIcon' => 'fa-user-plus',
            'cancelOnclick' => "switchTab('list')",
            'numberExample' => 'SITE-IT-FAC001',
        ])
    </div>

    <!-- Tab: Create Admin (Dean only) -->
    @if($canCreateAdmin)
    @php $isOldAdmin = old('_form') === 'admin'; @endphp
    <div class="tab-content" id="createAdminContent" hidden>
        <div class="content-card employee-account-card">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Create admin account</h3>
                    <p class="employee-account-card__intro">Admins handle day-to-day oversight across all programs: faculty accounts, Course Catalog, Recycle Bin, Rejected Files, Activity Log, and password resets. Backups, permanent deletes, school-year archiving, and Coordinator/Admin accounts stay with the Dean.</p>
                </div>
            </div>

            @if($isOldAdmin && $errors->any())
                <div class="alert alert-error">
                    <strong><i class="fas fa-exclamation-circle"></i> Please fix the following:</strong>
                    <ul class="mt-2 ml-5">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('dean.store-admin') }}" method="POST" class="account-form" data-account-form="admin">
                @csrf
                <input type="hidden" name="_form" value="admin">
                <div class="account-form__grid">
                    <div class="account-form__col">
                        <div class="ui-form-section">
                            <h4 class="ui-form-section__title">Employee details</h4>
                            <div class="form-group">
                                <label class="form-label" for="adminFirstName">First name *</label>
                                <input type="text" id="adminFirstName" name="first_name" class="form-control" autocomplete="given-name" required maxlength="45" value="{{ $isOldAdmin ? old('first_name') : '' }}">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="adminMiddleName">Middle name <span class="text-gray-500">(optional)</span></label>
                                <input type="text" id="adminMiddleName" name="middle_name" class="form-control" autocomplete="additional-name" maxlength="45" value="{{ $isOldAdmin ? old('middle_name') : '' }}">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="adminLastName">Last name *</label>
                                <input type="text" id="adminLastName" name="last_name" class="form-control" autocomplete="family-name" required maxlength="45" value="{{ $isOldAdmin ? old('last_name') : '' }}">
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Employee number is generated automatically (e.g. SITE-GEN-ADMIN001). Admins are not tied to one program.</p>
                        </div>
                    </div>
                    <div class="account-form__col">
                        <div class="ui-form-section">
                            <h4 class="ui-form-section__title">Account access</h4>
                            <div class="form-group">
                                <label class="form-label" for="adminUsername">Username *</label>
                                <input type="text" id="adminUsername" name="username" class="form-control" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="Choose a username" required maxlength="20" value="{{ $isOldAdmin ? old('username') : '' }}">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="adminPassword">Temporary password *</label>
                                <input type="password" id="adminPassword" name="password" class="form-control" autocomplete="new-password" placeholder="Minimum 8 characters" required minlength="8" maxlength="40">
                                <small class="text-xs text-gray-500 dark:text-gray-400 mt-1">The Admin must change it at first login.</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="account-form__actions">
                    <button type="submit" class="btn btn-success"><i class="fas fa-user-shield"></i> Create Admin Account</button>
                    <button type="button" class="btn btn-secondary" onclick="switchTab('list')"><i class="fas fa-times"></i> Cancel</button>
                </div>
            </form>
        </div>
    </div>
    @endif

    </div>

    <script>
        function switchTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(c => c.hidden = true);
            document.querySelectorAll('.tab-button').forEach(b => {
                b.classList.remove('is-active');
                b.setAttribute('aria-selected', 'false');
            });

            const tabMap = {
                list: { content: 'listContent', button: 'listTab' },
                createCoord: { content: 'createCoordContent', button: 'createCoordTab' },
                createFaculty: { content: 'createFacultyContent', button: 'createFacultyTab' },
                createAdmin: { content: 'createAdminContent', button: 'createAdminTab' },
                deactivated: { content: 'deactivatedContent', button: 'deactivatedTab' },
            };

            const t = tabMap[tabName];
            if (t && document.getElementById(t.content)) {
                document.getElementById(t.content).hidden = false;
                const btn = document.getElementById(t.button);
                btn.classList.add('is-active');
                btn.setAttribute('aria-selected', 'true');
            }

            const url = new URL(window.location.href);
            if (tabName === 'list') {
                url.searchParams.delete('tab');
            } else if (['deactivated', 'createCoord', 'createFaculty', 'createAdmin'].includes(tabName)) {
                url.searchParams.set('tab', tabName);
            }
            window.history.replaceState({}, '', url);
        }

        // Reopen the form that failed validation so its restored input and subjects are visible.
        @if($errors->any() && old('_form') === 'coordinator')
            document.addEventListener('DOMContentLoaded', () => switchTab('createCoord'));
        @elseif($errors->any() && old('_form') === 'faculty')
            document.addEventListener('DOMContentLoaded', () => switchTab('createFaculty'));
        @elseif($errors->any() && old('_form') === 'admin')
            document.addEventListener('DOMContentLoaded', () => switchTab('createAdmin'));
        @endif

        // Default tab (supports ?tab=deactivated after deactivation)
        document.addEventListener('DOMContentLoaded', function() {
            const initialTab = @json(request('tab', 'list'));
            const allowed = @json($tabNames);
            if (!document.querySelector('.tab-button.is-active')) {
                switchTab(allowed.includes(initialTab) ? initialTab : 'list');
            }
        });

        // Prevent double submit
        document.querySelectorAll('form[data-account-form]').forEach(form => {
            form.addEventListener('submit', function(e) {
                if (e.defaultPrevented) return;
                const btn = this.querySelector('button[type="submit"]');
                if (btn && !btn.disabled) {
                    btn.dataset.original = btn.innerHTML;
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
                    setTimeout(() => {
                        btn.disabled = false;
                        btn.innerHTML = btn.dataset.original;
                    }, 5000);
                }
            });
        });
    </script>
    @include('partials.course-assignment-guide-script')
@endsection
