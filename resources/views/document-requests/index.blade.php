@extends('layouts.dashboard')

@section('title', 'Document Requests')
@section('page-title', 'Document Requests')
@section('page-subtitle', 'Request, submit, and track required employee documents')

@section('sidebar')
    @if(auth()->user()->isProgramCoordinator())
        @include('partials.coordinator-sidebar')
    @elseif(auth()->user()->isFaculty())
        @include('partials.faculty-sidebar')
    @else
        @include('partials.dean-sidebar')
    @endif
@endsection

@section('content')
@php
    $viewer = auth()->user();
    $viewRoute = fn ($id) => route(($viewer->isProgramCoordinator() ? 'coordinator' : ($viewer->isFaculty() ? 'faculty' : 'dean')).'.view-document', $id);
@endphp
<div class="request-workspace">
    <div class="request-summary-strip">
        <div><span>Needs your action</span><strong>{{ $pendingCount }}</strong></div>
        <div><span>Received</span><strong>{{ $tab === 'received' ? $requests->total() : '—' }}</strong></div>
        <div><span>Sent</span><strong>{{ $tab === 'sent' ? $requests->total() : '—' }}</strong></div>
        @if($canCreateRequests)<button type="button" class="btn btn-primary" data-open-modal="createRequestModal"><i class="fas fa-plus"></i> New request</button>@endif
    </div>

    @if(session('success'))<div class="workflow-alert workflow-alert--success" role="status"><i class="fas fa-check-circle"></i>{{ session('success') }}</div>@endif
    @if($errors->any() && old('request_category') === null)<div class="workflow-alert workflow-alert--danger" role="alert"><i class="fas fa-circle-exclamation"></i>{{ $errors->first() }}</div>@endif

    <div class="request-toolbar">
        <nav class="request-tabs" aria-label="Request views">
            <a href="{{ route('document-requests.index', ['tab' => 'received']) }}" class="{{ $tab === 'received' ? 'active' : '' }}">Received</a>
            <a href="{{ route('document-requests.index', ['tab' => 'sent']) }}" class="{{ $tab === 'sent' ? 'active' : '' }}">Sent</a>
        </nav>
        <form method="GET" class="request-filter">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <label for="requestStatus">Status</label>
            <select id="requestStatus" name="status" onchange="this.form.submit()">
                <option value="">All statuses</option>
                @foreach(['pending','submitted','changes_requested','approved','exempt','open','completed','cancelled'] as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ str($status)->replace('_', ' ')->title() }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <div class="request-list">
        @forelse($requests as $item)
            @php $myRecord = $item->recipients->firstWhere('user_id', $viewer->id); @endphp
            <article class="request-row">
                <div class="request-row__main">
                    <div class="request-row__heading">
                        <h2>{{ $item->title }}</h2>
                        @if($item->status !== 'open')<span class="request-status">Request {{ $item->status }}</span>@endif
                        @if($tab === 'received' && $myRecord)
                            <span class="request-status request-status--{{ $myRecord->status }}">{{ str($myRecord->status)->replace('_', ' ')->title() }}</span>
                        @else
                            <span class="request-status">{{ $item->recipients->where('status', 'approved')->count() }}/{{ $item->recipients->where('status', '!=', 'exempt')->count() }} approved</span>
                        @endif
                    </div>
                    <p>{{ $item->instructions ?: 'No additional instructions.' }}</p>
                    <div class="request-meta">
                        <span><i class="fas fa-user"></i>{{ $item->requester->employee->full_name ?? $item->requester->username }}</span>
                        <span><i class="fas fa-tag"></i>{{ match($item->request_category) { 'teaching_guide' => 'Teaching Guide', 'exam_questionnaire' => 'Exam Questionnaire', default => 'General Document' } }}</span>
                        @if($item->destinationFolder?->document_category_id)<span>{{ $item->destinationFolder->managedCategory?->category_name }}</span>@endif
                        @if($item->course)<span><i class="fas fa-book"></i>{{ $item->course->code }}</span>@endif
                        @if($item->program)<span><i class="fas fa-building"></i>{{ $item->program }}</span>@endif
                        @if($item->schoolYear)<span><i class="fas fa-calendar"></i>{{ $item->schoolYear->name }}</span>@endif
                        <span class="{{ $item->due_at?->isPast() ? 'is-overdue' : '' }}"><i class="fas fa-clock"></i>{{ $item->due_at ? 'Due '.$item->due_at->format('M d, Y g:i A') : 'No deadline' }}</span>
                    </div>
                </div>

                @if($tab === 'received' && $myRecord)
                    <div class="request-row__action">
                        @if($item->status === 'open' && in_array($myRecord->status, ['pending','changes_requested']))
                            @if($myRecord->review_note)<p class="request-review-note"><strong>Correction:</strong> {{ $myRecord->review_note }}</p>@endif
                            <button type="button" class="btn btn-primary request-submit-trigger" data-open-modal="submitRequestModal" data-recipient="{{ $myRecord->id }}" data-title="{{ $item->title }}" data-type="{{ $item->document_type }}">Submit document</button>
                        @elseif($myRecord->document)
                            <a class="btn btn-secondary" href="{{ $viewRoute($myRecord->document->document_id) }}"><i class="fas fa-eye"></i> View submission</a>
                        @endif
                    </div>
                @else
                    <div class="request-recipient-list">
                        @foreach($item->recipients as $record)
                        <div class="request-recipient">
                            <div><strong>{{ $record->recipient->employee->full_name ?? $record->recipient->username }}</strong><span>{{ str($record->status)->replace('_', ' ')->title() }}</span></div>
                            @if($record->status === 'submitted' && $item->status === 'open')
                            <div class="request-review-actions">
                                @if($record->document)<a href="{{ $viewRoute($record->document->document_id) }}" class="btn btn-secondary"><i class="fas fa-eye"></i> View</a>@endif
                                <form method="POST" action="{{ route('document-requests.review', $record) }}">@csrf<input type="hidden" name="decision" value="approved"><button class="btn btn-primary">Approve</button></form>
                                <button type="button" class="btn btn-secondary request-changes-trigger" data-open-modal="changesRequestModal" data-recipient="{{ $record->id }}" data-name="{{ $record->recipient->employee->full_name ?? $record->recipient->username }}">Request changes</button>
                            </div>
                            @endif
                        </div>
                        @endforeach
                    </div>
                    @if($canCreateRequests && (int) $item->requested_by === (int) $viewer->id)
                        <a class="btn btn-secondary" href="{{ route('document-requests.manage', $item) }}">Manage request</a>
                    @endif
                @endif
            </article>
        @empty
            @include('partials.ui.empty-state', ['title' => 'No document requests here', 'text' => $tab === 'received' ? 'New requests assigned to you will appear here.' : 'Create a request to begin tracking compliance.'])
        @endforelse
    </div>
    {{ $requests->links() }}
