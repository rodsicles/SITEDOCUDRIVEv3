@extends('layouts.dashboard')

@section('title', 'Password Reset Requests')

@section('page-title', 'Password Reset Requests')
@section('page-subtitle', 'Requests you are allowed to handle. The Dean\'s requests go to an Admin; an Admin\'s requests go to the Dean.')

@section('sidebar')
    @if(auth()->user()->isSecretary())
        @include('partials.secretary-sidebar')
    @else
        @include('partials.dean-sidebar')
    @endif
@endsection

@section('content')

{{-- One-time temporary password reveal banner --}}
@if($tempPassword)
<div class="ui-notice ui-notice--warning" role="status">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-key"></i> Temporary Password (Show Once)
        </h3>
        <span class="badge badge-warning">Visible only on this page load</span>
    </div>
    <p class="text-sm text-gray-700 dark:text-gray-200 mb-3">
        Hand this temporary password to <strong>{{ $tempPasswordForUser }}</strong> in person or via a secure channel.
        It will <strong>not</strong> be shown again. The user will be forced to change it on first login.
    </p>
    <div class="flex items-center gap-3 flex-wrap">
        <code id="tempPasswordValue" class="temporary-password-value">{{ $tempPassword }}</code>
        <button type="button" class="btn btn-sm btn-primary" onclick="copyTempPassword()">
            <i class="fas fa-copy"></i> Copy
        </button>
        <span id="tempCopiedNotice" class="text-sm text-green-700 dark:text-green-300" hidden>
            <i class="fas fa-check"></i> Copied
        </span>
    </div>
    <p class="text-xs text-gray-600 dark:text-gray-400 mt-3">
        <i class="fas fa-info-circle"></i> Refreshing or navigating away will clear this value permanently.
    </p>
</div>
@endif

{{-- Flash notices --}}
@if(session('success') && !$tempPassword)
<div class="content-card password-reset-notice password-reset-notice--success" role="status">
    <i class="fas fa-check-circle"></i> {{ session('success') }}
</div>
@endif

@if($errors->any())
<div class="content-card password-reset-notice password-reset-notice--danger" role="alert">
    <i class="fas fa-exclamation-circle"></i> {{ $errors->first() }}
</div>
@endif

