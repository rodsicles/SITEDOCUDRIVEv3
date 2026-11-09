@extends('layouts.dashboard')
@section('title', 'Manage document request')
@section('page-title', 'Manage document request')
@section('page-subtitle', $documentRequest->title)
@section('sidebar')
    @include(auth()->user()->hasOversightAccess() ? 'partials.dean-sidebar' : 'partials.coordinator-sidebar')
@endsection
@section('content')
<a class="btn btn-secondary mb-4" href="{{ route('document-requests.index', ['tab' => 'sent']) }}">Back to sent requests</a>
@if($errors->any())<div class="workflow-alert workflow-alert--danger" role="alert">{{ $errors->first() }}</div>@endif
<div class="content-card mb-4">
    <div class="card-header"><h2 class="card-title">{{ $documentRequest->title }}</h2><span class="badge badge-info">{{ ucfirst($documentRequest->status) }}</span></div>
    <div class="p-4">
        <p>{{ $documentRequest->instructions ?: 'No additional instructions.' }}</p>
        <p class="text-sm mt-2">{{ $documentRequest->due_at ? 'Due '.$documentRequest->due_at->format('M d, Y g:i A') : 'No deadline' }} · {{ $documentRequest->allow_late_submission ? 'Late submissions allowed' : 'Deadline enforced' }}</p>
        <p class="text-sm mt-2">{{ $documentRequest->course?->label() ?? 'General document' }} · {{ $documentRequest->document_type }}. The request destination and existing submissions are retained.</p>
        <div class="flex flex-wrap gap-2 mt-4">
        @foreach($documentRequest->status === 'open' ? ['update' => 'Edit details / deadline', 'recipients' => 'Add or reinstate recipients', 'exempt' => 'Exempt recipient', 'cancel' => 'Cancel request'] : ['reopen' => 'Reopen request'] as $action => $label)
            <button type="button" class="btn btn-secondary" data-request-dialog="manage-{{ $action }}">{{ $label }}</button>
        @endforeach
        </div>
    </div>
</div>
<div class="content-card mb-4">
    <div class="card-header"><h2 class="card-title">Recipients and submissions</h2></div>
    <div class="table-responsive"><table class="data-table"><thead><tr><th>Employee</th><th>Status</th><th>Submission</th></tr></thead><tbody>
    @foreach($documentRequest->recipients as $recipient)
        <tr><td>{{ $recipient->recipient?->employee?->full_name ?? $recipient->recipient?->username }}</td><td>{{ str($recipient->status)->replace('_', ' ')->title() }}@if($recipient->exemption_reason)<p>{{ $recipient->exemption_reason }}</p>@endif</td><td>{{ $recipient->document?->document_title ?? 'No file submitted' }}</td></tr>
    @endforeach
    </tbody></table></div>
</div>
<div class="content-card">
    <div class="card-header"><h2 class="card-title">Change history</h2></div>
    <div class="table-responsive"><table class="data-table"><thead><tr><th>Action / date</th><th>Changed by</th><th>Reason and changes</th></tr></thead><tbody>
    @forelse($documentRequest->changes as $change)
        <tr><td>{{ str($change->action)->title() }}<br>{{ $change->created_at->format('M d, Y g:i A') }}</td><td>{{ $change->actor?->employee?->full_name ?? $change->actor?->username ?? 'Former account' }}</td><td>{{ $change->reason }}
            @foreach(['title' => 'Title', 'instructions' => 'Instructions', 'due_at' => 'Deadline', 'allow_late_submission' => 'Late submissions', 'status' => 'Status'] as $key => $label)
                @if(($change->before[$key] ?? null) !== ($change->after[$key] ?? null))
                    <p class="text-sm mt-1"><strong>{{ $label }}:</strong> {{ is_bool($change->before[$key] ?? null) ? ($change->before[$key] ? 'Allowed' : 'Not allowed') : ($change->before[$key] ?? 'None') }} &rarr; {{ is_bool($change->after[$key] ?? null) ? ($change->after[$key] ? 'Allowed' : 'Not allowed') : ($change->after[$key] ?? 'None') }}</p>
                @endif
            @endforeach
            @foreach($change->after['recipients'] ?? [] as $record)
                @php $previous = collect($change->before['recipients'] ?? [])->firstWhere('user_id', $record['user_id']); @endphp
                @if(($previous['status'] ?? null) !== $record['status'])
                    @php $employeeRecord = $documentRequest->recipients->firstWhere('user_id', $record['user_id']); @endphp
                    <p class="text-sm mt-1"><strong>{{ $employeeRecord?->recipient?->employee?->full_name ?? $employeeRecord?->recipient?->username ?? 'Former employee' }}:</strong> {{ str($previous['status'] ?? 'not assigned')->replace('_', ' ')->title() }} &rarr; {{ str($record['status'])->replace('_', ' ')->title() }}</p>
                @endif
            @endforeach
        </td></tr>
    @empty
        <tr><td colspan="3">No management changes recorded yet.</td></tr>
    @endforelse
    </tbody></table></div>
