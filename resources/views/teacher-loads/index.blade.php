@extends('layouts.dashboard')

@section('title', 'Teacher’s Load')
@section('page-title', 'Teacher’s Load')
@section('page-subtitle', $manager ? 'Prepare faculty workloads and export the registrar form' : 'View and download your finalized teaching loads')

@section('sidebar')
    @include(auth()->user()->hasOversightAccess() ? 'partials.dean-sidebar' : (auth()->user()->isProgramCoordinator() ? 'partials.coordinator-sidebar' : 'partials.faculty-sidebar'))
@endsection

@push('styles')
    @vite('resources/css/teacher-loads.css')
@endpush

@section('content')
<section id="teacher-load-workspace" class="tl-workspace"
         data-base="{{ route('teacher-loads.index') }}"
         data-selected-employee="{{ $manager ? ($filters['employee_id'] ?? '') : '' }}"
         data-selected-semester="{{ $filters['semester'] ?? \App\Support\SchoolTerm::current() }}"
         data-options="{{ route('teacher-loads.options') }}"
         data-preview="{{ route('teacher-loads.preview') }}">
    <header class="tl-toolbar">
        <div><h2>Teaching loads</h2><p>One record per faculty or program coordinator, school year, and semester.</p></div>
        @if($manager)
            <div class="flex gap-2 flex-wrap">
            @if(($filters['view'] ?? '') === 'drafts')<a class="btn btn-secondary" href="{{ route('teacher-loads.index', \Illuminate\Support\Arr::except($filters, ['view'])) }}">All loads</a>@endif
            <a class="btn btn-secondary" href="{{ route('teacher-loads.index', array_merge($filters, ['view' => 'drafts'])) }}" @if(($filters['view'] ?? '') === 'drafts') aria-current="page" @endif>Drafts ({{ $draftCount }})</a>
            <button type="button" class="btn btn-primary" id="tl-create"><i class="fas fa-plus"></i> Create Teacher’s Load</button>
            </div>
        @endif
    </header>

    @php $activeLoadFilters = collect(['program', 'school_year_id', 'semester', 'employee_id'])->filter(fn ($key) => filled($filters[$key] ?? null))->count(); @endphp
    <button type="button" class="btn tl-filters-toggle" aria-controls="tl-filters" aria-expanded="{{ $activeLoadFilters ? 'true' : 'false' }}">
        <i class="fas fa-sliders-h"></i> Filters @if($activeLoadFilters)<span class="tl-filters-count">{{ $activeLoadFilters }}</span>@endif
    </button>
    <form class="tl-filters {{ $activeLoadFilters ? '' : 'is-collapsed-mobile' }}" id="tl-filters" method="GET">
        @if(($filters['view'] ?? '') === 'drafts')<input type="hidden" name="view" value="drafts">@endif
        @if(auth()->user()->hasOversightAccess())
        <label>Program<select name="program" class="form-control"><option value="">All programs</option>
            @foreach(\App\Models\Program::codes() as $code)<option value="{{ $code }}" @selected(($filters['program'] ?? '') === $code)>{{ $code }}</option>@endforeach
        </select></label>
        @endif
        <label>School year<select name="school_year_id" class="form-control"><option value="">All school years</option>
            @foreach($years as $year)<option value="{{ $year->id }}" @selected(($filters['school_year_id'] ?? '') == $year->id)>{{ $year->name }}</option>@endforeach
        </select></label>
        <label>Semester<select name="semester" class="form-control"><option value="">All semesters</option>
            @foreach(\App\Support\SchoolTerm::labels() as $value => $label)<option value="{{ $value }}" @selected(($filters['semester'] ?? '') === $value)>{{ $label }}</option>@endforeach
        </select></label>
        @if($manager)
        <label>Faculty / Program Coordinator<select name="employee_id" class="form-control"><option value="">All</option>
            @foreach($faculty as $person)
                <option value="{{ $person->employee_id }}" @selected(($filters['employee_id'] ?? '') == $person->employee_id)>
                    {{ $person->full_name }} — {{ $person->program }}{{ $person->has_assigned_courses ? '' : ' · No courses assigned yet' }}
                </option>
            @endforeach
        </select></label>
        @endif
        <button class="btn btn-primary" type="submit">Apply filters</button>
        <a href="{{ route('teacher-loads.index') }}" class="btn">Reset</a>
    </form>

    @if($teachingAssignments)
        @include('partials.teaching-assignment-summary', [
            'assignments' => $teachingAssignments,
            'headingId' => 'load-teaching-heading',
            'manageSubjectsUrl' => $manageSubjectsUrl,
        ])
    @endif

    <div class="tl-table-wrap"><table class="data-table">
        <thead><tr><th>Name</th><th>Program</th><th>School year / semester</th><th>Teaching units</th><th>Load equivalent</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        @forelse($loads as $load)
            <tr>
                <td>{{ $load->faculty_name }}</td><td>@include('partials.program-name', ['program' => $load->program])</td>
                <td>{{ $load->academic_year }} · {{ \App\Support\SchoolTerm::label($load->semester) }}</td>
                <td>{{ rtrim(rtrim(number_format((float)$load->total_units, 2, '.', ''), '0'), '.') }}</td>
                <td>{{ rtrim(rtrim(number_format((float)$load->total_load, 3, '.', ''), '0'), '.') }}</td>
                <td><span class="tl-status {{ $load->status === 'finalized' ? 'tl-final' : '' }}">{{ ucfirst($load->status) }}</span>
                    <small>Revision {{ $load->revision_number }}</small>
                    @if($manager && $load->revision_draft !== null)<span class="badge badge-warning">Revision in progress</span>@endif
                    @if($load->void_reason)<p class="tl-note">{{ $load->void_reason }}</p>@endif
                </td>
                <td><div class="tl-actions">
                    @if($manager && !$load->schoolYear->isArchived())
                        @if($load->status === 'draft' || $load->revision_draft !== null)<button type="button" class="btn" data-edit-load="{{ $load->id }}">{{ $load->revision_draft !== null ? 'Edit revision' : 'Edit draft' }}</button>@endif
                        @foreach($load->status === 'draft' ? ['delete-draft' => 'Delete draft'] : ($load->revision_draft !== null ? ['discard' => 'Discard revision'] : ($load->status === 'finalized' ? ['revise' => 'Revise', 'void' => 'Void'] : ['revise' => 'Prepare replacement'])) as $action => $label)
                            <button type="button" class="btn btn-secondary" data-load-change="{{ $action }}" data-load-id="{{ $load->id }}" data-load-version="{{ $load->lock_version }}" data-load-label="{{ $label }}">{{ $label }}</button>
                        @endforeach
                    @endif
                    @if($load->status !== 'draft')<a class="btn" href="{{ route('teacher-loads.history', $load) }}">History</a>@endif
                    <a class="btn" href="{{ route('teacher-loads.pdf', ['id' => $load->id, 'inline' => 1]) }}" target="_blank" rel="noopener">View PDF</a>
                    <a class="btn" href="{{ route('teacher-loads.pdf', $load->id) }}"><i class="fas fa-download"></i> Download</a>
                </div></td>
            </tr>
        @empty
            <tr><td colspan="7" class="tl-empty">{{ ($filters['view'] ?? '') === 'drafts' ? 'No drafts or open revisions match these filters.' : ($manager ? 'No teaching loads found. Create a load to get started.' : 'No finalized teaching loads available yet.') }}</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div class="tl-pagination">{{ $loads->links() }}</div>