</div>

@if($canCreateRequests)
<div id="createRequestModal" class="modal-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="createRequestTitle">
    <div class="modal-card modal-card--wide request-modal">
        <div class="modal-header"><div><h2 id="createRequestTitle" class="modal-title">{{ $viewer->isFaculty() ? 'Request a document from the Secretary' : 'New document request' }}</h2><p>{{ $viewer->isFaculty() ? 'Choose a Secretary and describe the file you need. They will upload it for you here.' : 'Assign one requirement to one or more employees. Choose a Secretary to request a file from the Dean office.' }}</p></div><button type="button" class="modal-close" data-close-modal><i class="fas fa-times"></i></button></div>
        <form id="createDocumentRequestForm" method="POST" action="{{ route('document-requests.store') }}">@csrf
            <div class="modal-body request-form-grid">
                @if($errors->any() && old('request_category') !== null)
                    <div class="workflow-alert workflow-alert--danger request-form-wide" role="alert" tabindex="-1" id="requestValidationSummary"><strong>Request not sent. Please correct:</strong><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
                @endif
                <div class="form-group request-form-wide"><label class="form-label">Request title *</label><input class="form-control" name="title" value="{{ old('title') }}" maxlength="150" required placeholder="e.g. Updated faculty workload form"></div>
                <div class="form-group request-form-wide"><label class="form-label">Instructions</label><textarea class="form-control" name="instructions" maxlength="2000" rows="3" placeholder="Explain exactly what must be submitted.">{{ old('instructions') }}</textarea></div>
                <fieldset class="request-people request-form-wide"><legend>Recipients *</legend>
                    <div class="request-recipient-summary"><strong id="requestSelectedCount" role="status" aria-live="polite">No recipients selected</strong><p id="requestSelectedNames">Choose who should submit this document.</p><button type="button" class="btn btn-secondary" id="editRequestRecipients" aria-controls="requestRecipientPicker" aria-expanded="true">Change recipients</button></div>
                    <div id="requestRecipientPicker">
                    <label class="sr-only" for="requestPeopleSearch">Find a recipient</label>
                    <input id="requestPeopleSearch" type="search" class="form-control request-people-search" placeholder="Find an employee by name, username, or program…">
                    <div class="request-people-list">@foreach($people as $person)<label data-search="{{ strtolower(($person->employee->full_name ?? $person->username).' '.$person->username.' '.($person->employee->program ?? '').' '.($person->role->role_name ?? '')) }}"><input type="checkbox" name="recipient_ids[]" value="{{ $person->id }}" data-course-ids='@json($person->assignedCourses->pluck('id')->values())' @checked(in_array($person->id, old('recipient_ids', [])))><span><strong>{{ $person->employee->full_name ?? $person->username }}</strong><small>{{ $person->role->role_name ?? 'Employee' }} · {{ $person->employee->program ?? 'No program' }}</small></span></label>@endforeach</div>
                    <div class="flex items-center justify-between gap-2 mt-2"><small id="requestPeopleStatus" role="status" aria-live="polite">{{ $people->count() }} eligible recipients</small><button type="button" class="btn btn-secondary" id="refreshRequestPeople">Refresh recipients</button></div>
                    <div class="flex items-center justify-between gap-2 mt-2"><small>Select employees, then click Done selecting. This does not send the request.</small><button type="button" class="btn btn-primary" id="doneRequestRecipients">Done selecting</button></div>
                    </div>
                    <small id="requestRecipientsError" class="request-field-error" role="alert" hidden>Choose at least one recipient.</small>
                    @error('recipient_ids')<small class="request-field-error" role="alert">{{ $message }}</small>@enderror
                </fieldset>
                <div class="form-group"><label class="form-label">Accepted file type *</label><select id="requestDocumentType" class="form-control" name="document_type" required><option value="any" @selected(old('document_type', 'any') === 'any')>PDF, Word, or image</option><option value="pdf" @selected(old('document_type') === 'pdf')>PDF only</option><option value="word" @selected(old('document_type') === 'word')>Word only</option><option value="image" @selected(old('document_type') === 'image')>Image only</option></select></div>
                <div class="form-group"><label class="form-label" for="requestDueAt">Due date &amp; time (optional)</label><input id="requestDueAt" class="form-control" type="datetime-local" name="due_at" value="{{ old('due_at') }}" min="{{ now()->addMinute()->format('Y-m-d\TH:i') }}" aria-describedby="requestDueHint requestDueError" @error('due_at') aria-invalid="true" @enderror><small id="requestDueHint">{{ config('app.timezone') }} time. Choose a future date and time, or leave blank for no deadline.</small><small id="requestDueError" class="request-field-error" role="alert" @if(!$errors->has('due_at')) hidden @endif>{{ $errors->first('due_at') }}</small></div>
                <div class="form-group request-form-wide"><label class="form-label" for="requestCategory">Request category *</label><select id="requestCategory" class="form-control" name="request_category" required><option value="general" @selected(old('request_category', 'general') === 'general')>General Document</option>@unless($viewer->isFaculty())<option value="teaching_guide" @selected(old('request_category') === 'teaching_guide')>Teaching Guide</option><option value="exam_questionnaire" @selected(old('request_category') === 'exam_questionnaire')>Exam Questionnaire</option>@endunless</select><small id="requestCategoryHint" class="text-xs text-gray-500">General documents are filed in Uncategorized Files and marked with their request origin.</small></div>
                <input id="requestCourseId" type="hidden" name="course_id" value="{{ old('course_id') }}">
                <input id="requestSchoolYearId" type="hidden" name="school_year_id" value="{{ old('school_year_id') }}">
                <input id="requestSemesterValue" type="hidden" name="semester" value="{{ old('semester') }}">
                <input id="requestDestinationType" type="hidden" name="destination_type" value="{{ old('destination_type') }}">
                <input id="requestExamPeriod" type="hidden" name="exam_period" value="{{ old('exam_period') }}">
                <div id="generalRequestPeriod" class="request-form-wide request-form-grid"><div class="form-group"><label class="form-label" for="generalSchoolYear">School year</label><select id="generalSchoolYear" class="form-control"><option value="">Not specified</option>@foreach($schoolYears as $year)<option value="{{ $year->id }}">{{ $year->name }}</option>@endforeach</select></div><div class="form-group"><label class="form-label" for="generalSemester">Semester</label><select id="generalSemester" class="form-control"><option value="">Not specified</option><option value="1st">1st semester</option><option value="2nd">2nd semester</option></select></div></div>
                <div id="generalCategoryGroup" class="form-group request-form-wide" @if(old('request_category', 'general') !== 'general') hidden @endif><label for="generalCategory" class="form-label">File general requests under</label><select id="generalCategory" name="custom_category_id" class="form-control"><option value="">Uncategorized Files</option>@foreach($customRequestCategories as $customCategory)<option value="{{ $customCategory->category_id }}" @selected((string) old('custom_category_id') === (string) $customCategory->category_id)>{{ $customCategory->category_name }}</option>@endforeach</select><small class="text-xs">Only active system-wide categories are available. Personal categories stay private.</small></div>
                <div id="requestDestinationGroup" class="form-group request-form-wide" hidden><label class="form-label">Filing destination *</label><div class="request-destination-control"><button id="openDestinationModal" type="button" class="btn btn-secondary"><i class="fas fa-folder-tree"></i> Choose destination</button><span id="requestDestinationPath">No destination selected</span></div><small class="text-xs text-gray-500">Choose the course and filing details in a separate guided window.</small></div>
                <label class="request-checkbox request-form-wide"><input type="checkbox" name="allow_late_submission" value="1" checked> Allow late submissions and mark them overdue</label>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-close-modal>Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Send request</button></div>
        </form>
    </div>
