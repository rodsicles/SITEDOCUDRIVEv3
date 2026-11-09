@php
    $managedCategories = \App\Models\DocumentCategory::accessibleTo(auth()->user())->orderBy('category_name')->get()->filter(fn ($item) => $item->canManage(auth()->user()));
@endphp
<div id="categoryManagerModal" class="modal-overlay destination-picker-modal" hidden role="dialog" aria-modal="true" aria-labelledby="categoryManagerTitle">
    <div class="modal-card destination-picker-card document-upload-card">
        <div class="modal-header destination-picker-header"><div><h2 id="categoryManagerTitle" class="modal-title">Manage Categories</h2><p>Organize documents without changing existing categories.</p></div><button type="button" class="modal-close" data-category-close aria-label="Close categories"><i class="fas fa-times" aria-hidden="true"></i></button></div>
        <div class="modal-body destination-picker-body">
            <section id="categoryManagerList">
                <button type="button" class="btn btn-primary mb-3" id="addManagedCategory"><i class="fas fa-plus" aria-hidden="true"></i> Add Category</button>
                @forelse($managedCategories as $item)
                    <div class="upload-picker-selection"><div><strong>{{ $item->category_name }}</strong><p class="text-xs">{{ $item->owner_id ? 'Personal · Only you' : 'System-wide' }} · {{ $item->is_active ? 'Active' : 'Inactive' }}</p></div><button type="button" class="btn btn-sm btn-secondary" data-category-edit="{{ $item->category_id }}">Edit</button></div>
                @empty
                    <p class="text-sm">No custom categories yet. Add one to get started.</p>
                @endforelse
                <p class="text-xs">Built-in categories are protected. Deactivating a category preserves its files and removes it from new upload choices.</p>
            </section>
            <form id="categoryManagerForm" action="{{ route('document-categories.store') }}" method="POST" hidden>
                @csrf
                <input type="hidden" name="_method" id="categoryManagerMethod" value="POST">
                <input type="hidden" name="category_form_id" id="categoryFormId" value="new">
                @if($errors->has('category_name') || $errors->has('description') || $errors->has('scope'))<p role="alert">{{ $errors->first() }}</p>@endif
                <div class="form-group"><label class="form-label" for="managedCategoryName">Category name *</label><input id="managedCategoryName" name="category_name" class="form-control" maxlength="80" required placeholder="e.g. Research Outputs"></div>
                <div class="form-group"><label class="form-label" for="managedCategoryDescription">Description <span class="text-xs">(optional)</span></label><textarea id="managedCategoryDescription" name="description" class="form-control" maxlength="500" rows="3"></textarea></div>
                <div class="form-group" id="categoryScopeGroup">
                    @if(auth()->user()->hasOversightAccess())
                    <label class="form-label" for="managedCategoryScope">Visibility *</label><select name="scope" id="managedCategoryScope" class="form-control"><option value="personal">Personal — only me</option><option value="system">System-wide — available to all roles</option></select>
                    <p class="text-xs mt-2">File access still follows existing permissions. Visibility cannot be changed after creation.</p>
                    @else
                    <input type="hidden" name="scope" id="managedCategoryScope" value="personal"><p class="text-xs">This category and its files are personal. Only you can access them.</p>
                    @endif
                </div>
                <div id="categoryStatusGroup" class="form-group" hidden><label for="managedCategoryStatus" class="form-label">Status</label><select id="managedCategoryStatus" name="is_active" class="form-control"><option value="1">Active</option><option value="0">Inactive — keep existing documents</option></select></div>
            </form>
        </div>
        <div class="modal-footer destination-picker-footer"><button type="button" class="btn btn-secondary" id="categoryManagerBack" hidden>Back</button><button type="button" class="btn btn-secondary" data-category-close>Close</button><button class="btn btn-primary" type="submit" form="categoryManagerForm" id="categoryManagerSave" hidden>Save Category</button></div>
    </div>
</div>
@push('scripts')
<script>
(() => {
    const modal = document.getElementById('categoryManagerModal');
    const get = id => document.getElementById(id);
    const categories = @json($managedCategories->values());
    const createUrl = @json(route('document-categories.store'));
    let previousOverflow = '';
    function open() { previousOverflow = document.body.style.overflow; document.body.style.overflow = 'hidden'; modal.hidden = false; modal.classList.add('active'); get('addManagedCategory').focus(); }
    function close() { modal.hidden = true; modal.classList.remove('active'); document.body.style.overflow = previousOverflow; get('openCategoryManager').focus(); }
    function editor(item) {
        const editing = !!item;
        get('categoryManagerList').hidden = true; get('categoryManagerForm').hidden = false;
        get('categoryManagerSave').hidden = false; get('categoryManagerBack').hidden = false;
        get('categoryManagerTitle').textContent = editing ? 'Edit Category' : 'Add Category';
        get('categoryManagerForm').action = editing ? createUrl + '/' + item.category_id : createUrl;
        get('categoryManagerMethod').value = editing ? 'PATCH' : 'POST';
        get('categoryFormId').value = editing ? item.category_id : 'new';
        get('managedCategoryName').value = item?.category_name || '';
        get('managedCategoryDescription').value = item?.description || '';
        get('categoryScopeGroup').hidden = editing; get('categoryStatusGroup').hidden = !editing;
        get('managedCategoryStatus').value = item?.is_active === false ? '0' : '1';
        get('managedCategoryName').focus();
    }
    get('openCategoryManager').addEventListener('click', open);
    get('addManagedCategory').addEventListener('click', () => editor(null));
    modal.querySelectorAll('[data-category-edit]').forEach(button => button.addEventListener('click', () => editor(categories.find(item => String(item.category_id) === button.dataset.categoryEdit))));
    modal.querySelectorAll('[data-category-close]').forEach(button => button.addEventListener('click', close));
    get('categoryManagerBack').addEventListener('click', () => { get('categoryManagerList').hidden = false; get('categoryManagerForm').hidden = true; get('categoryManagerSave').hidden = true; get('categoryManagerBack').hidden = true; get('categoryManagerTitle').textContent = 'Manage Categories'; get('addManagedCategory').focus(); });
    modal.addEventListener('click', event => { if (event.target === modal) close(); });
    modal.addEventListener('keydown', event => {
        if (event.key === 'Escape') close();
        if (event.key === 'Tab') {
            const items = [...modal.querySelectorAll('button,input,select,textarea')].filter(item => !item.disabled && item.getClientRects().length);
            if (event.shiftKey && document.activeElement === items[0]) { event.preventDefault(); items.at(-1)?.focus(); }
            else if (!event.shiftKey && document.activeElement === items.at(-1)) { event.preventDefault(); items[0]?.focus(); }
        }
    });
    @if(old('category_form_id'))
    open(); editor(categories.find(item => String(item.category_id) === @json(old('category_form_id'))) || null);
    get('managedCategoryName').value = @json(old('category_name', ''));
    get('managedCategoryDescription').value = @json(old('description', ''));
    get('managedCategoryScope').value = @json(old('scope', 'personal'));
    get('managedCategoryStatus').value = @json(old('is_active', '1'));
    @endif
})();
</script>
@endpush
