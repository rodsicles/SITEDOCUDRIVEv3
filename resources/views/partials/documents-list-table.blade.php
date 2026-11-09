@php
    $routePrefix = $routePrefix ?? 'faculty';
    $user = auth()->user();
    $documentService = app(\App\Services\DocumentService::class);
    $foldersListUrl = route($routePrefix . '.folders.list');
    $favoriteRoute = $routePrefix . '.toggle-favorite';
    $canFavorite = \Illuminate\Support\Facades\Route::has($favoriteRoute);
    $docsListRoute = $routePrefix . '.documents';
    $hasListFilters = request()->filled('search')
        || request()->filled('uploaded_by')
        || request()->filled('date_from')
        || request()->filled('date_to')
        || request()->filled('file_type');
    $isScopedList = $isScopedList ?? false;
    $compactEmpty = $compactEmpty ?? false;
    $documentRows = $isScopedList ? collect($documents) : $documents;
    $canMove = \Illuminate\Support\Facades\Route::has($routePrefix . '.documents.move');
@endphp

<div class="documents-list-table-wrap">
<table class="data-table data-table--responsive" id="documentsListTable">
    <thead>
        <tr>
            <th>Document</th>
            <th>Uploaded by</th>
            <th>Date</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        @forelse($documentRows as $document)
        @php
            $extension = strtolower(pathinfo($document->file_path, PATHINFO_EXTENSION));
            $canRename = $documentService->userCanRenameDocument($document, $user);
            $canDelete = $user->isDeanOrSecretary() || (int) $document->uploaded_by === (int) $user->id;
            $canCopy   = $document->canView($user);
            $isFavorited = $canFavorite && $document->isFavoritedBy($user->id);
            $sizeBytes = (int) ($document->file_size ?? 0);
            $sizeLabel = $sizeBytes >= 1048576
                ? number_format($sizeBytes / 1048576, 1).' MB'
                : ($sizeBytes >= 1024 ? number_format($sizeBytes / 1024, 1).' KB' : ($sizeBytes ? $sizeBytes.' B' : strtoupper($extension ?: 'file')));
            $typeLabel = $document->getRelationValue('category')?->category_name ?? $document->category
                ?: ($document->document_type === 'pdf' ? 'PDF' : ($document->document_type === 'word' ? 'Word' : ($document->document_type === 'image' ? 'Image' : strtoupper($extension ?: 'File'))));
            $requestSubmission = $document->requestSubmissions()->with(['request.requester.employee', 'request.requester.role'])->first();
            $requesterRole = $requestSubmission?->request?->requester?->role?->role_name;
            $requestOriginLabel = match($requesterRole) {
                'Dean' => 'Requested by Dean',
                'Program Coordinator' => 'Requested by Coordinator',
                default => $requestSubmission ? 'Requested document' : null,
            };
        @endphp
        <tr>
            <td data-label="Document">
                <div class="doc-title-block">
                    <strong class="doc-title-text" id="doc-title-text-{{ $document->document_id }}">{{ $document->document_title }}</strong>
                    <span class="doc-title-meta">{{ $typeLabel }} · {{ $sizeLabel }}</span>
                    @if($requestOriginLabel)<span class="requested-origin-badge" title="{{ $requestSubmission->request->title }} · {{ $requestSubmission->request->requester->employee->full_name ?? $requestSubmission->request->requester->username }}"><i class="fas fa-clipboard-check"></i>{{ $requestOriginLabel }}</span>@endif
                    @if(!empty($document->search_excerpt))
                    <span class="doc-search-excerpt">{!! preg_replace('/('.preg_quote(request('search'), '/').')/iu', '<mark>$1</mark>', e($document->search_excerpt)) !!}</span>
                    @elseif(request('search') && optional($document->searchIndex)->index_status === 'pending')
                    <span class="doc-index-state"><i class="fas fa-spinner fa-spin"></i> Content indexing in progress</span>
                    @endif
                </div>
            </td>
            <td data-label="Uploaded by">{{ $document->uploader ? ($document->uploader->employee->full_name ?? $document->uploader->username) : 'Unknown' }}</td>
            <td data-label="Date">{{ optional($document->created_at)->format('M j, Y') }}</td>
            <td data-label="Actions" class="doc-action-cell">
                <div class="doc-action-btns archive-row-actions" role="group" aria-label="Document actions">
                    @if($canFavorite)
                    <button type="button"
                            class="btn btn-sm border-0 archive-row-actions__btn doc-favorite-btn {{ $isFavorited ? 'is-favorited' : '' }}"
                            title="{{ $isFavorited ? 'Remove from favorites' : 'Add to favorites' }}"
                            aria-label="{{ $isFavorited ? 'Unfavorite' : 'Favorite' }} {{ $document->document_title }}"
                            data-favorite-url="{{ route($favoriteRoute, $document->document_id) }}"
                            data-favorited="{{ $isFavorited ? '1' : '0' }}">
                        <i class="{{ $isFavorited ? 'fas' : 'far' }} fa-star" aria-hidden="true"></i>
                    </button>
                    @endif
                    <a href="{{ route($routePrefix . '.view-document', $document->document_id) }}"
                       class="btn btn-sm btn-primary border-0"
                       target="_blank"
                       rel="noopener noreferrer">
                        View
                    </a>
                    <a href="{{ route($routePrefix . '.download-document', $document->document_id) }}" class="doc-inline-action" title="Download" aria-label="Download {{ $document->document_title }}">
                        <i class="fas fa-download" aria-hidden="true"></i>
                    </a>
                    @if($canRename)
                    <button type="button" class="doc-inline-action" title="Rename" aria-label="Rename {{ $document->document_title }}" onclick="openRenameDocumentModal({{ $document->document_id }}, @js($document->document_title))">
                        <i class="fas fa-pen" aria-hidden="true"></i>
                    </button>
                    @endif
                    @if($canCopy)
                    <button type="button" class="doc-inline-action" title="Copy" aria-label="Copy {{ $document->document_title }}" onclick="openCopyDocumentModal({{ $document->document_id }}, @js(route($routePrefix.'.documents.copy', $document->document_id)))">
                        <i class="fas fa-copy" aria-hidden="true"></i>
                    </button>
                    @endif
                    @if($canMove)
                    <button type="button" class="doc-inline-action" title="Move" aria-label="Move {{ $document->document_title }}" onclick="openMoveDocumentModal({{ $document->document_id }})">
                        <i class="fas fa-folder-open" aria-hidden="true"></i>
                    </button>
                    @endif
                    @if($canDelete)
                    <form id="delete-doc-{{ $document->document_id }}" action="{{ route($routePrefix . '.delete-document', $document->document_id) }}" method="POST" class="m-0 inline-flex">
                        @csrf @method('DELETE')
                        <button type="button" class="doc-inline-action doc-inline-action--danger" title="Move to Recycle Bin" aria-label="Move {{ $document->document_title }} to Recycle Bin" onclick="confirmDelete({{ $document->document_id }})">
                            <i class="fas fa-recycle" aria-hidden="true"></i>
                        </button>
                    </form>
                    @endif
                    @if($user->isDean())
                    <form id="permanent-delete-doc-{{ $document->document_id }}" action="{{ route('dean.documents.force-delete', $document->document_id) }}" method="POST" class="m-0 inline-flex">
                        @csrf @method('DELETE')
                        <button type="button" class="doc-inline-action doc-inline-action--danger" title="Permanently delete" aria-label="Permanently delete {{ $document->document_title }}" data-impact-url="{{ route('dean.documents.delete-impact', $document->document_id) }}" onclick="confirmPermanentDocument({{ $document->document_id }}, this)"><i class="fas fa-trash-alt" aria-hidden="true"></i></button>
                    </form>
                    @endif
                </div>
            </td>
        </tr>
        @empty
        <tr>
            <td colspan="4" class="{{ $compactEmpty ? 'py-4' : 'py-10' }}">
                <div class="docs-empty-state {{ $compactEmpty ? 'docs-empty-state--compact' : '' }}">
                    @if($compactEmpty && ! $isScopedList && ! $hasListFilters)
                        <p class="docs-empty-state__text">This folder is empty.</p>
                    @else
                    <p class="docs-empty-state__title">{{ $isScopedList ? 'No files in this scope' : 'No documents in this view' }}</p>
                    @if($isScopedList)
                        <p class="docs-empty-state__text">{{ request('browse') === 'favorites' ? 'Star a file in a folder to pin it here.' : 'Open a document to see it in Recent.' }}</p>
                    @elseif($hasListFilters)
                        <p class="docs-empty-state__text">Nothing matches your current filters or search.</p>
                        <a href="{{ route($docsListRoute, array_filter(['tab' => request('tab', 'accreditation'), 'folder' => request('folder')])) }}"
                           class="btn btn-primary text-sm mt-3">
                            <i class="fas fa-rotate-left mr-1" aria-hidden="true"></i> Clear filters
                        </a>
                    @elseif(request('folder'))
                        <p class="docs-empty-state__text">This folder is empty. Upload a file here, or open another folder above.</p>
                    @else
                        <p class="docs-empty-state__text">Open a folder above to browse files, or upload once you are inside a folder.</p>
                    @endif
                    @endif
                </div>
            </td>
        </tr>
        @endforelse
    </tbody>