</div>

@foreach(['update' => 'Edit request details', 'recipients' => 'Add or reinstate recipients', 'exempt' => 'Exempt recipient', 'cancel' => 'Cancel request', 'reopen' => 'Reopen request'] as $action => $label)
<dialog class="ui-dialog" id="manage-{{ $action }}" aria-labelledby="manage-{{ $action }}-title">
<form method="POST" action="{{ route('document-requests.change', $documentRequest) }}" class="ui-dialog__form" data-request-guard>
    @csrf @method('PATCH')
    <input type="hidden" name="action" value="{{ $action }}"><input type="hidden" name="lock_version" value="{{ $documentRequest->lock_version }}">
    <header class="ui-dialog__head"><h2 class="ui-dialog__title" id="manage-{{ $action }}-title">{{ $label }}</h2><button type="button" class="ui-dialog__close" data-request-close aria-label="Close">&times;</button></header>
    <div class="ui-dialog__body">
        @if($action === 'update')
            <label class="form-label" for="request-edit-title">Title *</label><input id="request-edit-title" name="title" class="form-control" maxlength="150" value="{{ old('title', $documentRequest->title) }}" required>
            <label class="form-label" for="request-edit-instructions">Instructions</label><textarea id="request-edit-instructions" name="instructions" class="form-control" rows="3" maxlength="2000">{{ old('instructions', $documentRequest->instructions) }}</textarea>
        @endif
        @if(in_array($action, ['update', 'reopen']))
            <label class="form-label" for="request-{{ $action }}-due">Deadline (optional)</label><input type="datetime-local" id="request-{{ $action }}-due" name="due_at" class="form-control" value="{{ old('due_at', $documentRequest->due_at?->format('Y-m-d\TH:i')) }}">
            <input type="hidden" name="allow_late_submission" value="0"><label><input type="checkbox" name="allow_late_submission" value="1" @checked(old('allow_late_submission', $documentRequest->allow_late_submission))> Allow late submissions</label>
        @elseif($action === 'recipients')
            <p>Existing files and review status are preserved when reinstating an exempt recipient.</p>
            <label class="form-label" for="recipient-search">Find an employee</label><input type="search" class="form-control" id="recipient-search" placeholder="Search name or program">
            <fieldset><legend class="form-label">Choose employees *</legend>
            @foreach($people as $person)
                @if(!$documentRequest->recipients->contains(fn ($r) => (int) $r->user_id === (int) $person->id && $r->status !== 'exempt'))
                    <label class="block py-2" data-recipient-name="{{ strtolower(($person->employee?->full_name ?? $person->username).' '.$person->employee?->program) }}"><input type="checkbox" name="recipient_ids[]" value="{{ $person->id }}" @checked(in_array($person->id, old('recipient_ids', [])))> {{ $person->employee?->full_name ?? $person->username }} · {{ $person->employee?->program }}</label>
                @endif
            @endforeach
            </fieldset>
        @elseif($action === 'exempt')
            <p>The recipient is excused from this request. Their uploaded file stays on record. Approved submissions remain approved.</p>
            <label class="form-label" for="request-exempt-recipient">Recipient *</label><select class="form-control" name="recipient_id" id="request-exempt-recipient" required><option value="">Choose recipient</option>@foreach($documentRequest->recipients->whereIn('status', ['pending', 'submitted', 'changes_requested']) as $recipient)<option value="{{ $recipient->id }}">{{ $recipient->recipient?->employee?->full_name ?? $recipient->recipient?->username }}</option>@endforeach</select>
        @else
            <p>Existing submissions and approvals are preserved. {{ $action === 'cancel' ? 'New submissions and reviews stop until this request is reopened.' : 'Recipients can continue outstanding submissions under the selected deadline rules.' }}</p>
        @endif
        <label class="form-label" for="request-{{ $action }}-reason">Reason *</label><textarea class="form-control" id="request-{{ $action }}-reason" name="reason" rows="2" minlength="5" maxlength="1000" required>{{ old('action') === $action ? old('reason') : '' }}</textarea>
    </div>
    <footer class="ui-dialog__foot"><button type="button" class="btn btn-secondary" data-request-close>Cancel</button><button type="submit" class="btn {{ $action === 'cancel' ? 'btn-danger' : 'btn-primary' }}">{{ $label }}</button></footer>
</form></dialog>
@endforeach
@endsection
@push('scripts')
<script>
document.querySelectorAll('[data-request-dialog]').forEach(button => button.addEventListener('click', () => document.getElementById(button.dataset.requestDialog).showModal()));
document.querySelectorAll('[data-request-close]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
document.getElementById('recipient-search').addEventListener('input', event => document.querySelectorAll('[data-recipient-name]').forEach(row => { row.hidden = !row.dataset.recipientName.includes(event.target.value.toLowerCase().trim()); }));
@if($errors->any() && in_array(old('action'), ['update', 'recipients', 'exempt', 'cancel', 'reopen'], true))
document.getElementById(@json('manage-'.old('action'))).showModal();
@endif
</script>
@endpush
