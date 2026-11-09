{{-- Folder Management Modals --}}
@php
    $folders = $folders ?? collect();
@endphp

{{-- Create Folder Modal --}}
<div id="createFolderModal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="createFolderModalTitle" onclick="if(event.target===this)closeCreateFolderModal()">
    <div class="modal-card" role="document">
        <div class="modal-header">
            <h3 class="modal-title" id="createFolderModalTitle"><i class="fas fa-folder-plus mr-2"></i> Create New Folder</h3>
            <button type="button" class="modal-close" onclick="closeCreateFolderModal()" aria-label="Close create folder dialog"><i class="fas fa-times"></i></button>
        </div>
        <form id="createFolderForm" onsubmit="handleCreateFolder(event)">
            @csrf
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Folder Name *</label>
                    <input type="text" name="folder_name" id="newFolderName" class="form-input" placeholder="Enter folder name" required maxlength="13" pattern="[a-zA-Z0-9\s\-_]+">
                    <small class="text-xs text-gray-500 dark:text-gray-400 mt-1 block">Only letters, numbers, spaces, hyphens, and underscores allowed</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Color</label>
                    <div class="flex gap-2">
                        <input type="color" name="color" id="folderColor" value="#0d5c3b" class="form-input w-20 h-10 p-1 cursor-pointer">
                        <input type="text" value="#0d5c3b" class="form-input flex-1" readonly>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeCreateFolderModal()" class="btn btn-secondary">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-check mr-1"></i> Create Folder
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Rename Folder Modal --}}
<div id="renameFolderModal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="renameFolderModalTitle" onclick="if(event.target===this)closeRenameFolderModal()">
    <div class="modal-card" role="document">
        <div class="modal-header">
            <h3 class="modal-title" id="renameFolderModalTitle"><i class="fas fa-edit mr-2"></i> Rename Folder</h3>
            <button type="button" class="modal-close" onclick="closeRenameFolderModal()" aria-label="Close rename folder dialog"><i class="fas fa-times"></i></button>
        </div>
        <form id="renameFolderForm" onsubmit="handleRenameFolder(event)">
            @csrf
            @method('PATCH')
            <input type="hidden" name="folder_id" id="renameFolderId">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">New Folder Name *</label>
                    <input type="text" name="folder_name" id="renameFolderName" class="form-input" placeholder="Enter new folder name" required maxlength="13" pattern="[a-zA-Z0-9\s\-_]+">
                </div>
                <div class="form-group">
                    <label class="form-label">Color</label>
                    <div class="flex gap-2">
                        <input type="color" name="color" id="renameFolderColor" class="form-input w-20 h-10 p-1 cursor-pointer">
                        <input type="text" id="renameFolderColorText" class="form-input flex-1" readonly>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeRenameFolderModal()" class="btn btn-secondary">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save mr-1"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Move Document Modal --}}
<div id="moveDocumentModal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="moveDocumentModalTitle" onclick="if(event.target===this)closeMoveDocumentModal()">
    <div class="modal-card" role="document">
        <div class="modal-header">
            <h3 class="modal-title" id="moveDocumentModalTitle"><i class="fas fa-arrow-right mr-2"></i> Move to Folder</h3>
            <button type="button" class="modal-close" onclick="closeMoveDocumentModal()" aria-label="Close move document dialog"><i class="fas fa-times"></i></button>
        </div>
        <form id="moveDocumentForm" onsubmit="handleMoveDocument(event)">
            @csrf
            <input type="hidden" name="document_id" id="moveDocumentId">
            <input type="hidden" name="folder_id" id="selectedFolderId">
            <div class="modal-body">
                <div class="mb-4">
                    <button type="button" onclick="showCreateFolderFromMove()" class="btn btn-success w-full">
                        <i class="fas fa-plus mr-1"></i> Create New Folder
                    </button>
                </div>
                <div class="mb-3">
                    <label class="form-label">Select Destination</label>
                </div>
                <div class="folder-list" id="folderListForMove">
                    <div class="folder-list-item" onclick="selectFolderForMove(null, this)">
                        <i class="fas fa-folder-open text-2xl text-gray-400"></i>
                        <div class="flex-1">
                            <div class="font-semibold text-gray-800 dark:text-gray-200">Uncategorized</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">No folder</div>
                        </div>
                    </div>
                    @foreach($folders as $folder)
                    <div class="folder-list-item" onclick="selectFolderForMove({{ $folder->folder_id }}, this)">
                        <i class="fas fa-folder text-2xl" style="color: {{ $folder->color }}"></i>
                        <div class="flex-1">
                            <div class="font-semibold text-gray-800 dark:text-gray-200">{{ $folder->folder_name }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $folder->documents_count }} document(s)</div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeMoveDocumentModal()" class="btn btn-secondary">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-check mr-1"></i> Move Document
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
// Modal Functions
function openCreateFolderModal() {
    const modal = document.getElementById('createFolderModal');
    const input = document.getElementById('newFolderName');
    if (modal) {
        modal.classList.add('active');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }
    if (input) input.focus();
}

