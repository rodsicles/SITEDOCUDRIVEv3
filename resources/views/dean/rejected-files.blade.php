@extends('layouts.dashboard')

@section('title', 'Rejected Files')

@section('page-title', 'Rejected Files')
@section('page-subtitle', 'Rejected Teaching Guides and Exam Questionnaires across all programs')

@section('sidebar')
    @include('partials.dean-sidebar')
@endsection

@section('content')
    <div class="content-card">
        <div class="card-header">
            <h3 class="card-title">
                Rejected submissions
                <span class="badge badge-info ml-2">{{ $rows->count() }}</span>
            </h3>
        </div>

        <form method="GET" action="{{ route('dean.rejected-files.index') }}" class="audit-filter-panel">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-3">
                <div class="form-group mb-0 lg:col-span-2">
                    <label class="form-label" for="rejected-q">Search</label>
                    <input id="rejected-q" type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="Title, subject, or faculty name">
                </div>
                <div class="form-group mb-0">
                    <label class="form-label" for="rejected-type">Type</label>
                    <select id="rejected-type" name="type" class="form-control">
                        <option value="all" @selected($filters['type'] === 'all')>All types</option>
                        <option value="tg" @selected($filters['type'] === 'tg')>Teaching Guides</option>
                        <option value="eq" @selected($filters['type'] === 'eq')>Exam Questionnaires</option>
                    </select>
                </div>
                <div class="form-group mb-0">
                    <label class="form-label" for="rejected-program">Program</label>
                    <select id="rejected-program" name="program" class="form-control">
                        <option value="">All programs</option>
                        @foreach($programs as $program)
                            <option value="{{ $program }}" @selected(($filters['program'] ?? '') === $program)>{{ $program }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group mb-0">
                    <label class="form-label" for="rejected-year">School year</label>
                    <select id="rejected-year" name="school_year_id" class="form-control">
                        <option value="">All years</option>
                        @foreach($schoolYears as $year)
                            <option value="{{ $year->id }}" @selected((string) ($filters['school_year_id'] ?? '') === (string) $year->id)>{{ $year->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter mr-1"></i> Apply Filters</button>
                @if(array_filter(\Illuminate\Support\Arr::except($filters, 'type')) || $filters['type'] !== 'all')
                    <a href="{{ route('dean.rejected-files.index') }}" class="btn btn-secondary"><i class="fas fa-times mr-1"></i> Clear</a>
                @endif
            </div>
        </form>

        <p class="px-4 py-2 text-sm text-gray-500 dark:text-gray-400">
            This list is read-only. Faculty fix and resubmit, or delete, their own rejected files.
        </p>

        <div class="overflow-x-auto">
            <table class="data-table compact">
                <thead>
                    <tr>
                        <th>File</th>
                        <th>Type</th>
                        <th>Faculty</th>
                        <th>Rejected by</th>
                        <th>Remarks</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $row)
                        <tr>
                            <td>
                                <strong class="text-sm">{{ $row['title'] }}</strong>
                                @if($row['subject'])
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row['subject'] }}</div>
                                @endif
                            </td>
                            <td class="text-sm">{{ $row['type'] }}</td>
                            <td class="text-sm">
                                {{ $row['owner'] }}
                                @if($row['program'])
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row['program'] }}</div>
                                @endif
                            </td>
                            <td class="text-sm">{{ $row['reviewer'] ?? '—' }}</td>
                            <td class="text-sm break-words">{{ \Illuminate\Support\Str::limit($row['remarks'] ?: 'No remarks', 120) }}</td>
                            <td class="text-xs whitespace-nowrap">{{ optional($row['reviewed_at'])->format('M d, Y g:i A') ?? '—' }}</td>
                            <td class="whitespace-nowrap">
                                <a href="{{ $row['view_url'] }}" class="btn btn-secondary btn-sm"><i class="fas fa-eye"></i> View</a>
                                <a href="{{ $row['download_url'] }}" class="btn btn-secondary btn-sm"><i class="fas fa-download"></i> Download</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-10 text-gray-500 dark:text-gray-400">No rejected files match these filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
