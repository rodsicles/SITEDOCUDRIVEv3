<?php

namespace App\Http\Controllers;

use App\Models\ExamQuestionnaire;
use App\Models\Program;
use App\Models\SchoolYear;
use App\Models\TeachingGuide;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Read-only oversight list of rejected Teaching Guides and Exam Questionnaires
 * across every program. Deleting a rejected file stays with its owner.
 */
class RejectedFilesController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasOversightAccess(), 403);

        $filters = $request->validate([
            'type' => ['nullable', Rule::in(['all', 'tg', 'eq'])],
            'program' => ['nullable', Rule::in(Program::codes())],
            'school_year_id' => ['nullable', 'integer', 'exists:school_years,id'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $type = $filters['type'] ?? 'all';

        $apply = function ($query, string $ownerRelation) use ($filters) {
            $query->where('status', 'rejected')
                ->when($filters['program'] ?? null, fn ($q, $program) => $q->whereHas($ownerRelation.'.employee', fn ($e) => $e->where('program', $program)))
                ->when($filters['school_year_id'] ?? null, fn ($q, $year) => $q->where('school_year_id', $year))
                ->when($filters['q'] ?? null, function ($q, $term) use ($ownerRelation) {
                    $q->where(fn ($inner) => $inner->where('title', 'like', "%{$term}%")
                        ->orWhere('subject', 'like', "%{$term}%")
                        ->orWhereHas($ownerRelation.'.employee', fn ($e) => $e->where('full_name', 'like', "%{$term}%")));
                });
        };

        $guides = $type === 'eq' ? collect() : TeachingGuide::query()->visibleTo($user)
            ->with(['uploader.employee', 'reviewer.employee'])
            ->tap(fn ($q) => $apply($q, 'uploader'))
            ->get()
            ->map(fn (TeachingGuide $guide) => $this->row($guide, 'Teaching Guide', $guide->uploader, 'teaching-guides'));

        $questionnaires = $type === 'tg' ? collect() : ExamQuestionnaire::query()->visibleTo($user)
            ->with(['submitter.employee', 'reviewer.employee'])
            ->tap(fn ($q) => $apply($q, 'submitter'))
            ->get()
            ->map(fn (ExamQuestionnaire $item) => $this->row($item, 'Exam Questionnaire'.($item->exam_type ? ' · '.$item->exam_type : ''), $item->submitter, 'exam-questionnaires'));

        $rows = $guides->concat($questionnaires)->sortByDesc('reviewed_at')->values();

        return view('dean.rejected-files', [
            'rows' => $rows,
            'filters' => $filters + ['type' => $type],
            'programs' => Program::codes(),
            'schoolYears' => SchoolYear::orderByDesc('start_year')->get(),
        ]);
    }

    private function row($item, string $typeLabel, $owner, string $routeKey): array
    {
        return [
            'title' => $item->title,
            'type' => $typeLabel,
            'subject' => $item->subject,
            'owner' => $owner?->employee?->full_name ?? $owner?->username ?? 'Unknown',
            'program' => $owner?->employee?->program,
            'reviewer' => $item->reviewer?->employee?->full_name ?? $item->reviewer?->username,
            'remarks' => $item->remarks,
            'reviewed_at' => $item->reviewed_at ?? $item->updated_at,
            'view_url' => route('dean.'.$routeKey.'.view', $item->id),
            'download_url' => route('dean.'.$routeKey.'.download', $item->id),
        ];
    }
}