function closeCreateFolderModal() {
    const modal = document.getElementById('createFolderModal');
    const form = document.getElementById('createFolderForm');
    if (modal) {
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }
    if (form) form.reset();
}

function openRenameFolderModal(folderId, folderName, folderColor) {
    const modal = document.getElementById('renameFolderModal');
    const idInput = document.getElementById('renameFolderId');
    const nameInput = document.getElementById('renameFolderName');
    const colorInput = document.getElementById('renameFolderColor');
    const colorText = document.getElementById('renameFolderColorText');

    if (idInput) idInput.value = folderId;
    if (nameInput) nameInput.value = folderName;
    if (colorInput) colorInput.value = folderColor;
    if (colorText) colorText.value = folderColor;
    if (modal) {
        modal.classList.add('active');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }
    if (nameInput) nameInput.focus();
}

function closeRenameFolderModal() {
    const modal = document.getElementById('renameFolderModal');
    const form = document.getElementById('renameFolderForm');
    if (modal) {
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }
    if (form) form.reset();
}

function openMoveDocumentModal(documentId) {
    const modal = document.getElementById('moveDocumentModal');
    const idInput = document.getElementById('moveDocumentId');

    if (idInput) idInput.value = documentId;
    if (modal) {
        modal.classList.add('active');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    // Clear previous selection
    document.querySelectorAll('#folderListForMove .folder-list-item').forEach(item => {
        item.classList.remove('selected');
    });
}

function closeMoveDocumentModal() {
    const modal = document.getElementById('moveDocumentModal');
    const folderIdInput = document.getElementById('selectedFolderId');

    if (modal) {
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }
    if (folderIdInput) folderIdInput.value = '';
}

function selectFolderForMove(folderId, element) {
    // Remove selected class from all items
    document.querySelectorAll('#folderListForMove .folder-list-item').forEach(item => {
        item.classList.remove('selected');
    });
    // Add selected class to clicked item
    element.classList.add('selected');
    document.getElementById('selectedFolderId').value = folderId || '';
}

function showCreateFolderFromMove() {
    closeMoveDocumentModal();
    openCreateFolderModal();
}

document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    if (document.getElementById('createFolderModal')?.classList.contains('active')) closeCreateFolderModal();
    if (document.getElementById('renameFolderModal')?.classList.contains('active')) closeRenameFolderModal();
    if (document.getElementById('moveDocumentModal')?.classList.contains('active')) closeMoveDocumentModal();
});

// Folder CRUD Functions
async function handleCreateFolder(event) {
    event.preventDefault();
    const form = event.target;
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalHTML = submitBtn ? submitBtn.innerHTML : '';

    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
    }

    const formData = new FormData(form);

    try {
        const response = await fetch('{{ route(auth()->user()->isDeanOrSecretary() ? "dean.folders.store" : (auth()->user()->isProgramCoordinator() ? "coordinator.folders.store" : "faculty.folders.store")) }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
            body: formData
        });

        const data = await response.json();

        if (response.ok && data.success) {
            showToast('Folder created successfully!', 'success');
            closeCreateFolderModal();
            setTimeout(() => window.location.reload(), 1000);
        } else if (response.status === 429) {
            // Rate limit exceeded
            showToast('Too many folders created! Please wait 1 hour before creating more folders (Limit: 3 folders per hour)', 'error');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalHTML;
            }
        } else {
            // Handle validation errors
            if (data.errors) {
                const firstError = Object.values(data.errors)[0][0];
                showToast(firstError, 'error');
            } else {
                showToast(data.message || 'Failed to create folder', 'error');
            }
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalHTML;
            }
        }
    } catch (error) {
        showToast('An error occurred. Please try again.', 'error');
        console.error('Folder creation error:', error);
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalHTML;
        }
    }
}

