@php
    $documentsRoute = $documentsRoute ?? 'faculty.documents';
    $folderFilter = $folderFilter ?? null;
    $tab = $tab ?? request('tab', 'accreditation');
    $uploaders = $uploaders ?? collect();
    $savedFilters = $savedFilters ?? collect();
    $searchDepartments = $searchDepartments ?? collect();
    $searchCourses = $searchCourses ?? collect();
    $searchSchoolYears = $searchSchoolYears ?? collect();
    $search = request('search', request('name', ''));
    $scope = request('scope', 'folder');
    $filterKeys = ['uploaded_by','program','course_id','school_year_id','semester','status','file_type','date_from','date_to','managed_category_id'];
    $activeCount = collect($filterKeys)->filter(fn ($key) => request()->filled($key))->count();
    $showFilters = $activeCount > 0;
@endphp

<div class="card-header card-header--documents unified-doc-search">
    <div class="unified-doc-search__main">
        <form action="{{ route($documentsRoute) }}" method="GET" class="unified-doc-search__form" role="search">
            <input type="hidden" name="tab" value="{{ $tab }}">
            @if($folderFilter)<input type="hidden" name="folder" value="{{ $folderFilter }}">@endif
            <label class="sr-only" for="documentUnifiedSearch">Search document titles and file contents</label>
            <i class="fas fa-search" aria-hidden="true"></i>
            <input id="documentUnifiedSearch" name="search" type="search" value="{{ $search }}" placeholder="Search titles, subjects, PDF and Word content…" maxlength="150">
            <select name="scope" aria-label="Search scope">
                <option value="folder" @selected($scope === 'folder')>Current folder + subfolders</option>
                <option value="all" @selected($scope === 'all')>All accessible documents</option>
                <option value="archives" @selected($scope === 'archives')>Archives</option>
            </select>
            <button type="submit" class="btn btn-primary">Search</button>
        </form>
        <button type="button" class="doc-filter-button {{ $showFilters ? 'is-active' : '' }}" id="docFilterToggle" aria-expanded="{{ $showFilters ? 'true' : 'false' }}" aria-controls="docAdvancedFilters">
            <i class="fas fa-sliders-h"></i> Filters @if($activeCount)<span>{{ $activeCount }}</span>@endif
        </button>
        <select class="doc-clean-sort" aria-label="Sort documents" onchange="if(this.value) window.location=this.value">
            <option value="">Sort</option>
            @foreach(['date'=>'Newest','title'=>'Name','size'=>'Size','author'=>'Uploader'] as $value=>$label)
                <option value="{{ route($documentsRoute, array_merge(request()->query(), ['sort'=>$value])) }}" @selected(request('sort', 'date') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <span class="badge badge-info">{{ $documents->total() }} {{ $documents->total() === 1 ? 'file' : 'files' }}</span>
</div>

<div id="docAdvancedFilters" class="doc-advanced-filters {{ $showFilters ? '' : 'is-collapsed' }}">
    <form action="{{ route($documentsRoute) }}" method="GET" class="unified-filter-grid">
        <label>Custom category<select name="managed_category_id"><option value="">All accessible categories</option>@foreach(\App\Models\DocumentCategory::accessibleTo(auth()->user())->orderBy('category_name')->get() as $filterCategory)<option value="{{ $filterCategory->category_id }}" @selected((string)request('managed_category_id') === (string)$filterCategory->category_id)>{{ $filterCategory->category_name }}{{ $filterCategory->owner_id ? ' (Personal)' : '' }}{{ $filterCategory->is_active ? '' : ' (Inactive)' }}</option>@endforeach</select></label>
        <input type="hidden" name="tab" value="{{ $tab }}">
        @if($folderFilter)<input type="hidden" name="folder" value="{{ $folderFilter }}">@endif
        <input type="hidden" name="scope" value="{{ $scope }}">
        @if($search !== '')<input type="hidden" name="search" value="{{ $search }}">@endif
        <label>Uploaded by<select name="uploaded_by"><option value="">Anyone I can access</option>@foreach($uploaders as $uploader)<option value="{{ $uploader->id }}" @selected((string)request('uploaded_by') === (string)$uploader->id)>{{ $uploader->employee->full_name ?? $uploader->username }}</option>@endforeach</select></label>
        @if(auth()->user()->isDeanOrSecretary())
        <label>Program<select name="program"><option value="">All programs</option>@foreach($searchDepartments as $department)<option value="{{ $department }}" @selected(request('program') === $department)>{{ $department }}</option>@endforeach</select></label>
        @endif
        <label>Course<select name="course_id"><option value="">All accessible courses</option>@foreach($searchCourses as $course)<option value="{{ $course->id }}" @selected((string)request('course_id') === (string)$course->id)>{{ $course->code }} — {{ $course->title }}</option>@endforeach</select></label>
        <label>School year<select name="school_year_id"><option value="">All school years</option>@foreach($searchSchoolYears as $year)<option value="{{ $year->id }}" @selected((string)request('school_year_id') === (string)$year->id)>{{ $year->name ?? ($year->start_year.'–'.$year->end_year) }}</option>@endforeach</select></label>
        <label>Semester<select name="semester"><option value="">All semesters</option><option value="1st Semester" @selected(request('semester') === '1st Semester')>1st Semester</option><option value="2nd Semester" @selected(request('semester') === '2nd Semester')>2nd Semester</option></select></label>
        <label>Compliance status<select name="status"><option value="">All statuses</option>@foreach(['pending'=>'Pending','submitted'=>'Submitted','approved'=>'Approved','changes_requested'=>'Changes requested'] as $value=>$label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select></label>
        <label>File type<select name="file_type"><option value="">All types</option><option value="pdf" @selected(request('file_type') === 'pdf')>PDF</option><option value="word" @selected(request('file_type') === 'word')>Word</option><option value="image" @selected(request('file_type') === 'image')>Image</option></select></label>
        <label>Uploaded from<input type="date" name="date_from" value="{{ request('date_from') }}"></label>
        <label>Uploaded to<input type="date" name="date_to" value="{{ request('date_to') }}"></label>
        <div class="unified-filter-actions"><a href="{{ route($documentsRoute, array_filter(['tab'=>$tab,'folder'=>$folderFilter])) }}" class="btn btn-secondary">Clear all</a><button class="btn btn-primary" type="submit">Apply filters</button></div>
    </form>
</div>

@if($activeCount || $search !== '')
<div class="doc-active-search-summary"><span><strong>{{ $documents->total() }}</strong> accessible {{ $documents->total() === 1 ? 'result' : 'results' }}</span>@if($search !== '')<span class="doc-filter-chip">Search: {{ $search }}</span>@endif @if($scope === 'all')<span class="doc-filter-chip">All documents</span>@endif @foreach($filterKeys as $key) @if(request()->filled($key))<span class="doc-filter-chip">{{ str($key)->replace('_',' ')->title() }}</span>@endif @endforeach</div>
@endif

<details class="doc-saved-searches">
    <summary><i class="fas fa-bookmark"></i> Saved searches @if($savedFilters->isNotEmpty())<span>{{ $savedFilters->count() }}</span>@endif</summary>
    <div class="doc-saved-searches__panel">
        @forelse($savedFilters as $savedFilter)
            <span class="doc-saved-preset-chip-wrap">
                <a class="doc-saved-preset-chip" href="{{ route($documentsRoute, $savedFilter->toQueryParams()) }}">{{ $savedFilter->name }}</a>
                <form action="{{ route('document-filters.destroy', $savedFilter->document_filter_id) }}" method="POST">@csrf @method('DELETE')<button aria-label="Delete {{ $savedFilter->name }}">×</button></form>
            </span>
        @empty
            <span class="text-muted">No saved searches yet.</span>
        @endforelse
        @if($activeCount || $search !== '')
        <form action="{{ route('document-filters.store') }}" method="POST" class="doc-save-search-inline">
            @csrf
            @foreach(request()->query() as $key=>$value) @if(is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach
            <input name="name" required maxlength="50" placeholder="Name this search">
            <button class="btn btn-primary" type="submit">Save</button>
        </form>
        @endif
    </div>
</details>

@push('scripts')
<script>document.getElementById('docFilterToggle')?.addEventListener('click',function(){const p=document.getElementById('docAdvancedFilters');const c=p.classList.toggle('is-collapsed');this.setAttribute('aria-expanded',c?'false':'true');});</script>
@endpush
