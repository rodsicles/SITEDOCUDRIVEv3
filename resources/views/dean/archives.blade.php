@extends('layouts.dashboard')

@section('title', 'School Year Archives - Dean')
@section('page-title', 'School Year Archives')
@section('page-subtitle', 'Manage school year archiving')

@section('sidebar')
    @include('partials.dean-sidebar')
@endsection

@section('content')
    {{-- Active School Year --}}
    <div class="content-card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-calendar-alt mr-2"></i>Current School Year</h3>
            <span class="badge badge-success">Active</span>
        </div>
        <div class="p-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">School Year</p>
                    <p class="text-lg font-bold text-gray-800 dark:text-white">{{ $activeSchoolYear->name }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Documents</p>
                    <p class="text-lg font-bold text-gray-800 dark:text-white">{{ $activeDocCount }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Teaching Guides <span class="text-xs">(approved)</span></p>
                    <p class="text-lg font-bold text-gray-800 dark:text-white">{{ $activeTgCount }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Exam Questionnaires <span class="text-xs">(approved)</span></p>
                    <p class="text-lg font-bold text-gray-800 dark:text-white">{{ $activeEqCount }}</p>
                </div>
            </div>

            <div class="mt-6 border-t border-gray-200 dark:border-gray-700 pt-4">
                @if($canManageArchives ?? true)
                <button type="button" class="btn btn-danger border-0" data-dialog-open="archiveModal">
                    <i class="fas fa-archive"></i> Archive This School Year
                </button>
                @else
                <p class="text-sm text-gray-600 dark:text-gray-300"><i class="fas fa-lock mr-1"></i> Archiving the school year is done by the Dean.</p>
                @endif
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                    Documents for this year are archived in full. Only <strong>approved</strong> teaching guides and exam questionnaires are kept in the archive; pending and rejected submissions stay in the active year for review or faculty cleanup.
                </p>
                @if(($pendingTgCount + $pendingEqCount + $rejectedTgCount + $rejectedEqCount) > 0)
                <p class="text-xs text-amber-700 dark:text-amber-300 mt-2">
                    <i class="fas fa-info-circle mr-1"></i>
                    Not archived with this year:
                    @if($pendingTgCount + $pendingEqCount > 0)
                        {{ $pendingTgCount + $pendingEqCount }} pending
                    @endif
                    @if($pendingTgCount + $pendingEqCount > 0 && $rejectedTgCount + $rejectedEqCount > 0)
                        ,
                    @endif
                    @if($rejectedTgCount + $rejectedEqCount > 0)
                        {{ $rejectedTgCount + $rejectedEqCount }} rejected
                    @endif
                    (carried forward to the new school year).
                </p>
                @endif
            </div>
        </div>
    </div>

    @if($allowArchiveHardDelete && $archivedYears->isNotEmpty())
    <div class="content-card border-2 border-red-200 dark:border-red-900/50">
        <div class="card-header bg-red-50 dark:bg-red-900/20">
            <h3 class="card-title text-red-800 dark:text-red-200">
                <i class="fas fa-exclamation-triangle mr-2"></i>Dry-run cleanup — permanent archive delete
            </h3>
        </div>
        <div class="p-4 text-sm text-gray-600 dark:text-gray-300">
            <p>Permanently removes an <strong>archived</strong> school year bucket and all documents, teaching guides, exam questionnaires, exam records, and semester folders tagged to that year. Storage files are deleted. This cannot be undone.</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">Does not remove active-year data, notifications, or dashboard logs. Set <code class="text-xs">ALLOW_ARCHIVE_HARD_DELETE=false</code> when dry runs end.</p>
        </div>
    </div>
    @endif

    {{-- Archived School Years --}}
    <div class="content-card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-box-archive mr-2"></i>Archived School Years</h3>
            <span class="badge badge-info">{{ $archivedYears->count() }} Archives</span>
        </div>
        @if($archivedYears->isEmpty())
            <div class="p-6 text-center text-gray-500 dark:text-gray-400">
                <i class="fas fa-inbox text-4xl mb-3"></i>
                <p>No archived school years yet. When you archive the current school year, it will appear here.</p>
            </div>
        @else
            <table class="data-table">
                <thead>
                    <tr>
                        <th>School Year</th>
                        <th>Archived On</th>
                        <th>Archived By</th>
                        @if($allowArchiveHardDelete)
                        <th>Records</th>
                        @endif
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($archivedYears as $year)
                    <tr>
                        <td><strong>{{ $year->name }}</strong></td>
                        <td>{{ $year->archived_at->format('M d, Y h:i A') }}</td>
                        <td>{{ optional($year->archivedByUser)->employee->full_name ?? optional($year->archivedByUser)->username ?? 'System' }}</td>
                        @if($allowArchiveHardDelete)
                        <td class="text-sm text-gray-600 dark:text-gray-400">
                            {{ $year->documents_count }} doc · {{ $year->teaching_guides_count }} TG · {{ $year->exam_questionnaires_count }} EQ · {{ $year->folders_count }} folders
                        </td>
                        @endif
                        <td class="flex flex-wrap gap-2 relative z-10 whitespace-normal">
                            <a href="{{ route('dean.archives.show', $year->id) }}" class="btn btn-sm btn-primary border-0">
                                <i class="fas fa-eye"></i> Browse
                            </a>
                            @if($canManageArchives ?? true)
                            <button type="button"
                                    class="btn btn-sm btn-success border-0 js-archive-restore-open"
                                    data-restore-url="{{ route('dean.archives.restore', $year) }}"
                                    data-restore-name="{{ $year->name }}">
                                <i class="fas fa-undo"></i> Restore as Active
                            </button>
                            @endif
                            @if($allowArchiveHardDelete)
                            <button type="button"
                                    class="btn btn-sm btn-danger border-0 js-archive-delete-open"
                                    data-delete-url="{{ route('dean.archives.destroy', $year) }}"
                                    data-delete-name="{{ $year->name }}">
                                <i class="fas fa-trash-alt"></i> Delete permanently
                            </button>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Archive dialog --}}
    <dialog id="archiveModal" class="ui-dialog ui-dialog--danger" aria-labelledby="archiveModalTitle" aria-describedby="archiveModalWarning">
        <form action="{{ route('dean.archives.archive') }}" method="POST" id="archiveForm" class="ui-dialog__form" data-request-guard>
            @csrf
            <header class="ui-dialog__head">
                <div>
                    <h2 id="archiveModalTitle" class="ui-dialog__title"><i class="fas fa-archive" aria-hidden="true"></i> Archive school year</h2>
                    <p class="ui-dialog__subtitle">Close {{ $activeSchoolYear->name }} and start a new school year.</p>
                </div>
                <button type="button" class="ui-dialog__close" data-dialog-close aria-label="Close">&times;</button>
            </header>

            <div class="ui-dialog__body">
                <div class="ui-callout" id="archiveModalWarning">
                    <strong><i class="fas fa-exclamation-triangle mr-1" aria-hidden="true"></i> What happens when you archive</strong>
                    <ul>
                        <li>All documents from the current school year are archived.</li>
                        <li>Only <strong>approved</strong> teaching guides and exam questionnaires are archived.</li>
                        <li>Pending and rejected submissions are <strong>not</strong> archived; they stay active for the new school year.</li>
                        <li>User-created folders move to the archive, and the new year starts with empty default folders.</li>
                    </ul>
                </div>

                @if($errors->any() && ! old('confirm_phrase'))
                <div class="ui-dialog__error" role="alert">
                    <ul>
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
                @endif

                <div class="ui-dialog__field">
                    <label for="archive_name">Archive name</label>
                    <input type="text" name="archive_name" id="archive_name" value="{{ old('archive_name', $activeSchoolYear->name) }}" class="form-control" required maxlength="50">
                    <small>Name for the archived school year.</small>
                </div>
                <div class="ui-dialog__field">
                    <label for="new_name">New school year name</label>
                    <input type="text" name="new_name" id="new_name" value="{{ old('new_name', 'S.Y. ' . $suggestedStartYear . '-' . ($suggestedStartYear + 1)) }}" class="form-control" required maxlength="50">
                </div>
                <div class="ui-dialog__field">
                    <label for="new_start_year">New school year start</label>
                    <input type="number" name="new_start_year" id="new_start_year" value="{{ old('new_start_year', $suggestedStartYear) }}" class="form-control" required min="2020" max="2099" inputmode="numeric">
                    <small>The start year of the new school year (e.g. {{ $suggestedStartYear }} for {{ $suggestedStartYear }}-{{ $suggestedStartYear + 1 }}).</small>
                </div>

                <label class="ui-dialog__ack">
                    <input type="checkbox" data-ack-for="archiveSubmitBtn">
                    <span>I understand this closes the current school year and cannot be undone from this screen.</span>
                </label>
                <p class="ui-dialog__note"><i class="fas fa-info-circle mr-1" aria-hidden="true"></i>This may take a few seconds. Click once and don’t refresh the page.</p>
            </div>

            <footer class="ui-dialog__foot">
                <button type="button" class="btn btn-secondary" data-dialog-close>Cancel</button>
                <button type="submit" id="archiveSubmitBtn" class="btn btn-danger border-0" disabled>
                    <i class="fas fa-archive" aria-hidden="true"></i> Confirm archive
                </button>
            </footer>
        </form>
    </dialog>

    @if($allowArchiveHardDelete)
    <dialog id="archiveDeleteModal" class="ui-dialog ui-dialog--danger" aria-labelledby="archiveDeleteTitle">
        <form action="" method="POST" id="archiveDeleteForm" class="ui-dialog__form" data-request-guard data-typed-confirm="DELETE PERMANENTLY">
            @csrf
            <header class="ui-dialog__head">
                <div>
                    <h2 id="archiveDeleteTitle" class="ui-dialog__title"><i class="fas fa-trash-alt" aria-hidden="true"></i> Permanently delete archive</h2>
                    <p class="ui-dialog__subtitle">This cannot be undone.</p>
                </div>
                <button type="button" class="ui-dialog__close" data-dialog-close aria-label="Close">&times;</button>
            </header>

            <div class="ui-dialog__body">
                <div class="ui-callout">
                    This wipes <strong data-year-label></strong> and every document, teaching guide, exam questionnaire, and folder tagged to that archive. Storage files are deleted.
                </div>

                @if($errors->has('confirm_name') || $errors->has('confirm_phrase') || $errors->has('error'))
                <div class="ui-dialog__error" role="alert">
                    <ul>
                        @foreach(['confirm_name', 'confirm_phrase', 'error'] as $field)
                            @error($field)<li>{{ $message }}</li>@enderror
                        @endforeach
                    </ul>
                </div>
                @endif
                <p class="ui-dialog__error" data-inline-error role="alert" hidden></p>

                <div class="ui-dialog__field">
                    <label for="confirm_name">Type the school year name exactly</label>
                    <input type="text" name="confirm_name" id="confirm_name" class="form-control" value="{{ old('confirm_name') }}" autocomplete="off" required maxlength="50">
                </div>
                <div class="ui-dialog__field">
                    <label for="confirm_phrase">Type DELETE PERMANENTLY</label>
                    <input type="text" name="confirm_phrase" id="confirm_phrase" class="form-control" placeholder="DELETE PERMANENTLY" autocomplete="off" required maxlength="50">
                </div>
            </div>

            <footer class="ui-dialog__foot">
                <button type="button" class="btn btn-secondary" data-dialog-close>Cancel</button>
                <button type="submit" class="btn btn-danger border-0">
                    <i class="fas fa-trash-alt" aria-hidden="true"></i> Delete permanently
                </button>
            </footer>
        </form>
    </dialog>
    @endif

    @if($archivedYears->isNotEmpty())
        @include('partials.archive-restore-modal')
    @endif
@endsection

@push('scripts')
<script>
(function () {
    function openDialog(dialog, options) {
        if (!dialog) return;
        options = options || {};
        var form = dialog.querySelector('form');
        if (form && options.url) form.action = options.url;
        if (options.name !== undefined) {
            dialog.dataset.expectedName = options.name || '';
            dialog.querySelectorAll('[data-year-label]').forEach(function (el) { el.textContent = options.name || ''; });
        }
        var inlineError = dialog.querySelector('[data-inline-error]');
        if (inlineError) inlineError.hidden = true;
        if (!dialog.open) dialog.showModal();
        var first = dialog.querySelector('.ui-dialog__body input:not([type=hidden]):not([type=checkbox])');
        if (first) first.focus();
    }

    document.addEventListener('click', function (event) {
        var closer = event.target.closest('[data-dialog-close]');
        if (closer && closer.closest('dialog')) {
            closer.closest('dialog').close();
            return;
        }
        var opener = event.target.closest('[data-dialog-open]');
        if (opener) {
            event.preventDefault();
            openDialog(document.getElementById(opener.getAttribute('data-dialog-open')));
            return;
        }
        var restore = event.target.closest('.js-archive-restore-open');
        if (restore) {
            event.preventDefault();
            openDialog(document.getElementById('archiveRestoreModal'), { url: restore.dataset.restoreUrl, name: restore.dataset.restoreName });
            return;
        }
        var destroy = event.target.closest('.js-archive-delete-open');
        if (destroy) {
            event.preventDefault();
            openDialog(document.getElementById('archiveDeleteModal'), { url: destroy.dataset.deleteUrl, name: destroy.dataset.deleteName });
        }
    });

    document.querySelectorAll('[data-ack-for]').forEach(function (box) {
        var target = document.getElementById(box.getAttribute('data-ack-for'));
        if (!target) return;
        var sync = function () { target.disabled = !box.checked; };
        box.addEventListener('change', sync);
        sync();
    });

    // Typed confirmations are checked inline; browser pop-ups would render behind a modal <dialog>.
    document.querySelectorAll('form[data-typed-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var dialog = form.closest('dialog');
            var expectedName = dialog ? (dialog.dataset.expectedName || '') : '';
            var phrase = form.getAttribute('data-typed-confirm');
            var nameInput = form.querySelector('[name="confirm_name"]');
            var phraseInput = form.querySelector('[name="confirm_phrase"]');
            var error = form.querySelector('[data-inline-error]');
            var message = '';
            if (expectedName && nameInput && nameInput.value.trim() !== expectedName) {
                message = 'The school year name doesn’t match. Type it exactly as shown: ' + expectedName;
                nameInput.focus();
            } else if (phraseInput && phraseInput.value.trim() !== phrase) {
                message = 'Type ' + phrase + ' in capital letters to confirm.';
                phraseInput.focus();
            }
            if (message) {
                event.preventDefault();
                event.stopImmediatePropagation();
                if (error) { error.textContent = message; error.hidden = false; }
            }
        }, true);
    });

    @if($errors->any())
        @php $retryYear = $archivedYears->firstWhere('name', old('confirm_name')); @endphp
        @if(old('confirm_phrase') && str_contains(strtoupper((string) old('confirm_phrase')), 'RESTORE'))
            openDialog(document.getElementById('archiveRestoreModal'), { name: @json(old('confirm_name')), url: @json($retryYear ? route('dean.archives.restore', $retryYear) : null) });
        @elseif(old('confirm_phrase') && str_contains(strtoupper((string) old('confirm_phrase')), 'DELETE'))
            openDialog(document.getElementById('archiveDeleteModal'), { name: @json(old('confirm_name')), url: @json($retryYear && $allowArchiveHardDelete ? route('dean.archives.destroy', $retryYear) : null) });
        @else
            openDialog(document.getElementById('archiveModal'));
        @endif
    @endif
})();
</script>
@endpush