</div>

<div id="destinationPickerModal" class="modal-overlay destination-picker-modal" hidden role="dialog" aria-modal="true" aria-labelledby="destinationPickerTitle">
    <div class="modal-card destination-picker-card">
        <div class="modal-header destination-picker-header"><div><h2 id="destinationPickerTitle" class="modal-title">Choose filing destination</h2><p>Select one option in each step. The folder path is created automatically.</p></div><button id="closeDestinationModal" type="button" class="modal-close" aria-label="Close destination picker"><i class="fas fa-times"></i></button></div>
        <div class="modal-body destination-picker-body">
            <div class="destination-step"><span class="destination-step__number">1</span><div><label class="form-label" for="destinationCourse">Course</label><select id="destinationCourse" class="form-control" data-selected-course="{{ old('course_id') }}"><option value="">Select a recipient first</option></select><small id="requestCourseHint" class="text-xs text-gray-500">Only courses assigned to every selected recipient are available.</small></div></div>
            <div class="destination-step"><span class="destination-step__number">2</span><div class="destination-paired-fields"><div><label class="form-label" for="destinationSchoolYear">School year</label><select id="destinationSchoolYear" class="form-control"><option value="">Select school year</option>@foreach($schoolYears as $year)<option value="{{ $year->id }}" data-label="{{ $year->name }}" @selected((string) old('school_year_id') === (string) $year->id || (old('school_year_id') === null && $year->is_active))>{{ $year->name }}</option>@endforeach</select></div><div><label class="form-label" for="destinationSemester">Semester</label><select id="destinationSemester" class="form-control"><option value="">Select semester</option><option value="1st" @selected(old('semester') === '1st')>1st semester</option><option value="2nd" @selected(old('semester') === '2nd')>2nd semester</option></select></div></div></div>
            <div id="examPeriodStep" class="destination-step" hidden><span class="destination-step__number">3</span><div><span class="form-label">Exam period</span><div class="destination-choice-row" role="radiogroup" aria-label="Exam period">@foreach(['prelims' => 'Prelims', 'midterms' => 'Midterms', 'finals' => 'Finals'] as $value => $label)<button type="button" class="destination-choice" data-exam-period="{{ $value }}">{{ $label }}</button>@endforeach</div></div></div>
            <div class="destination-step"><span id="documentTypeStepNumber" class="destination-step__number">3</span><div><span class="form-label">Document type</span><div id="teachingGuideTypes" class="destination-choice-row" role="radiogroup" aria-label="Teaching Guide document type"><button type="button" class="destination-choice" data-destination-type="tg">TG</button><button type="button" class="destination-choice" data-destination-type="lb">LB</button></div><div id="examQuestionnaireTypes" class="destination-choice-row" role="radiogroup" aria-label="Exam Questionnaire document type" hidden><button type="button" class="destination-choice" data-destination-type="tos">TOS</button><button type="button" class="destination-choice" data-destination-type="toq">TOQ</button></div></div></div>
            <div class="destination-summary"><span>Selected destination</span><strong id="destinationDraftSummary">Complete the choices above.</strong></div>
        </div>
        <div class="modal-footer destination-picker-footer"><button id="cancelDestinationModal" type="button" class="btn btn-secondary">Cancel</button><button id="useDestination" type="button" class="btn btn-primary" disabled><i class="fas fa-check"></i> Use this destination</button></div>
    </div>