<div class="content-card">
    <div class="card-header">
        <h3 class="card-title">Requests</h3>
        <div class="flex items-center gap-2">
            <a href="{{ route('password-reset-requests.index', ['tab' => 'pending']) }}"
               class="btn btn-sm {{ $tab === 'pending' ? 'btn-primary' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300' }}">
                Pending
                @if($pendingCount > 0)
                <span class="badge badge-danger ml-2">{{ $pendingCount }}</span>
                @endif
            </a>
            <a href="{{ route('password-reset-requests.index', ['tab' => 'handled']) }}"
               class="btn btn-sm {{ $tab === 'handled' ? 'btn-primary' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300' }}">
                Recently Handled
            </a>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Requester</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Requested</th>
                    @if($tab === 'pending')
                        <th>Expires</th>
                    @else
                        <th>Handled</th>
                        <th>By</th>
                    @endif
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($requests as $req)
                <tr>
                    <td>
                        <strong>{{ $req->user->employee->full_name ?? $req->user->name ?? '—' }}</strong>
                    </td>
                    <td>{{ $req->user->username }}</td>
                    <td>{{ $req->user->role->role_name ?? '—' }}</td>
                    <td class="text-xs text-gray-600 dark:text-gray-400">
                        {{ $req->created_at->format('M d, Y g:i A') }}
                    </td>
                    @if($tab === 'pending')
                        <td class="text-xs {{ $req->expires_at->isPast() ? 'text-red-600' : 'text-gray-600' }} dark:text-gray-400">
                            {{ $req->expires_at->diffForHumans() }}
                        </td>
                    @else
                        <td class="text-xs text-gray-600 dark:text-gray-400">
                            {{ $req->handled_at ? $req->handled_at->format('M d, Y g:i A') : '—' }}
                        </td>
                        <td class="text-xs text-gray-600 dark:text-gray-400">
                            {{ $req->handler->employee->full_name ?? $req->handler->username ?? '—' }}
                        </td>
                    @endif
                    <td>
                        @switch($req->status)
                            @case(\App\Models\PasswordResetRequest::STATUS_PENDING)
                                <span class="badge badge-warning">Pending</span>
                                @break
                            @case(\App\Models\PasswordResetRequest::STATUS_APPROVED)
                                <span class="badge badge-success">Approved</span>
                                @break
                            @case(\App\Models\PasswordResetRequest::STATUS_DENIED)
                                <span class="badge badge-danger">Denied</span>
                                @break
                            @case(\App\Models\PasswordResetRequest::STATUS_EXPIRED)
                                <span class="badge badge-info">Expired</span>
                                @break
                        @endswitch
                    </td>
                    <td>
                        @if($req->status === \App\Models\PasswordResetRequest::STATUS_PENDING)
                            <div class="flex items-center gap-2">
                                <form action="{{ route('password-reset-requests.approve', $req->password_reset_request_id) }}"
                                      method="POST"
                                      class="password-reset-approve-form"
                                      data-username="{{ $req->user->username }}"
                                      data-requires-reason="{{ $req->user->isDean() ? '1' : '0' }}">
                                    @csrf
                                    <input type="hidden" name="reason" value="">
                                    @if($req->user->isDean())
                                        <span class="badge badge-warning">Dean account</span>
                                    @endif
                                    <button type="submit" class="btn btn-sm btn-primary">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                </form>
                                <button type="button" class="btn btn-sm btn-danger"
                                        onclick="openDenyModal({{ $req->password_reset_request_id }}, '{{ addslashes($req->user->username) }}')">
                                    <i class="fas fa-times"></i> Deny
                                </button>
                            </div>
                        @else
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $req->reason ?: '—' }}
                            </span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="{{ $tab === 'pending' ? 7 : 8 }}" class="text-center text-gray-500 dark:text-gray-400 py-6">
                        @if($tab === 'pending')
                            No pending password reset requests.
                        @else
                            No handled requests yet.
                        @endif
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($requests->hasPages())
    <div class="mt-3">
        {{ $requests->links() }}
    </div>
    @endif
</div>

{{-- Approve confirmation modal --}}
<div id="approveResetModal" class="modal-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="approveResetModalTitle" aria-describedby="approveResetModalDescription">
    <div class="modal-card password-reset-confirm-dialog" role="document">
        <div class="modal-body">
            <div class="password-reset-confirm-dialog__heading">
                <span class="password-reset-confirm-dialog__icon" aria-hidden="true">
                    <i class="fas fa-key"></i>
                </span>
                <div>
                    <p class="password-reset-confirm-dialog__eyebrow">Password reset request</p>
                    <h3 class="card-title" id="approveResetModalTitle">Approve this request?</h3>
                </div>
            </div>
            <p class="password-reset-confirm-dialog__message" id="approveResetModalDescription">
                A one-time temporary password will be generated for
                <strong id="approveResetUsername"></strong>. It will only be shown once after approval.
            </p>
            <div class="password-reset-confirm-dialog__note">
                <i class="fas fa-shield-alt" aria-hidden="true"></i>
                <span>The employee must change the temporary password after signing in.</span>
            </div>
            <div id="approveResetReasonGroup" class="form-group mt-3" hidden>
                <label class="form-label" for="approveResetReason">Reason for resetting the Dean account (required)</label>
                <textarea id="approveResetReason" class="form-control text-sm w-full" maxlength="255" rows="2" placeholder="e.g. Dean asked for help in person on Nov 9"></textarea>
                <p id="approveResetReasonError" class="text-sm text-red-600 mt-1" hidden>Enter at least 5 characters.</p>
            </div>
            <div class="flex items-center gap-2 mt-4 justify-end">
                <button type="button" id="approveResetCancel" class="btn btn-sm bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200">
                    Cancel
                </button>
                <button type="button" id="approveResetConfirm" class="btn btn-sm btn-primary">
                    <i class="fas fa-check" aria-hidden="true"></i> Approve &amp; Generate Password
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Deny modal --}}
<div id="denyModal" class="modal-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="denyModalTitle">
    <div class="modal-card">
        <div class="modal-body">
        <h3 class="card-title mb-3" id="denyModalTitle">Deny Password Reset</h3>
        <form id="denyForm" method="POST" action="">
            @csrf
            <p class="text-sm text-gray-700 dark:text-gray-200 mb-3">
                You are denying the reset request for <strong id="denyUsername"></strong>.
                A short reason will be shown to them in their notification (optional).
            </p>
            <textarea name="reason" class="form-control text-sm w-full" maxlength="255" rows="3" placeholder="Optional reason..."></textarea>
            <div class="flex items-center gap-2 mt-4 justify-end">
                <button type="button" class="btn btn-sm bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200" onclick="closeDenyModal()">Cancel</button>
                <button type="submit" class="btn btn-sm btn-danger">Deny Request</button>
            </div>
        </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
