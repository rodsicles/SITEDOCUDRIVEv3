@extends('layouts.dashboard')
@section('title', "Teacher's Load history")
@section('page-title', "Teacher's Load history")
@section('page-subtitle', $load->faculty_name.' · '.$load->academic_year.' · '.\App\Support\SchoolTerm::label($load->semester))
@section('sidebar')
    @include(auth()->user()->hasOversightAccess() ? 'partials.dean-sidebar' : (auth()->user()->isProgramCoordinator() ? 'partials.coordinator-sidebar' : 'partials.faculty-sidebar'))
@endsection
@section('content')
<a href="{{ route('teacher-loads.index') }}" class="btn btn-secondary mb-4">Back to teaching loads</a>
<div class="content-card">
    <div class="card-header"><h2 class="card-title">Published revision {{ $load->revision_number }} · {{ ucfirst($load->status) }}</h2></div>
    @if($load->void_reason)<p class="p-4">Voided: {{ $load->void_reason }}</p>@endif
    <div class="table-responsive"><table class="data-table"><thead><tr><th>Revision</th><th>Changed by / date</th><th>Reason</th><th>File</th></tr></thead><tbody>
    @forelse($load->revisions as $revision)
        <tr><td>{{ $revision->revision_number }}</td><td>{{ $revision->actor?->employee?->full_name ?? $revision->actor?->username ?? 'Former account' }}<br>{{ $revision->created_at->format('M d, Y g:i A') }}</td><td>{{ $revision->reason }}</td><td><a class="btn btn-secondary" href="{{ route('teacher-loads.revision-pdf', [$load, $revision]) }}">Download saved revision</a></td></tr>
    @empty
        <tr><td colspan="4">No earlier published revisions.</td></tr>
    @endforelse
    </tbody></table></div>
</div>
@endsection
