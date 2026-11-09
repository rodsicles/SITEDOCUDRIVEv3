<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\Document;
use App\Models\ExamQuestionnaire;
use App\Models\SchoolYear;
use App\Models\Task;
use App\Models\TeachingGuide;
use App\Models\User;
use App\Support\AcademicYear;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SubmissionAnalyticsService
{
    public function getAnalytics(User $viewer, array $filters = []): array
    {
        $filters = $this->normalizeFilters($viewer, $filters);
        $cacheKey = $this->cacheKey($viewer, $filters);

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($viewer, $filters) {
            $facultyIds = $this->scopedFacultyIds($viewer, $filters['program']);
            $counts = $this->aggregateFacultyCounts($facultyIds, $filters);
            $monthly = $this->aggregateMonthlyTrend($facultyIds, $filters);

            $responsiveness = $this->buildFacultyResponsiveness($facultyIds, $counts, $viewer, $filters);

            $active = SchoolYear::active();

            return [
                'facultyResponsiveness' => $responsiveness,
                'monthlyTrend' => $monthly,
                'totalSubmissions' => (int) $counts->sum('count'),
                'scopeLabel' => $this->scopeLabel($viewer, $filters),
                'showResponsivenessTable' => !$viewer->isFaculty(),
                'schoolYearOptions' => SchoolYear::filterOptions(),
                'activeSchoolYearStart' => $active?->start_year,
                'filters' => $filters,
            ];
        });
    }

    public function normalizeFilters(User $viewer, array $filters): array
    {
        $schoolYear = !empty($filters['school_year'])
            ? (int) $filters['school_year']
            : null;

        $active = SchoolYear::active();

        if (!$schoolYear) {
            $schoolYear = $active?->start_year
                ?? SchoolYear::orderByDesc('start_year')->value('start_year')
                ?? AcademicYear::currentStartYear();
        }

        if (!SchoolYear::where('start_year', $schoolYear)->exists() && $active) {
            $schoolYear = $active->start_year;
        }

        $semester = $viewer->isFaculty() ? null : ($filters['semester'] ?? null);
        if ($semester && !in_array($semester, ['1st', '2nd'], true)) {
            $semester = null;
        }

        $department = $filters['program'] ?? null;
        if ($viewer->isProgramCoordinator()) {
            $department = \App\Support\CoordinatorDepartment::handles($viewer, $department) ? $department : null;
        } elseif ($viewer->isFaculty()) {
            $department = (optional($viewer->employee)->program ?? "__unassigned__");
        } elseif ($department && !in_array($department, \App\Models\Program::codes(), true)) {
            $department = null;
        }

        return [
            'school_year' => $schoolYear,
            'academic_year' => AcademicYear::rangeString($schoolYear),
            'school_year_id' => SchoolYear::where('start_year', $schoolYear)->value('id'),
            'semester' => $semester,
            'program' => $department,
        ];
    }

    protected function cacheKey(User $viewer, array $filters): string
    {
        $role = $viewer->role->role_name ?? 'user';

        return 'submission_analytics_' . md5(json_encode([
            'role' => $role,
            'user' => $viewer->id,
            'programs' => \App\Support\CoordinatorDepartment::programs($viewer),
            'filters' => $filters,
        ]));
    }

    /** @return list<int> */
    protected function scopedFacultyIds(User $viewer, ?string $department): array
    {
        if ($viewer->isFaculty()) {
            return [$viewer->id];
        }

        $query = User::query()
            ->whereHas('role', fn ($q) => $q->where('role_name', 'Faculty Employee'))
            ->where('status', 'Active');

        $programs = $department ? [$department] : \App\Support\CourseCatalog::programsForUser($viewer);
        if ($programs !== null) {
            $query->whereHas('employee', fn ($q) => $q->whereIn('program', $programs));
        }

        return $query->pluck('id')->all();
    }

    protected function aggregateFacultyCounts(array $facultyIds, array $filters): Collection
    {
        if (empty($facultyIds)) {
            return collect();
        }

        $counts = collect($facultyIds)->mapWithKeys(fn ($id) => [(int) $id => 0]);

        $eqRows = ExamQuestionnaire::query()
            ->select('submitted_by', DB::raw('COUNT(*) as aggregate'))
            ->whereIn('submitted_by', $facultyIds)
            ->when($filters['semester'], fn ($q, $sem) => $q->where('semester', $sem))
            ->when($filters['school_year_id'], fn ($q, $id) => $q->where('school_year_id', $id))
            ->when(!$filters['school_year_id'] && $filters['academic_year'], fn ($q) => $q->where('academic_year', $filters['academic_year']))
            ->groupBy('submitted_by')
            ->pluck('aggregate', 'submitted_by');

        foreach ($eqRows as $userId => $count) {
            $counts[(int) $userId] = ($counts[(int) $userId] ?? 0) + (int) $count;
        }

        $tgRows = TeachingGuide::query()
            ->select('user_id', DB::raw('COUNT(*) as aggregate'))
            ->whereIn('user_id', $facultyIds)
            ->when($filters['semester'], fn ($q, $sem) => $q->where('semester', $sem))
            ->when($filters['school_year_id'], fn ($q, $id) => $q->where('school_year_id', $id))
            ->when(!$filters['school_year_id'] && $filters['academic_year'], fn ($q) => $q->where('academic_year', $filters['academic_year']))
            ->groupBy('user_id')
            ->pluck('aggregate', 'user_id');

        foreach ($tgRows as $userId => $count) {
            $counts[(int) $userId] = ($counts[(int) $userId] ?? 0) + (int) $count;
        }

        $docRows = Document::query()
            ->select('uploaded_by', DB::raw('COUNT(*) as aggregate'))
            ->whereIn('uploaded_by', $facultyIds)
            ->whereDoesntHave('examQuestionnaire')
            ->whereDoesntHave('teachingGuide')
            ->when($filters['semester'], function ($q) use ($filters) {
                $q->where(function ($inner) use ($filters) {
                    $inner->whereHas('folder', function ($fq) use ($filters) {
                        $sem = $filters['semester'] === '2nd' ? '2nd' : '1st';
                        $fq->where('folder_name', 'like', '%' . $sem . '%');
                    })->orWhereNull('folder_id');
                });
            })
            ->when($filters['school_year_id'], fn ($q, $id) => $q->where('school_year_id', $id))
            ->groupBy('uploaded_by')
            ->pluck('aggregate', 'uploaded_by');

        foreach ($docRows as $userId => $count) {
            $counts[(int) $userId] = ($counts[(int) $userId] ?? 0) + (int) $count;
        }

        return $counts
            ->map(fn ($count, $userId) => ['user_id' => (int) $userId, 'count' => (int) $count])
            ->filter(fn ($row) => $row['count'] > 0)
            ->values();
    }

    protected function aggregateMonthlyTrend(array $facultyIds, array $filters): Collection
    {
        if (empty($facultyIds)) {
            return collect();
        }

        $months = [];

        $append = function (Collection $rows) use (&$months) {
            foreach ($rows as $row) {
                $key = $row->month;
                $months[$key] = ($months[$key] ?? 0) + (int) $row->count;
            }
        };

        $eqQuery = ExamQuestionnaire::query()
            ->select(DB::raw('DATE_FORMAT(created_at, "%Y-%m") as month'), DB::raw('COUNT(*) as count'))
            ->whereIn('submitted_by', $facultyIds)
            ->where('created_at', '>=', now()->subMonths(11)->startOfMonth());

        $this->applyEqTgFilters($eqQuery, $filters);
        $append($eqQuery->groupBy('month')->orderBy('month')->get());

        $tgQuery = TeachingGuide::query()
            ->select(DB::raw('DATE_FORMAT(created_at, "%Y-%m") as month'), DB::raw('COUNT(*) as count'))
            ->whereIn('user_id', $facultyIds)
            ->where('created_at', '>=', now()->subMonths(11)->startOfMonth());

        $this->applyEqTgFilters($tgQuery, $filters, 'user_id');
        $append($tgQuery->groupBy('month')->orderBy('month')->get());

        $docQuery = Document::query()
            ->select(DB::raw('DATE_FORMAT(created_at, "%Y-%m") as month'), DB::raw('COUNT(*) as count'))
            ->whereIn('uploaded_by', $facultyIds)
            ->whereDoesntHave('examQuestionnaire')
            ->whereDoesntHave('teachingGuide')
            ->where('created_at', '>=', now()->subMonths(11)->startOfMonth())
            ->when($filters['school_year_id'], fn ($q, $id) => $q->where('school_year_id', $id));

        $append($docQuery->groupBy('month')->orderBy('month')->get());

        ksort($months);

        return collect($months)->map(fn ($count, $month) => [
            'month' => $month,
            'label' => date('M Y', strtotime($month . '-01')),
            'count' => $count,
        ])->values();
    }

    protected function applyEqTgFilters($query, array $filters, string $userColumn = 'submitted_by'): void
    {
        if ($filters['semester']) {
            $query->where('semester', $filters['semester']);
        }
        if ($filters['school_year_id']) {
            $query->where('school_year_id', $filters['school_year_id']);
        } elseif ($filters['academic_year']) {
            $query->where('academic_year', $filters['academic_year']);
        }
    }

    protected function buildFacultyResponsiveness(array $facultyIds, Collection $counts, User $viewer, array $filters): Collection
    {
        if (empty($facultyIds)) {
            return collect();
        }

        $schoolYear = SchoolYear::find($filters['school_year_id']);
        $yearStart = $schoolYear?->created_at ?? now()->startOfYear();
        $yearEnd = now();

        $taskResponseTimes = $this->computeTaskResponseTimes($facultyIds, $yearStart, $yearEnd);
        $readRates = $this->computeAnnouncementReadRates($facultyIds, $yearStart, $yearEnd);

        $submissionMap = $counts->keyBy('user_id');

        $users = User::with('employee')
            ->whereIn('id', $facultyIds)
            ->get()
            ->keyBy('id');

        if ($viewer->isFaculty()) {
            $user = $viewer->loadMissing('employee');
            $submissions = ($submissionMap->get($viewer->id)['count'] ?? 0);

            return collect([[
                'name' => $user->employee?->full_name ?? $user->username ?? 'You',
                'program' => $user->employee?->program ?? '—',
                'avg_response_days' => $taskResponseTimes->get($viewer->id),
                'read_rate' => $readRates->get($viewer->id, 0),
                'submissions' => $submissions,
            ]]);
        }

        $rows = collect($facultyIds)->map(function ($id) use ($users, $taskResponseTimes, $readRates, $submissionMap) {
            $user = $users->get($id);
            if (!$user) {
                return null;
            }

            return [
                'user_id' => $id,
                'name' => $user->employee?->full_name ?? $user->username ?? 'Unknown',
                'program' => $user->employee?->program ?? '—',
                'avg_response_days' => $taskResponseTimes->get($id),
                'read_rate' => $readRates->get($id, 0),
                'submissions' => ($submissionMap->get($id)['count'] ?? 0),
            ];
        })
        ->filter()
        ->sortBy(function ($row) {
            if ($row['avg_response_days'] === null) {
                return PHP_INT_MAX;
            }
            return $row['avg_response_days'];
        })
        ->values();

        return $rows;
    }

    protected function computeTaskResponseTimes(array $facultyIds, $from, $to): Collection
    {
        $rows = Task::selectRaw('assigned_to, AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)) as avg_hours')
            ->whereIn('assigned_to', $facultyIds)
            ->where('status', 'Completed')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('assigned_to')
            ->pluck('avg_hours', 'assigned_to');

        return $rows->map(fn ($hours) => round($hours / 24, 1));
    }

    protected function computeAnnouncementReadRates(array $facultyIds, $from, $to): Collection
    {
        $totalAnnouncements = Announcement::whereBetween('created_at', [$from, $to])->count();

        if ($totalAnnouncements === 0) {
            return collect(array_fill_keys($facultyIds, 0));
        }

        $readCounts = AnnouncementRead::query()
            ->whereIn('user_id', $facultyIds)
            ->whereHas('announcement', fn ($q) => $q->whereBetween('created_at', [$from, $to]))
            ->selectRaw('user_id, COUNT(DISTINCT announcement_id) as read_count')
            ->groupBy('user_id')
            ->pluck('read_count', 'user_id');

        return collect($facultyIds)->mapWithKeys(function ($id) use ($readCounts, $totalAnnouncements) {
            $reads = $readCounts->get($id, 0);
            return [$id => (int) round(($reads / $totalAnnouncements) * 100)];
        });
    }

    protected function scopeLabel(User $viewer, array $filters): string
    {
        $parts = [SchoolYear::labelForStartYear((int) $filters['school_year'])];

        if ($filters['semester']) {
            $parts[] = $filters['semester'] . ' Semester';
        }

        if ($viewer->isDeanOrSecretary()) {
            $parts[] = $filters['program'] ?: 'All Departments';
        } elseif ($viewer->isProgramCoordinator()) {
            $parts[] = $filters['program'] ?: implode(', ', \App\Support\CoordinatorDepartment::programs($viewer));
        } elseif ($viewer->isFaculty()) {
            $parts[] = 'Your submissions';
        }

        return implode(' · ', $parts);
    }
}