</table>
</div>

<div class="mt-5">
    @if(!$isScopedList)
        {{ $documents->links() }}
    @endif
</div>

@include('partials.rename-document-modal', ['routePrefix' => $routePrefix])

{{-- ── Copy Document Modal ──────────────────────────────────────────────── --}}
<div id="copyDocumentModalBackdrop" class="copy-doc-modal-backdrop" aria-modal="true" role="dialog">
    <div class="copy-doc-modal">
        <h4><i class="fas fa-copy mr-2 text-[#028a0f]"></i>Copy Document To…</h4>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Choose a destination folder. The file will be duplicated there.</p>
        <select id="copyDocumentFolderSelect" class="form-control">
            <option value="">— Root Documents (no folder) —</option>
        </select>
        <div class="flex gap-3">
            <button type="button" id="copyDocumentConfirmBtn" class="btn btn-primary text-xs">
                <i class="fas fa-copy mr-1"></i> Copy
            </button>
            <button type="button" class="btn bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200 text-xs"
                    onclick="closeCopyDocumentModal()">Cancel</button>
        </div>
        <p id="copyDocumentStatus" class="text-xs mt-2 hidden"></p>
    </div>
</div>

@once
@push('scripts')
<script>
async function confirmPermanentDocument(id, button) {
    const form = document.getElementById('permanent-delete-doc-' + id);
    if (!form) return;
    button.disabled = true;
    let impact;
    try {
        const response = await fetch(button.dataset.impactUrl, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
        if (!response.ok) throw new Error('Could not review document dependencies.');
        impact = await response.json();
    } catch (error) {
        if (typeof Swal !== 'undefined') Swal.fire({title: 'Could not review file', text: error.message, icon: 'error', customClass: {popup: 'swal-flat'}});
        else alert(error.message);
        button.disabled = false;
        return;
    }
    button.disabled = false;
    const message = [
        impact.title,
        'Stored file: ' + impact.stored_file,
        'Saved versions: ' + impact.saved_versions,
        'Request submissions: ' + impact.request_submissions,
        'Teaching guides: ' + impact.teaching_guides,
        'Exam questionnaires: ' + impact.exam_questionnaires,
        'Other records sharing the file: ' + impact.shared_file_records,
        impact.blocked ? 'Permanent deletion is blocked until these links are resolved.' : 'This cannot be undone.'
    ].join('\n');
    if (typeof Swal === 'undefined') {
        if (!impact.blocked && confirm('Permanently delete this document?\n' + message)) form.submit();
        return;
    }
    Swal.fire({
        title: 'Permanently delete this document?', text: message, icon: 'warning',
        showCancelButton: !impact.blocked, showConfirmButton: true,
        confirmButtonText: impact.blocked ? 'Close' : 'Permanently delete', cancelButtonText: 'Cancel',
        confirmButtonColor: '#b42318', customClass: { popup: 'swal-flat' }
    }).then(result => { if (result.isConfirmed && !impact.blocked) form.submit(); });
}
function confirmDelete(id) {
    if (typeof Swal === 'undefined') {
        if (confirm('Move this file to the Recycle Bin?')) {
            document.getElementById('delete-doc-' + id).submit();
        }
        return;
    }

    Swal.fire({
        title: 'Move to Recycle Bin?',
        text: 'This file will be removed from Documents and moved to the Recycle Bin. You can restore it later.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete it',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#6b7280',
        customClass: { popup: 'swal-flat' }
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('delete-doc-' + id).submit();
        }
    });
}