function copyTempPassword() {
    var el = document.getElementById('tempPasswordValue');
    if (!el) return;
    var text = el.innerText;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(function () {
            var notice = document.getElementById('tempCopiedNotice');
            if (notice) {
                notice.hidden = false;
                setTimeout(function () { notice.hidden = true; }, 2000);
            }
        });
    } else {
        var range = document.createRange();
        range.selectNode(el);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);
        try { document.execCommand('copy'); } catch (e) {}
    }
}

var denyBase = "{{ url('password-reset-requests') }}";
var pendingApproveForm = null;

function openApproveResetModal(form) {
    var modal = document.getElementById('approveResetModal');
    var username = document.getElementById('approveResetUsername');
    pendingApproveForm = form;
    username.innerText = form.dataset.username || 'this employee';
    document.getElementById('approveResetReasonGroup').hidden = form.dataset.requiresReason !== '1';
    document.getElementById('approveResetReason').value = '';
    document.getElementById('approveResetReasonError').hidden = true;
    modal.hidden = false;
    modal.classList.add('active');
    document.getElementById('approveResetConfirm').focus();
}

function closeApproveResetModal() {
    var modal = document.getElementById('approveResetModal');
    modal.classList.remove('active');
    modal.hidden = true;
    pendingApproveForm = null;
}

document.querySelectorAll('.password-reset-approve-form').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        openApproveResetModal(form);
    });
});

document.getElementById('approveResetCancel').addEventListener('click', closeApproveResetModal);
document.getElementById('approveResetConfirm').addEventListener('click', function () {
    if (!pendingApproveForm) return;
    var form = pendingApproveForm;
    var reason = document.getElementById('approveResetReason').value.trim();
    if (form.dataset.requiresReason === '1' && reason.length < 5) {
        document.getElementById('approveResetReasonError').hidden = false;
        document.getElementById('approveResetReason').focus();
        return;
    }
    form.querySelector('input[name="reason"]').value = reason;
    var button = this;
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Approving...';
    form.submit();
});

document.getElementById('approveResetModal').addEventListener('click', function (event) {
    if (event.target === this) closeApproveResetModal();
});

function openDenyModal(id, username) {
    var modal = document.getElementById('denyModal');
    var form = document.getElementById('denyForm');
    document.getElementById('denyUsername').innerText = username;
    form.action = denyBase + '/' + id + '/deny';
    modal.hidden = false;
    modal.classList.add('active');
}

function closeDenyModal() {
    var modal = document.getElementById('denyModal');
    modal.classList.remove('active');
    modal.hidden = true;
}

document.getElementById('denyModal').addEventListener('click', function (e) {
    if (e.target === this) closeDenyModal();
});

document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    if (!document.getElementById('approveResetModal').hidden) closeApproveResetModal();
    if (!document.getElementById('denyModal').hidden) closeDenyModal();
});
</script>
@endpush
@endsection