</section>

@if($manager)
<dialog id="tl-action-dialog" class="ui-dialog" aria-labelledby="tl-action-title">
    <form method="POST" id="tl-action-form" class="ui-dialog__form" data-request-guard>
        @csrf
        <input type="hidden" name="action" id="tl-action-name">
        <input type="hidden" name="lock_version" id="tl-action-version">
        <header class="ui-dialog__head"><h2 class="ui-dialog__title" id="tl-action-title">Update teaching load</h2><button type="button" class="btn btn-secondary" data-close-load-action aria-label="Close">&times;</button></header>
        <div class="ui-dialog__body"><p id="tl-action-description">Published history is retained.</p><label for="tl-action-reason" class="form-label">Reason *</label><textarea id="tl-action-reason" name="reason" class="form-control" rows="3" minlength="5" maxlength="1000" required></textarea></div>
        <footer class="ui-dialog__foot"><button type="button" class="btn btn-secondary" data-close-load-action>Cancel</button><button type="submit" class="btn btn-primary" id="tl-action-confirm">Confirm</button></footer>
    </form>
</dialog>
<dialog id="tl-dialog" class="tl-dialog" aria-labelledby="tl-dialog-title">
    <header class="tl-modal-head"><div><h2 id="tl-dialog-title">Create Teacher’s Load</h2><p>Prepare one faculty workload for an academic term.</p></div><button type="button" class="tl-icon" id="tl-close" aria-label="Close">×</button></header>
    <ol class="tl-steps" aria-label="Form progress">
        <li data-step-label="0" aria-current="step"><button type="button" class="tl-step-btn" data-go-step="0"><span>1</span> Faculty &amp; term</button></li>
        <li data-step-label="1"><button type="button" class="tl-step-btn" data-go-step="1"><span>2</span> Teaching details</button></li>
        <li data-step-label="2"><button type="button" class="tl-step-btn" data-go-step="2"><span>3</span> Review &amp; export</button></li>
    </ol>
    <div class="tl-error" id="tl-error" role="alert" tabindex="-1" hidden></div>
    <div id="tl-discard" class="tl-discard" hidden><p>You have unsaved changes. Discard them and close?</p><div><button type="button" class="btn btn-primary" id="tl-keep">Keep editing</button><button type="button" class="btn" id="tl-discard-confirm">Discard changes</button></div></div>
    <div id="tl-context-confirm" class="tl-discard tl-context-confirm" role="alertdialog" aria-labelledby="tl-context-title" hidden>
        <div>
            <p id="tl-context-title"><strong>Some teaching rows don’t match the new selection.</strong> These subjects aren’t assigned to the chosen person for that semester and would be removed:</p>
            <ul id="tl-context-rows"></ul>
        </div>
        <div><button type="button" class="btn" id="tl-context-cancel">Keep previous selection</button><button type="button" class="btn btn-primary" id="tl-context-apply">Remove these rows</button></div>
    </div>
    <div class="tl-modal-body" id="tl-scroll"><fieldset id="tl-fields">
        <section data-step="0">
            <div class="tl-form-grid">
                <label class="tl-wide tl-faculty-picker">Faculty / Program Coordinator *
                    <input type="search" id="tl-faculty-search" class="form-control tl-faculty-search" placeholder="Search by name, program, or role..." autocomplete="off" aria-controls="tl-faculty">
                    <select id="tl-faculty" class="form-control" required size="1">
                    <option value="">Choose faculty or program coordinator</option>
                    @foreach($faculty as $person)
                        <option value="{{ $person->employee_id }}"
                                data-program="{{ $person->program }}"
                                data-role="{{ $person->role_label }}"
                                data-has-courses="{{ $person->has_assigned_courses ? '1' : '0' }}">
                            {{ $person->full_name }} — {{ $person->program }} · {{ $person->role_label }}{{ $person->has_assigned_courses ? '' : ' · No courses assigned yet' }}
                        </option>
                    @endforeach
                </select></label>
                <label>School year *<select id="tl-year" class="form-control" required><option value="">Choose school year</option>
                    @foreach($years->whereNull('archived_at') as $year)<option value="{{ $year->id }}" @selected($year->is_active)>{{ $year->name }}</option>@endforeach
                </select></label>
                <label>Semester *<select id="tl-semester" class="form-control">@foreach(\App\Support\SchoolTerm::labels() as $value => $label)<option value="{{ $value }}" @selected($value === \App\Support\SchoolTerm::current())>{{ $label }}</option>@endforeach</select></label>
                <label>Faculty type *<select id="tl-employment" class="form-control">@foreach(\App\Models\Employee::FACULTY_TYPES as $label)<option>{{ $label }}</option>@endforeach</select></label>
                <div class="tl-readonly"><span>Department / Program</span><strong id="tl-program">SITE / —</strong><small>Read from the selected profile</small></div>
            </div>
            <p class="tl-note">Includes program coordinators who also teach. Available subjects still come from their assigned courses for the selected semester.</p>
            <p id="tl-faculty-tag" class="tl-note tl-faculty-tag" hidden aria-live="polite"></p>
        </section>

        <section data-step="1" hidden>
            <div class="tl-section-head"><div><h3>Teaching details</h3><p>Add one row per subject and section.</p></div><button type="button" class="btn btn-primary" id="tl-add-course">+ Add subject</button></div>
            <p id="tl-course-notice" class="tl-note" aria-live="polite"></p>
            <button type="button" class="btn btn-secondary" id="tl-add-assigned" hidden>Add assigned subjects</button>
            <div id="tl-course-rows"></div>
        </section>

        <section data-step="2" hidden>
            <div class="tl-section-head"><div><h3>Additional duties</h3><p>Optional duties such as program coordination.</p></div><button type="button" class="btn" id="tl-add-duty">+ Add duty</button></div>
            <div id="tl-duty-rows"></div>
            <div class="tl-totals"><div><span>Teaching units</span><strong id="tl-units">0</strong></div><div><span>Load equivalent</span><strong id="tl-total">0</strong></div></div>
            <p class="tl-note">Load equivalent is entered manually. The system adds the values without assuming an institutional formula.</p>
            <div id="tl-review" class="tl-review" aria-live="polite"></div>
            <div class="tl-section-head"><h3>Registrar form</h3><button type="button" class="btn" id="tl-preview">Preview PDF</button></div>
            <p class="tl-note" id="tl-preview-note">Preview the complete form before finalizing.</p>
            <iframe id="tl-pdf-frame" title="Teacher’s Load PDF preview" hidden></iframe>
            <a id="tl-preview-link" target="_blank" rel="noopener" hidden>Open PDF preview in a new tab</a>
            <label class="tl-confirm"><input type="checkbox" id="tl-confirm-final"> I reviewed this load. Finalizing makes it read-only and visible to the faculty.</label>
        </section>
    </fieldset></div>
    <footer class="tl-modal-footer"><span id="tl-save-state" role="status" aria-live="polite">Unsaved draft</span><div class="tl-actions"><button type="button" class="btn" id="tl-back" disabled><i class="fas fa-arrow-left" aria-hidden="true"></i> Back</button><button type="button" class="btn" id="tl-save">Save draft</button><button type="button" class="btn btn-primary" id="tl-next">Next <i class="fas fa-arrow-right" aria-hidden="true"></i></button><button type="button" class="btn btn-primary" id="tl-finalize" hidden disabled>Finalize load</button></div></footer>