document.addEventListener('click', function (e) {
    var btn = e.target.closest('.doc-favorite-btn');
    if (!btn) return;
    e.preventDefault();
    var url = btn.getAttribute('data-favorite-url');
    if (!url) return;
    btn.disabled = true;
    fetch(url, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (!data || !data.success) return;
        var on = !!data.favorited;
        btn.dataset.favorited = on ? '1' : '0';
        btn.classList.toggle('is-favorited', on);
        btn.title = on ? 'Remove from favorites' : 'Add to favorites';
        var icon = btn.querySelector('i');
        if (icon) {
            icon.classList.toggle('fas', on);
            icon.classList.toggle('far', !on);
        }
        if (typeof showToast === 'function') {
            showToast(data.message || (on ? 'Added to favorites' : 'Removed from favorites'), 'success');
        }
    })
    .catch(function () {})
    .finally(function () { btn.disabled = false; });
});

// ── Copy Document Modal ─────────────────────────────────────────────────
(function () {
    var _copyUrl = '';
    var _foldersUrl = @json($foldersListUrl);

    window.openCopyDocumentModal = function (docId, copyEndpoint) {
        _copyUrl = copyEndpoint;
        var backdrop = document.getElementById('copyDocumentModalBackdrop');
        var select   = document.getElementById('copyDocumentFolderSelect');
        var status   = document.getElementById('copyDocumentStatus');
        if (!backdrop || !select) return;

        select.innerHTML = '<option value="">— Root Documents (no folder) —</option>';
        status.classList.add('hidden');
        status.textContent = '';
        backdrop.classList.add('open');

        fetch(_foldersUrl)
            .then(r => r.json())
            .then(function (data) {
                (data.folders || []).forEach(function (f) {
                    var opt = document.createElement('option');
                    opt.value = f.folder_id;
                    opt.textContent = f.folder_name;
                    select.appendChild(opt);
                });
            })
            .catch(function () {
                status.textContent = 'Could not load folders.';
                status.classList.remove('hidden');
            });
    };

    window.closeCopyDocumentModal = function () {
        var backdrop = document.getElementById('copyDocumentModalBackdrop');
        if (backdrop) backdrop.classList.remove('open');
    };

    document.addEventListener('DOMContentLoaded', function () {
        var backdrop = document.getElementById('copyDocumentModalBackdrop');
        var confirmBtn = document.getElementById('copyDocumentConfirmBtn');
        var status = document.getElementById('copyDocumentStatus');

        if (confirmBtn) {
            confirmBtn.addEventListener('click', function () {
                var select = document.getElementById('copyDocumentFolderSelect');
                var folderId = select ? select.value : '';
                var body = new FormData();
                body.append('_token', document.querySelector('meta[name=csrf-token]')?.content || '');
                if (folderId) body.append('folder_id', folderId);

                confirmBtn.disabled = true;
                confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Copying…';
                status.classList.add('hidden');

                fetch(_copyUrl, { method: 'POST', body: body })
                    .then(r => r.json())
                    .then(function (data) {
                        status.textContent = data.message || 'Copied successfully.';
                        status.classList.remove('hidden');
                        status.style.color = data.success ? '#028a0f' : '#dc2626';
                        if (data.success) {
                            setTimeout(function () { window.closeCopyDocumentModal(); }, 1200);
                        }
                    })
                    .catch(function () {
                        status.textContent = 'An error occurred. Please try again.';
                        status.classList.remove('hidden');
                        status.style.color = '#dc2626';
                    })
                    .finally(function () {
                        confirmBtn.disabled = false;
                        confirmBtn.innerHTML = '<i class="fas fa-copy mr-1"></i> Copy';
                    });
            });
        }

        if (backdrop) {
            backdrop.addEventListener('click', function (e) {
                if (e.target === backdrop) window.closeCopyDocumentModal();
            });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') window.closeCopyDocumentModal();
        });
    });
})();

</script>
@endpush
@endonce