async function handleRenameFolder(event) {
    event.preventDefault();
    const form = event.target;
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalHTML = submitBtn ? submitBtn.innerHTML : '';

    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    }

    const formData = new FormData(form);
    const folderIdInput = document.getElementById('renameFolderId');

    if (!folderIdInput || !folderIdInput.value) {
        showToast('Invalid folder ID', 'error');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalHTML;
        }
        return;
    }

    const folderId = folderIdInput.value;

    try {
        const route = '{{ url("/") }}/' + '{{ auth()->user()->isDeanOrSecretary() ? "dean" : (auth()->user()->isProgramCoordinator() ? "coordinator" : "faculty") }}' + '/folders/' + folderId;

        const response = await fetch(route, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'X-HTTP-Method-Override': 'PATCH',
                'Accept': 'application/json',
            },
            body: formData
        });

        const data = await response.json();

        if (response.ok && data.success) {
            showToast('Folder renamed successfully!', 'success');
            closeRenameFolderModal();
            setTimeout(() => window.location.reload(), 1000);
        } else {
            if (data.errors) {
                const firstError = Object.values(data.errors)[0][0];
                showToast(firstError, 'error');
            } else {
                showToast(data.message || 'Failed to rename folder', 'error');
            }
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalHTML;
            }
        }
    } catch (error) {
        showToast('An error occurred. Please try again.', 'error');
        console.error('Folder rename error:', error);
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalHTML;
        }
    }
}

async function deleteFolder(folderId, folderName) {
    if (!confirm(`Delete the folder "${folderName}"?\n\nAll files inside will be moved to the Recycle Bin.`)) {
        return;
    }
    
    try {
        const route = '{{ url("/") }}/' + '{{ auth()->user()->isDeanOrSecretary() ? "dean" : (auth()->user()->isProgramCoordinator() ? "coordinator" : "faculty") }}' + '/folders/' + folderId;
        
        const response = await fetch(route, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            }
        });
        
        const data = await response.json();
        
        if (data.success) {
            showToast('Folder deleted successfully!', 'success');
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showToast(data.message || 'Failed to delete folder', 'error');
        }
    } catch (error) {
        showToast('An error occurred', 'error');
        console.error(error);
    }
}

async function handleMoveDocument(event) {
    event.preventDefault();
    const form = event.target;
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalHTML = submitBtn ? submitBtn.innerHTML : '';

    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Moving...';
    }

    const docIdInput = document.getElementById('moveDocumentId');
    const folderIdInput = document.getElementById('selectedFolderId');

    if (!docIdInput || !docIdInput.value) {
        showToast('Invalid document ID', 'error');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalHTML;
        }
        return;
    }

    const documentId = docIdInput.value;
    const folderId = folderIdInput ? folderIdInput.value : '';

    try {
        const route = '{{ url("/") }}/' + '{{ auth()->user()->isDeanOrSecretary() ? "dean" : (auth()->user()->isProgramCoordinator() ? "coordinator" : "faculty") }}' + '/documents/' + documentId + '/move';

        const response = await fetch(route, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ folder_id: folderId || null })
        });

        const data = await response.json();

        if (data.success) {
            showToast(data.message, 'success');
            closeMoveDocumentModal();
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showToast(data.message || 'Failed to move document', 'error');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalHTML;
            }
        }
    } catch (error) {
        showToast('An error occurred', 'error');
        console.error(error);
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalHTML;
        }
    }
}

// Color picker sync
document.getElementById('folderColor')?.addEventListener('input', (e) => {
    e.target.nextElementSibling.value = e.target.value;
});

document.getElementById('renameFolderColor')?.addEventListener('input', (e) => {
    document.getElementById('renameFolderColorText').value = e.target.value;
});

// Close modals on Escape key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeCreateFolderModal();
        closeRenameFolderModal();
        closeMoveDocumentModal();
    }
});
</script>
@endpush
