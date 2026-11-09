@extends('layouts.dashboard')

@section('title', 'Activity Log')

@section('page-title', 'Activity Log')
@section('page-subtitle', 'Searchable, filterable record of every system action')

@section('sidebar')
    @include('partials.dean-sidebar')
@endsection

@section('content')

    @php
        $hasFilters = !empty(array_filter($filters ?? []));
    @endphp

    <div class="content-card">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-history mr-2 text-[#028a0f] dark:text-[#02b815]"></i>
                Activity Log
                <span class="badge badge-info ml-2">{{ number_format($logs->total()) }} records</span>
            </h3>
            @if(auth()->user()->hasOversightAccess())
                <div class="flex flex-wrap items-center gap-2">
                    @if($logReset ?? null)
                        <a href="{{ ($showHistory ?? false) ? route('dean.audit-trail') : route('dean.audit-trail', ['history' => 1]) }}" class="btn btn-secondary">{{ ($showHistory ?? false) ? 'Current log' : 'View earlier records' }}</a>
                    @endif
                    @if(auth()->user()->isDean())
                    <form id="reset-activity-form" method="POST" action="{{ route('dean.audit-trail.reset') }}">@csrf
                        <button type="button" class="btn btn-danger" @disabled(!($activityResetReady ?? false)) onclick="confirmResetActivity()">Reset Activity Log</button>
                    </form>
                    @endif
                </div>
            @endif
        </div>
        @if(!($activityResetReady ?? false) && auth()->user()->isDean())
            <p class="px-4 py-2 text-sm text-amber-700">The Activity Log reset migration is pending.</p>
        @endif
        @if(($logReset ?? null) && !($showHistory ?? false))
            <p class="px-4 py-2 text-sm text-gray-500">Showing activity since {{ $logReset->reset_at->format('M j, Y g:i A') }}. Earlier records remain available in historical view.</p>
        @endif

        {{-- Filter form --}}
        <form method="GET" action="{{ route('dean.audit-trail') }}"
              class="audit-filter-panel">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-3">

                <div class="form-group mb-0 lg:col-span-2">
                    <label class="form-label">Search</label>
                    <input type="text" name="q" value="{{ $filters['q'] ?? '' }}"
                           class="form-control" placeholder="Name, activity text, or IP">
                </div>

                <div class="form-group mb-0">
                    <label class="form-label">Action Type</label>
                    <select name="activity_type" class="form-control">
                        <option value="">All Types</option>
                        @foreach($activityTypes as $type)
                            <option value="{{ $type }}" @selected(($filters['activity_type'] ?? '') === $type)>
                                {{ ucfirst(str_replace('_', ' ', $type)) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="form-group mb-0">
                    <label class="form-label">User</label>
                    <select name="user_id" class="form-control">
                        <option value="">All Users</option>
                        @foreach($users as $u)
                            <option value="{{ $u->id }}" @selected((string)($filters['user_id'] ?? '') === (string)$u->id)>
                                {{ $u->employee->full_name ?? $u->username }}
                                @if($u->role) ({{ $u->role->role_name }}) @endif
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="form-group mb-0">
                    <label class="form-label" for="audit-actor-role">Actions by</label>
                    <select id="audit-actor-role" name="actor_role" class="form-control">
                        <option value="">All roles</option>
                        @foreach($actorRoles ?? [] as $roleName)
                            <option value="{{ $roleName }}" @selected(($filters['actor_role'] ?? '') === $roleName)>{{ $roleName }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="form-group mb-0">
                    <label class="form-label">From</label>
                    <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="form-control" min="{{ (date('Y') - 1) . '-01-01' }}" max="{{ date('Y') . '-12-31' }}">
                </div>

                <div class="form-group mb-0">
                    <label class="form-label">To</label>
                    <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="form-control" min="{{ (date('Y') - 1) . '-01-01' }}" max="{{ date('Y') . '-12-31' }}">
                </div>
            </div>

            <div class="flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary border-0">
                    <i class="fas fa-filter mr-1"></i> Apply Filters
                </button>
                @if($hasFilters)
                    <a href="{{ route('dean.audit-trail') }}" class="btn btn-secondary border-0">
                        <i class="fas fa-times mr-1"></i> Clear
                    </a>
                @endif
            </div>
        </form>

        {{-- Results table --}}
        <div class="overflow-x-auto">
            <table class="data-table compact audit-table">
                <thead>
                    <tr>
                        <th class="audit-col-date">Date &amp; Time</th>
                        <th class="audit-col-user">User</th>
                        <th class="audit-col-role">Role</th>
                        <th>Action</th>
                        <th class="audit-col-type">Type</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        <tr>
                            <td class="text-xs text-gray-600 dark:text-gray-400 whitespace-nowrap">
                                @if($log->log_date)
                                    {{ $log->log_date->timezone(config('app.timezone'))->format('M d, Y') }}<br>
                                    <span class="text-gray-400 dark:text-gray-500">{{ $log->log_date->timezone(config('app.timezone'))->format('g:i:s A') }}</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                <strong class="text-sm text-gray-800 dark:text-gray-200">
                                    {{ optional(optional($log->user)->employee)->full_name ?? optional($log->user)->username ?? 'System' }}
                                </strong>
                                @if($log->targetUser)
                                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                        <i class="fas fa-arrow-right mr-0.5"></i>
                                        {{ optional($log->targetUser->employee)->full_name ?? $log->targetUser->username }}
                                    </div>
                                @endif
                            </td>
                            <td>
                                <span class="text-xs text-gray-600 dark:text-gray-400">
                                    {{ optional(optional($log->user)->role)->role_name ?? '—' }}
                                </span>
                            </td>
                            <td class="text-sm text-gray-700 dark:text-gray-300 break-words">
                                {{ $log->activity }}
                            </td>
                            <td>
                                @if($log->activity_type)
                                    <span class="badge badge-neutral text-[0.7rem]">
                                        {{ ucfirst(str_replace('_', ' ', $log->activity_type)) }}
                                    </span>
                                @else
                                    <span class="text-gray-400 dark:text-gray-500 text-xs">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-10 text-gray-500 dark:text-gray-400">
                                <i class="fas fa-shield-alt text-3xl mb-2 block text-gray-300 dark:text-gray-600"></i>
                                No audit records match your filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($logs->hasPages())
            <div class="mt-4 px-2">
                {{ $logs->links() }}
            </div>
        @endif
    </div>
@push('scripts')
<script>
function confirmResetActivity() {
    const form = document.getElementById('reset-activity-form');
    if (!form) return;
    const message = 'Start a fresh Activity Log view from now? Earlier records remain available through View earlier records.';
    if (typeof Swal === 'undefined') { if (confirm(message)) form.submit(); return; }
    Swal.fire({title: 'Reset Activity Log?', text: message, icon: 'warning', showCancelButton: true,
        confirmButtonText: 'Reset log view', cancelButtonText: 'Cancel', confirmButtonColor: '#b42318',
        customClass: {popup: 'swal-flat'}}).then(result => { if (result.isConfirmed) form.submit(); });
}
</script>
@endpush
@endsection