</dialog>

<template id="tl-course-template"><article class="tl-item"><div class="tl-section-head"><h4>Subject &amp; section</h4><button type="button" class="tl-remove btn">Remove</button></div><div class="tl-form-grid">
    <label class="tl-wide">Assigned subject *<select data-field="course_id" class="form-control" required></select></label>
    <label>Section *<select data-field="section" class="form-control" required><option value="">Choose section</option></select><small>Manage choices under Course Catalog → Sections.</small></label>
    <label>Class size *<input data-field="class_size" class="form-control" type="number" min="1" max="9999" required></label>
    <div class="tl-numbers tl-wide"><label>Lecture units *<input data-field="lecture_units" type="number" min="0" max="99" step="0.01" class="form-control" value="0" readonly required></label><label>Lab units *<input data-field="lab_units" type="number" min="0" max="99" step="0.01" class="form-control" value="0" readonly required></label><label>Load equivalent *<input data-field="load_equivalent" type="number" min="0" max="999" step="0.001" class="form-control" value="0" required></label></div>
    <div class="tl-units-bar tl-wide"><small class="tl-units-hint" data-units-hint>Choose a subject to fill units from the Course Catalog.</small><button type="button" class="tl-units-edit" data-units-edit aria-pressed="false"><i class="fas fa-pencil-alt" aria-hidden="true"></i> <span>Edit units</span></button></div>
    </div><div class="tl-section-head tl-schedule-head"><h4>Schedule</h4><button type="button" class="btn tl-add-schedule">+ Add schedule</button></div><div class="tl-schedules"></div></article></template>

<template id="tl-schedule-template"><div class="tl-meeting"><fieldset class="tl-days"><legend>Days *</legend>@foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $day)<label><input type="checkbox" value="{{ $day }}">{{ $day }}</label>@endforeach</fieldset><div class="tl-meeting-fields"><label>Start *<input type="time" data-meeting="start" class="form-control" required></label><label>End *<input type="time" data-meeting="end" class="form-control" required></label><label>Type<select data-meeting="mode" class="form-control"><option>Lec</option><option>Lab</option></select></label><label>Room *<input data-meeting="room" class="form-control" maxlength="40" required></label><button type="button" class="btn tl-remove-schedule">Remove</button></div></div></template>

<template id="tl-duty-template"><article class="tl-item tl-duty"><div class="tl-form-grid"><label>Additional duty *<input data-field="title" class="form-control" maxlength="150" placeholder="e.g. Program Coordinator — BSIT" required></label><label>Load equivalent *<input data-field="load_equivalent" class="form-control" type="number" min="0" max="999" step="0.001" value="0" required></label></div><button type="button" class="btn tl-remove">Remove duty</button></article></template>
@endif
@endsection

@push('scripts')
    @vite('resources/js/teacher-loads.js')
@endpush