</div>
@endif

<div id="submitRequestModal" class="modal-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="submitRequestTitle">
    <div class="modal-card request-modal"><div class="modal-header"><div><h2 id="submitRequestTitle" class="modal-title">Submit requested document</h2><p id="submitRequestSubtitle"></p></div><button type="button" class="modal-close" data-close-modal><i class="fas fa-times"></i></button></div>
        <form id="requestSubmitForm" method="POST" enctype="multipart/form-data">@csrf<div class="modal-body"><label class="request-dropzone" for="requestFile"><i class="fas fa-cloud-arrow-up"></i><strong>Choose a file to submit</strong><span id="requestFileHint">Maximum 10 MB</span><input id="requestFile" type="file" name="file" required></label><div id="duplicateWarning" class="duplicate-warning" hidden></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-close-modal>Cancel</button><button class="btn btn-primary" type="submit"><i class="fas fa-upload"></i> Submit document</button></div></form>
    </div>
</div>

<div id="changesRequestModal" class="modal-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="changesRequestTitle"><div class="modal-card request-modal"><div class="modal-header"><div><h2 id="changesRequestTitle" class="modal-title">Request changes</h2><p id="changesRequestSubtitle"></p></div><button type="button" class="modal-close" data-close-modal><i class="fas fa-times"></i></button></div><form id="changesRequestForm" method="POST">@csrf<input type="hidden" name="decision" value="changes_requested"><div class="modal-body"><label class="form-label">What needs to be corrected? *</label><textarea class="form-control" name="review_note" maxlength="1000" rows="4" required></textarea></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-close-modal>Cancel</button><button class="btn btn-primary">Send correction request</button></div></form></div></div>
@endsection

