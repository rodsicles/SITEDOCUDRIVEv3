@extends('layouts.dashboard')

@section('title', 'Edit Profile')

@section('page-title', 'Edit Profile')
@section('page-subtitle', 'Update your personal information and password')

@section('sidebar')
    @if(auth()->user()->hasOversightAccess())
        @include('partials.dean-sidebar')
    @elseif(auth()->user()->isProgramCoordinator())
        @include('partials.coordinator-sidebar')
    @elseif(auth()->user()->isSecretary())
        @include('partials.secretary-sidebar')
    @else
        @include('partials.faculty-sidebar')
    @endif
@endsection

@section('content')
@php
    $displayName = optional($employee)->full_name ?? $user->username;
    $roleName = $user->role->role_name ?? '';
    $programCode = optional($employee)->program;
    $programLabel = $programCode ? (\App\Models\Program::OPTIONS[$programCode] ?? $programCode) : null;
    $employeeNo = optional($employee)->employee_no;
    $openPassword = $errors->hasAny(['current_password', 'new_password']);
@endphp

<div class="profile-panel" data-profile-panel data-initial-tab="{{ $openPassword ? 'password' : 'details' }}">
    <header class="profile-panel__head">
        <form action="{{ route('profile.avatar') }}" method="POST" enctype="multipart/form-data" id="avatarForm" class="profile-panel__avatar-form">
            @csrf
            <div class="profile-panel__avatar">
                @if($user->avatarUrl())
                    <img src="{{ $user->avatarUrl() }}"
                         alt="Profile picture"
                         class="profile-panel__photo"
                         id="avatarPreview"
                         onerror="this.classList.add('hidden'); document.getElementById('avatarFallback')?.classList.remove('hidden');">
                    <div id="avatarFallback" class="profile-panel__initials hidden" aria-hidden="true">{{ $user->initials() }}</div>
                @else
                    <div class="profile-panel__initials" aria-hidden="true">{{ $user->initials() }}</div>
                @endif
            </div>
            <input type="file"
                   name="avatar"
                   id="avatarInput"
                   accept="image/png,image/jpeg,image/webp,.png,.jpg,.jpeg,.webp"
                   class="sr-only"
                   onchange="if (this.files?.length) document.getElementById('avatarForm').submit()">
        </form>

        <div class="profile-panel__identity">
            <h2 class="profile-panel__name">{{ $displayName }}</h2>
            <div class="profile-panel__badges">
                @if($roleName !== '')
                    <span class="profile-panel__role">{{ $roleName }}</span>
                @endif
                @if($programCode)
                    <span class="profile-panel__program" title="{{ $programLabel }}">{{ $programCode }}</span>
                @endif
            </div>
            <div class="profile-panel__photo-actions">
                <label for="avatarInput" class="btn btn-secondary profile-panel__photo-btn">
                    <i class="fas fa-camera" aria-hidden="true"></i> Change photo
                </label>
                <span class="profile-panel__hint">JPG, PNG or WebP, up to 2 MB</span>
            </div>
            @error('avatar')
                <p class="profile-panel__error" role="alert">{{ $message }}</p>
            @enderror
        </div>
    </header>

    <div class="ui-segmented profile-panel__tabs" role="tablist" aria-label="Profile sections">
        <button type="button" class="tab-button" role="tab" id="profile-tab-details" aria-controls="profile-pane-details" data-profile-tab="details">
            <i class="fas fa-id-card" aria-hidden="true"></i> Profile details <span class="profile-panel__dirty-dot" hidden aria-label="unsaved changes"></span>
        </button>
        <button type="button" class="tab-button" role="tab" id="profile-tab-password" aria-controls="change-password" data-profile-tab="password">
            <i class="fas fa-key" aria-hidden="true"></i> Password <span class="profile-panel__dirty-dot" hidden aria-label="unsaved changes"></span>
        </button>
    </div>

    {{-- Profile details --}}
    <form action="{{ route('profile.update') }}" method="POST"
          id="profile-pane-details" class="profile-panel__pane" role="tabpanel" aria-labelledby="profile-tab-details"
          data-profile-pane="details" data-profile-form data-request-guard>
        @csrf
        <div class="profile-panel__body">
            <section class="profile-panel__section" aria-labelledby="profile-account-heading">
                <div class="profile-panel__section-head">
                    <h3 id="profile-account-heading">Account information</h3>
                    <p>Managed by the Dean through Employee Management.</p>
                </div>
                <dl class="profile-panel__facts">
                    <div>
                        <dt>Username</dt>
                        <dd><i class="fas fa-lock" aria-hidden="true"></i> {{ $user->username }}</dd>
                    </div>
                    <div>
                        <dt>Role</dt>
                        <dd><i class="fas fa-lock" aria-hidden="true"></i> {{ $roleName ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt>Program</dt>
                        <dd><i class="fas fa-lock" aria-hidden="true"></i> {{ $programLabel ? $programCode.' — '.$programLabel : 'Not assigned' }}</dd>
                    </div>
                    @if($user->isProgramCoordinator() && $employee && ($extraPrograms = $employee->extraProgramCodes()))
                        <div>
                            <dt>Also handles</dt>
                            <dd><i class="fas fa-lock" aria-hidden="true"></i> {{ implode(', ', $extraPrograms) }}</dd>
                        </div>
                    @endif
                    @unless($canEditEmployeeNo)
                        <div>
                            <dt>Employee number</dt>
                            <dd><i class="fas fa-lock" aria-hidden="true"></i> {{ $employeeNo ?: 'Not assigned' }}</dd>
                        </div>
                    @endunless
                    @unless($canEditFullName)
                        <div class="profile-panel__facts-wide">
                            <dt>Full name</dt>
                            <dd><i class="fas fa-lock" aria-hidden="true"></i> {{ $displayName }}</dd>
                        </div>
                    @endunless
                </dl>
            </section>

            @if($teachingAssignments)
                <section class="profile-panel__section" aria-labelledby="profile-teaching-heading">
                    @include('partials.teaching-assignment-summary', [
                        'assignments' => $teachingAssignments,
                        'headingId' => 'profile-teaching-heading',
                        'loadUrl' => route('teacher-loads.index'),
                    ])
                </section>
            @endif

            <section class="profile-panel__section" aria-labelledby="profile-personal-heading">
                <div class="profile-panel__section-head">
                    <h3 id="profile-personal-heading">Personal details</h3>
                    <p>
                        @if($canEditFullName)
                            You can update these yourself.
                        @else
                            Only the Dean or Program Coordinator can change your name.
                        @endif
                    </p>
                </div>
                <div class="profile-panel__grid">
                    @if($canEditFullName)
                        <div class="profile-panel__field profile-panel__field--wide">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label for="profileFirstName">First name *</label>
                                    <input type="text" id="profileFirstName" name="first_name" maxlength="45" class="form-control @error('first_name') is-invalid @enderror" value="{{ old('first_name', optional($employee)->first_name) }}" autocomplete="given-name" @if($employee?->first_name) required @endif>
                                    @error('first_name')<small class="profile-panel__error">{{ $message }}</small>@enderror
                                </div>
                                <div>
                                    <label for="profileMiddleName">Middle name (optional)</label>
                                    <input type="text" id="profileMiddleName" name="middle_name" maxlength="45" class="form-control @error('middle_name') is-invalid @enderror" value="{{ old('middle_name', optional($employee)->middle_name) }}" autocomplete="additional-name">
                                    @error('middle_name')<small class="profile-panel__error">{{ $message }}</small>@enderror
                                </div>
                                <div>
                                    <label for="profileLastName">Last name *</label>
                                    <input type="text" id="profileLastName" name="last_name" maxlength="45" class="form-control @error('last_name') is-invalid @enderror" value="{{ old('last_name', optional($employee)->last_name) }}" autocomplete="family-name" @if($employee?->last_name) required @endif>
                                    @error('last_name')<small class="profile-panel__error">{{ $message }}</small>@enderror
                                </div>
                            </div>
                            @if($employee && (!$employee->first_name || !$employee->last_name))
                                <small class="profile-panel__note">Current name: {{ $employee->full_name }}. Leave these fields blank to keep it, or enter first and last name to replace it.</small>
                            @endif
                            @error('full_name')<small class="profile-panel__error">{{ $message }}</small>@enderror
                        </div>
                    @endif

                    <div class="profile-panel__field {{ $canEditEmployeeNo ? '' : 'profile-panel__field--wide' }}">
                        <label for="profileEmail">Email address <span class="profile-panel__optional">(optional)</span></label>
                        <input type="email" id="profileEmail" name="email" maxlength="45"
                               class="form-control @error('email') is-invalid @enderror"
                               value="{{ old('email', $user->email) }}" placeholder="you@example.com" autocomplete="email">
                        @error('email')<small class="profile-panel__error">{{ $message }}</small>@enderror
                    </div>

                    @if($canEditEmployeeNo)
                        <div class="profile-panel__field">
                            <label for="profileEmployeeNo">Employee number <span class="profile-panel__optional">(optional)</span></label>
                            <input type="text" id="profileEmployeeNo" name="employee_no" maxlength="20"
                                   class="form-control @error('employee_no') is-invalid @enderror"
                                   value="{{ old('employee_no', $employeeNo) }}" placeholder="e.g. DEAN001">
                            <small class="profile-panel__note">Letters, numbers, and dashes only.</small>
                            @error('employee_no')<small class="profile-panel__error">{{ $message }}</small>@enderror
                        </div>
                    @endif
                </div>
            </section>
        </div>

        <footer class="profile-panel__foot">
            <span class="profile-panel__status" data-profile-status aria-live="polite"></span>
            <button type="reset" class="btn btn-secondary" data-profile-reset disabled>Reset</button>
            <button type="submit" class="btn btn-primary" data-profile-submit disabled>
                <i class="fas fa-save" aria-hidden="true"></i> Save changes
            </button>
        </footer>
    </form>

    {{-- Password --}}
    <form action="{{ route('profile.change-password') }}" method="POST"
          id="change-password" class="profile-panel__pane" role="tabpanel" aria-labelledby="profile-tab-password"
          data-profile-pane="password" data-profile-form data-password-form data-request-guard hidden>
        @csrf
        <div class="profile-panel__body">
            <section class="profile-panel__section" aria-labelledby="profile-security-heading">
                <div class="profile-panel__section-head">
                    <h3 id="profile-security-heading">Change password</h3>
                    <p>Use at least 8 characters. You'll stay signed in on this device.</p>
                </div>
                <div class="profile-panel__grid">
                    <div class="profile-panel__field profile-panel__field--wide">
                        <label for="currentPassword">Current password</label>
                        <div class="profile-panel__secret">
                            <input type="password" id="currentPassword" name="current_password" required autocomplete="current-password"
                                   class="form-control @error('current_password') is-invalid @enderror">
                            <button type="button" class="profile-panel__reveal" data-reveal="currentPassword" aria-label="Show password" aria-pressed="false"><i class="fas fa-eye" aria-hidden="true"></i></button>
                        </div>
                        @error('current_password')<small class="profile-panel__error">{{ $message }}</small>@enderror
                    </div>

                    <div class="profile-panel__field">
                        <label for="newPassword">New password</label>
                        <div class="profile-panel__secret">
                            <input type="password" id="newPassword" name="new_password" required minlength="8" autocomplete="new-password"
                                   class="form-control @error('new_password') is-invalid @enderror">
                            <button type="button" class="profile-panel__reveal" data-reveal="newPassword" aria-label="Show password" aria-pressed="false"><i class="fas fa-eye" aria-hidden="true"></i></button>
                        </div>
                        @error('new_password')<small class="profile-panel__error">{{ $message }}</small>@enderror
                    </div>

                    <div class="profile-panel__field">
                        <label for="confirmNewPassword">Confirm new password</label>
                        <div class="profile-panel__secret">
                            <input type="password" id="confirmNewPassword" name="new_password_confirmation" required minlength="8" autocomplete="new-password"
                                   class="form-control">
                            <button type="button" class="profile-panel__reveal" data-reveal="confirmNewPassword" aria-label="Show password" aria-pressed="false"><i class="fas fa-eye" aria-hidden="true"></i></button>
                        </div>
                    </div>

                    <p class="profile-panel__field--wide profile-panel__match" data-password-match aria-live="polite"></p>
                </div>
            </section>
        </div>

        <footer class="profile-panel__foot">
            <span class="profile-panel__status" data-profile-status aria-live="polite"></span>
            <button type="reset" class="btn btn-secondary" data-profile-reset disabled>Reset</button>
            <button type="submit" class="btn btn-primary" data-profile-submit disabled>
                <i class="fas fa-key" aria-hidden="true"></i> Change password
            </button>
        </footer>
    </form>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const panel = document.querySelector('[data-profile-panel]');
    if (!panel) return;

    const tabs = [...panel.querySelectorAll('[data-profile-tab]')];
    const panes = [...panel.querySelectorAll('[data-profile-pane]')];
    const forms = [...panel.querySelectorAll('[data-profile-form]')];
    let submitting = false;

    const snapshot = (form) => JSON.stringify([...new FormData(form)].filter(([key]) => key !== '_token'));
    const initial = new Map(forms.map((form) => [form, snapshot(form)]));
    const isDirty = (form) => snapshot(form) !== initial.get(form);

    function showTab(name, focus = false) {
        tabs.forEach((tab) => {
            const active = tab.dataset.profileTab === name;
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
            tab.tabIndex = active ? 0 : -1;
            tab.classList.toggle('is-active', active);
            if (active && focus) tab.focus();
        });
        panes.forEach((pane) => { pane.hidden = pane.dataset.profilePane !== name; });
    }

    function passwordReady(form) {
        const current = form.querySelector('#currentPassword').value;
        const next = form.querySelector('#newPassword').value;
        const confirm = form.querySelector('#confirmNewPassword').value;
        const match = form.querySelector('[data-password-match]');
        match.classList.remove('is-ok', 'is-bad');
        if (!next && !confirm) {
            match.textContent = '';
        } else if (next.length < 8) {
            match.textContent = 'New password needs at least 8 characters.';
            match.classList.add('is-bad');
        } else if (confirm && next !== confirm) {
            match.textContent = "Passwords don't match.";
            match.classList.add('is-bad');
        } else if (confirm) {
            match.textContent = 'Passwords match.';
            match.classList.add('is-ok');
        } else {
            match.textContent = '';
        }
        return current !== '' && next.length >= 8 && next === confirm;
    }

    function refresh(form) {
        const dirty = isDirty(form);
        const ready = form.hasAttribute('data-password-form') ? passwordReady(form) : dirty;
        form.querySelector('[data-profile-submit]').disabled = !ready;
        form.querySelector('[data-profile-reset]').disabled = !dirty;
        form.querySelector('[data-profile-status]').textContent = dirty ? 'Unsaved changes' : '';
        const tab = tabs.find((item) => item.dataset.profileTab === form.dataset.profilePane);
        tab?.querySelector('.profile-panel__dirty-dot')?.toggleAttribute('hidden', !dirty);
    }

    forms.forEach((form) => {
        form.addEventListener('input', () => refresh(form));
        form.addEventListener('change', () => refresh(form));
        form.addEventListener('reset', () => setTimeout(() => refresh(form)));
        form.addEventListener('submit', () => { submitting = true; });
        refresh(form);
    });

    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => {
            showTab(tab.dataset.profileTab);
            history.replaceState(null, '', tab.dataset.profileTab === 'password' ? '#change-password' : location.pathname + location.search);
        });
        tab.addEventListener('keydown', (event) => {
            if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
            event.preventDefault();
            const next = tabs[(index + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
            showTab(next.dataset.profileTab, true);
        });
    });

    panel.querySelectorAll('[data-reveal]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.reveal);
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.setAttribute('aria-pressed', show ? 'true' : 'false');
            button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            button.querySelector('i').className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
        });
    });

    window.addEventListener('beforeunload', (event) => {
        if (submitting || !forms.some(isDirty)) return;
        event.preventDefault();
        event.returnValue = '';
    });

    showTab(location.hash === '#change-password' ? 'password' : panel.dataset.initialTab);
})();
</script>
@endpush
