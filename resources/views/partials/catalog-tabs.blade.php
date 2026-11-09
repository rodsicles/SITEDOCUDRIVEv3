@if(auth()->user()->hasOversightAccess() || auth()->user()->isProgramCoordinator())
<nav class="ui-segmented mb-4" aria-label="Catalog management">
    <a href="{{ route($routePrefix.'.courses') }}" class="{{ request('view') !== 'sections' ? 'is-active' : '' }}" @if(request('view') !== 'sections') aria-current="page" @endif>All Courses</a>
    <a href="{{ route($routePrefix.'.courses', ['view' => 'sections']) }}" class="{{ request('view') === 'sections' ? 'is-active' : '' }}" @if(request('view') === 'sections') aria-current="page" @endif>Sections</a>
</nav>
@endif