@push('scripts')
<script>
document.querySelectorAll('[data-open-modal]').forEach(function (button) {
    button.addEventListener('click', function () {
        var modal = document.getElementById(this.dataset.openModal);
        if (this.classList.contains('request-submit-trigger')) {
            document.getElementById('requestSubmitForm').action = @json(url('/document-request-recipients')) + '/' + this.dataset.recipient + '/submit';
            document.getElementById('submitRequestSubtitle').textContent = this.dataset.title;
            var accept = {pdf:'.pdf',word:'.doc,.docx',image:'.jpg,.jpeg,.png,.gif,.webp',any:'.pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp'};
            document.getElementById('requestFile').accept = accept[this.dataset.type] || accept.any;
        }
        if (this.classList.contains('request-changes-trigger')) {
            document.getElementById('changesRequestForm').action = @json(url('/document-request-recipients')) + '/' + this.dataset.recipient + '/review';
            document.getElementById('changesRequestSubtitle').textContent = 'Send clear feedback to ' + this.dataset.name + '.';
        }
        modal.hidden = false; modal.classList.add('active');
        if (modal.querySelector('.request-people-list')) { refreshRequestPeople(); updateRequestDueMinimum(); }
    });
});
document.querySelectorAll('[data-close-modal]').forEach(function (button) { button.addEventListener('click', function () { var modal = this.closest('.modal-overlay'); modal.classList.remove('active'); modal.hidden = true; }); });
var refreshingRequestPeople = false;
function updateRecipientSummary() {
    var selected = Array.from(document.querySelectorAll('.request-people-list input:checked'));
    var count = document.getElementById('requestSelectedCount');
    if (!count) return;
    count.textContent = selected.length ? selected.length + ' recipient' + (selected.length === 1 ? '' : 's') + ' selected' : 'No recipients selected';
    document.getElementById('requestSelectedNames').textContent = selected.length ? selected.map(function (input) { return input.closest('label').querySelector('strong').textContent; }).join(', ') : 'Choose who should submit this document.';
    if (selected.length) document.getElementById('requestRecipientsError').hidden = true;
    document.getElementById('doneRequestRecipients').disabled = !selected.length;
}
function setRecipientPicker(open) {
    document.getElementById('requestRecipientPicker').hidden = !open;
    var button = document.getElementById('editRequestRecipients');
    button.setAttribute('aria-expanded', String(open));
    button.textContent = open ? 'Selecting recipients' : 'Change recipients';
    if (open) document.getElementById('requestPeopleSearch').focus();
    else button.focus();
}
document.getElementById('doneRequestRecipients')?.addEventListener('click', function () { updateRecipientSummary(); setRecipientPicker(false); });
document.getElementById('editRequestRecipients')?.addEventListener('click', function () { setRecipientPicker(true); });
var requestClockStart = Date.now();
var requestServerTime = @json(now()->getTimestampMs());
function updateRequestDueMinimum() {
    var input = document.getElementById('requestDueAt');
    if (!input) return;
    var parts = new Intl.DateTimeFormat('en-CA', { timeZone: @json(config('app.timezone')), year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date(requestServerTime + Date.now() - requestClockStart + 60000));
    var values = Object.fromEntries(parts.map(function (part) { return [part.type, part.value]; }));
    input.min = values.year + '-' + values.month + '-' + values.day + 'T' + values.hour + ':' + values.minute;
}
function validateRequestDue() {
    var input = document.getElementById('requestDueAt');
    if (!input) return true;
    updateRequestDueMinimum();
    var invalid = Boolean(input.value && input.value < input.min);
    var error = document.getElementById('requestDueError');
    error.hidden = !invalid;
    error.textContent = invalid ? 'This deadline has already passed or is too soon. Choose a later date and time, or clear it for no deadline.' : '';
    input.setAttribute('aria-invalid', String(invalid));
    input.setCustomValidity(invalid ? error.textContent : '');
    return !invalid;
}
document.getElementById('requestDueAt')?.addEventListener('input', validateRequestDue);
document.getElementById('requestDueAt')?.addEventListener('invalid', validateRequestDue);
function filterRequestPeople() {
    var search = document.querySelector('.request-people-search');
    if (!search) return;
    var q = search.value.toLowerCase().trim();
    var rows = Array.from(document.querySelectorAll('.request-people-list label'));
    rows.forEach(function (row) { row.hidden = !row.dataset.search.includes(q); });
    document.querySelector('.request-people-list').scrollTop = 0;
    document.getElementById('requestPeopleStatus').textContent = rows.filter(function (row) { return !row.hidden; }).length + ' matching recipients' + (q ? ' — clear search to see all eligible employees.' : '');
}
async function refreshRequestPeople() {
    if (refreshingRequestPeople) return;
    refreshingRequestPeople = true;
    var button = document.getElementById('refreshRequestPeople');
    var status = document.getElementById('requestPeopleStatus');
    var list = document.querySelector('.request-people-list');
    button.disabled = true; status.textContent = 'Refreshing recipients…';
    try {
        var response = await fetch(@json(route('document-requests.recipients')), { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        if (!response.ok) throw new Error('Failed to load recipients');
        var data = await response.json();
        if (!Array.isArray(data.people)) throw new Error('Invalid recipient response');
        var selected = new Set(Array.from(list.querySelectorAll('input:checked')).map(function (input) { return input.value; }));
        var fragment = document.createDocumentFragment();
        data.people.forEach(function (person) {
            var row = document.createElement('label');
            row.dataset.search = [person.name, person.username, person.program, person.role].join(' ').toLowerCase();
            var checkbox = document.createElement('input');
            checkbox.type = 'checkbox'; checkbox.name = 'recipient_ids[]'; checkbox.value = person.id;
            checkbox.dataset.courseIds = JSON.stringify(person.course_ids); checkbox.checked = selected.has(String(person.id));
            var text = document.createElement('span'); var name = document.createElement('strong'); var detail = document.createElement('small');
            name.textContent = person.name; detail.textContent = person.role + ' · ' + person.program;
            text.append(name, detail); row.append(checkbox, text); fragment.append(row);
        });
        list.replaceChildren(fragment); filterRequestPeople(); updateRecipientSummary(); updateRequestCourseChoices();
    } catch (error) {
        status.textContent = 'Could not refresh recipients. The previous list is still shown. Click Refresh recipients to retry.';
    } finally { button.disabled = false; refreshingRequestPeople = false; }
}
document.querySelector('.request-people-search')?.addEventListener('input', filterRequestPeople);
document.getElementById('refreshRequestPeople')?.addEventListener('click', refreshRequestPeople);
document.querySelector('.request-people-list')?.addEventListener('change', function (event) { if (event.target.matches('input[name="recipient_ids[]"]')) { updateRecipientSummary(); updateRequestCourseChoices(); } });

var requestCourses = @json($courses->mapWithKeys(fn ($course) => [(string) $course->id => $course->code.' — '.$course->title]));
var requestCategorySelect = document.getElementById('requestCategory');
var destinationCourse = document.getElementById('destinationCourse');
var destinationSchoolYear = document.getElementById('destinationSchoolYear');
var destinationSemester = document.getElementById('destinationSemester');
var destinationModal = document.getElementById('destinationPickerModal');
var courseIdInput = document.getElementById('requestCourseId');
var schoolYearIdInput = document.getElementById('requestSchoolYearId');
var semesterInput = document.getElementById('requestSemesterValue');
var destinationTypeInput = document.getElementById('requestDestinationType');
var examPeriodInput = document.getElementById('requestExamPeriod');
var generalSchoolYear = document.getElementById('generalSchoolYear');
var generalSemester = document.getElementById('generalSemester');
var draftDestinationType = destinationTypeInput?.value || '';
var draftExamPeriod = examPeriodInput?.value || '';

function updateRequestCourseChoices() {
    if (!destinationCourse) return;

    var recipients = Array.from(document.querySelectorAll('.request-people-list input[name="recipient_ids[]"]:checked'));
    var previousValue = destinationCourse.value || destinationCourse.dataset.selectedCourse || courseIdInput.value || '';
    var commonCourseIds = [];

    if (recipients.length) {
        commonCourseIds = JSON.parse(recipients[0].dataset.courseIds || '[]').map(String);
        recipients.slice(1).forEach(function (recipient) {
            var assignedIds = JSON.parse(recipient.dataset.courseIds || '[]').map(String);
            commonCourseIds = commonCourseIds.filter(function (courseId) { return assignedIds.includes(courseId); });
        });
    }

    destinationCourse.replaceChildren(new Option(recipients.length ? 'Select course' : 'Select a recipient first', ''));
    commonCourseIds.forEach(function (courseId) {
        if (requestCourses[courseId]) destinationCourse.add(new Option(requestCourses[courseId], courseId));
    });
    destinationCourse.value = commonCourseIds.includes(String(previousValue)) ? String(previousValue) : '';
    destinationCourse.dataset.selectedCourse = '';

    if (courseIdInput.value && !commonCourseIds.includes(String(courseIdInput.value))) clearCommittedDestination();

    var hint = document.getElementById('requestCourseHint');
    if (!hint) return;
    if (!recipients.length) {
        hint.textContent = 'Return to the request form and select at least one recipient first.';
    } else if (!commonCourseIds.length) {
        hint.textContent = recipients.length > 1
            ? 'The selected recipients have no assigned course in common.'
            : 'This recipient has no assigned courses.';
    } else {
        hint.textContent = recipients.length > 1
            ? 'Only courses assigned to every selected recipient are shown.'
            : 'Only courses assigned to this recipient are shown.';
    }
}

function clearCommittedDestination() {
    if (!courseIdInput) return;
    courseIdInput.value = '';
    schoolYearIdInput.value = '';
    semesterInput.value = '';
    destinationTypeInput.value = '';
    examPeriodInput.value = '';
    draftDestinationType = '';
    draftExamPeriod = '';
    document.getElementById('requestDestinationPath').textContent = 'No destination selected';
    document.getElementById('openDestinationModal').innerHTML = '<i class="fas fa-folder-tree"></i> Choose destination';
}

function updateChoiceButtons() {
    document.querySelectorAll('[data-exam-period]').forEach(function (button) {
        var selected = button.dataset.examPeriod === draftExamPeriod;
        button.classList.toggle('is-selected', selected);
        button.setAttribute('aria-pressed', selected ? 'true' : 'false');
    });
    document.querySelectorAll('[data-destination-type]').forEach(function (button) {
        var selected = button.dataset.destinationType === draftDestinationType;
        button.classList.toggle('is-selected', selected);
        button.setAttribute('aria-pressed', selected ? 'true' : 'false');
    });
}

function destinationSummary() {
    var category = requestCategorySelect?.value;
    var course = destinationCourse?.value;
    var year = destinationSchoolYear?.value;
    var semester = destinationSemester?.value;
    var needsExamPeriod = category === 'exam_questionnaire';
    var validType = category === 'teaching_guide'
        ? ['tg', 'lb'].includes(draftDestinationType)
        : ['tos', 'toq'].includes(draftDestinationType);
    var complete = Boolean(course && year && semester && validType && (!needsExamPeriod || draftExamPeriod));
    if (!complete) return null;

    var categoryLabel = category === 'teaching_guide' ? 'Teaching Guides' : 'Exam Questionnaires';
    var yearLabel = destinationSchoolYear.options[destinationSchoolYear.selectedIndex].text;
    var semesterLabel = destinationSemester.options[destinationSemester.selectedIndex].text;
    var parts = [categoryLabel, semesterLabel + ' · ' + yearLabel, requestCourses[course]];
    if (needsExamPeriod) parts.push(draftExamPeriod.charAt(0).toUpperCase() + draftExamPeriod.slice(1));
    parts.push(draftDestinationType.toUpperCase());
    return parts.join(' › ');
}

function updateDestinationDraftSummary() {
    var summary = destinationSummary();
    document.getElementById('destinationDraftSummary').textContent = summary || 'Complete the choices above.';
    document.getElementById('useDestination').disabled = !summary;
    updateChoiceButtons();
}

function configureDestinationModal() {
    var isExam = requestCategorySelect?.value === 'exam_questionnaire';
    document.getElementById('examPeriodStep').hidden = !isExam;
    document.getElementById('teachingGuideTypes').hidden = isExam;
    document.getElementById('examQuestionnaireTypes').hidden = !isExam;
    document.getElementById('documentTypeStepNumber').textContent = isExam ? '4' : '3';
    if (isExam && !['tos', 'toq'].includes(draftDestinationType)) draftDestinationType = '';
    if (!isExam && !['tg', 'lb'].includes(draftDestinationType)) draftDestinationType = '';
    if (!isExam) draftExamPeriod = '';
    updateDestinationDraftSummary();
}

function openDestinationPicker() {
    updateRequestCourseChoices();
    destinationCourse.value = courseIdInput.value || destinationCourse.value || '';
    destinationSchoolYear.value = schoolYearIdInput.value || destinationSchoolYear.value || '';
    destinationSemester.value = semesterInput.value || destinationSemester.value || '';
    draftDestinationType = destinationTypeInput.value || '';
    draftExamPeriod = examPeriodInput.value || '';
    configureDestinationModal();
    destinationModal.hidden = false;
    destinationModal.classList.add('active');
    window.setTimeout(function () { destinationCourse.focus(); }, 0);
}

function closeDestinationPicker() {
    destinationModal?.classList.remove('active');
    if (destinationModal) destinationModal.hidden = true;
    document.getElementById('openDestinationModal')?.focus();
}

function applyRequestCategoryState() {
    if (!requestCategorySelect) return;
    var isGeneral = requestCategorySelect.value === 'general';
    var documentType = document.getElementById('requestDocumentType');
    document.getElementById('generalRequestPeriod').hidden = !isGeneral;
    document.getElementById('requestDestinationGroup').hidden = isGeneral;
    Array.from(documentType.options).forEach(function (option) { option.disabled = !isGeneral && ['any', 'image'].includes(option.value); });

    if (isGeneral) {
        document.getElementById('generalCategoryGroup').hidden = false;
        clearCommittedDestination();
        schoolYearIdInput.value = generalSchoolYear?.value || '';
        semesterInput.value = generalSemester?.value || '';
        document.getElementById('requestCategoryHint').textContent = 'Files use the selected general category and display their request origin.';
    } else {
        document.getElementById('generalCategoryGroup').hidden = true;
        document.getElementById('generalCategory').value = '';
        clearCommittedDestination();
        if (!['pdf', 'word'].includes(documentType.value)) documentType.value = 'pdf';
        document.getElementById('requestCategoryHint').textContent = 'Choose the filing details in a separate guided window.';
    }
}

requestCategorySelect?.addEventListener('change', applyRequestCategoryState);
document.getElementById('generalCategory')?.addEventListener('change', function () {
    document.getElementById('requestCategoryHint').textContent = 'Files will be filed under ' + this.options[this.selectedIndex].text + ' and display their request origin.';
});
destinationCourse?.addEventListener('change', updateDestinationDraftSummary);
destinationSchoolYear?.addEventListener('change', updateDestinationDraftSummary);
destinationSemester?.addEventListener('change', updateDestinationDraftSummary);
document.querySelectorAll('[data-exam-period]').forEach(function (button) { button.addEventListener('click', function () { draftExamPeriod = button.dataset.examPeriod; updateDestinationDraftSummary(); }); });
document.querySelectorAll('[data-destination-type]').forEach(function (button) { button.addEventListener('click', function () { draftDestinationType = button.dataset.destinationType; updateDestinationDraftSummary(); }); });
document.getElementById('openDestinationModal')?.addEventListener('click', openDestinationPicker);
document.getElementById('closeDestinationModal')?.addEventListener('click', closeDestinationPicker);
document.getElementById('cancelDestinationModal')?.addEventListener('click', closeDestinationPicker);
destinationModal?.addEventListener('click', function (event) { if (event.target === destinationModal) closeDestinationPicker(); });
document.getElementById('useDestination')?.addEventListener('click', function () {
    var summary = destinationSummary();
    if (!summary) return;
    courseIdInput.value = destinationCourse.value;
    schoolYearIdInput.value = destinationSchoolYear.value;
    semesterInput.value = destinationSemester.value;
    destinationTypeInput.value = draftDestinationType;
    examPeriodInput.value = draftExamPeriod;
    document.getElementById('requestDestinationPath').textContent = summary;
    document.getElementById('openDestinationModal').innerHTML = '<i class="fas fa-pen"></i> Change destination';
    closeDestinationPicker();
});
generalSchoolYear?.addEventListener('change', function () { schoolYearIdInput.value = this.value; });
generalSemester?.addEventListener('change', function () { semesterInput.value = this.value; });
document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && destinationModal && !destinationModal.hidden) closeDestinationPicker(); });
updateRequestCourseChoices();
if (generalSchoolYear) generalSchoolYear.value = schoolYearIdInput?.value || '';
if (generalSemester) generalSemester.value = semesterInput?.value || '';
if (requestCategorySelect && requestCategorySelect.value === 'general') {
    document.getElementById('generalRequestPeriod').hidden = false;
    document.getElementById('requestDestinationGroup').hidden = true;
} else if (requestCategorySelect) {
    document.getElementById('generalRequestPeriod').hidden = true;
    document.getElementById('requestDestinationGroup').hidden = false;
    if (courseIdInput.value && schoolYearIdInput.value && semesterInput.value && destinationTypeInput.value) {
        destinationCourse.value = courseIdInput.value;
        draftDestinationType = destinationTypeInput.value;
        draftExamPeriod = examPeriodInput.value;
        var restoredSummary = destinationSummary();
        if (restoredSummary) {
            document.getElementById('requestDestinationPath').textContent = restoredSummary;
            document.getElementById('openDestinationModal').innerHTML = '<i class="fas fa-pen"></i> Change destination';
        }
    }
}
if (document.getElementById('requestSelectedCount')) updateRecipientSummary();

@if($errors->any() && old('request_category') !== null)
var createRequestModal = document.getElementById('createRequestModal');
if (createRequestModal) {
    createRequestModal.hidden = false;
    createRequestModal.classList.add('active');
    document.getElementById('requestDueError')?.closest('.form-group').querySelector('[aria-invalid="true"]')?.focus();
}
@endif

document.getElementById('createDocumentRequestForm')?.addEventListener('submit', function (event) {
    if (!validateRequestDue()) { event.preventDefault(); document.getElementById('requestDueAt').reportValidity(); return; }
    if (!document.querySelector('.request-people-list input:checked')) { event.preventDefault(); setRecipientPicker(true); document.getElementById('requestRecipientsError').hidden = false; return; }
    if (requestCategorySelect?.value !== 'general' && !destinationSummary()) {
        event.preventDefault();
        openDestinationPicker();
        return;
    }
    var button = this.querySelector('button[type="submit"]');
    if (!button || button.disabled) return;
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';
});

document.getElementById('requestFile')?.addEventListener('change', async function () {
    var warning = document.getElementById('duplicateWarning'); warning.hidden = true;
    if (!this.files[0] || !crypto.subtle) return;
    var hash = Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', await this.files[0].arrayBuffer()))).map(function (b) { return b.toString(16).padStart(2, '0'); }).join('');
    var response = await fetch(@json(route('document-search.duplicate')), {method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify({hash:hash})});
    if (response.ok) { var data = await response.json(); if (data.duplicate) { warning.innerHTML = '<i class="fas fa-triangle-exclamation"></i><span>This appears to duplicate <strong></strong>. You may still submit if a new copy was requested.</span>'; warning.querySelector('strong').textContent = data.document; warning.hidden = false; } }
});
document.getElementById('requestSubmitForm')?.addEventListener('submit', function (event) {
    if (this.dataset.submitting === '1') { event.preventDefault(); return; }
    this.dataset.submitting = '1';
    var button = this.querySelector('button[type="submit"]');
    if (button) { button.disabled = true; button.textContent = 'Submitting…'; }
});
</script>
@endpush
