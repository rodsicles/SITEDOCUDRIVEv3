<?php

namespace App\Services;

use App\Models\DashboardLog;
use App\Models\Document;
use App\Models\DocumentView;
use App\Models\Notification;
use App\Models\SchoolYear;
use App\Models\User;
use App\Support\AcademicYear;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class EngagementAnalyticsService
{
    public function getEngagement(User $viewer, array $filters = []): array
    {
        $filters = $this->normalizeFilters($viewer, $filters);
        $cacheKey = $this->cacheKey($viewer, $filters);

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($viewer, $filters) {
            $syId = $filters['school_year_id'];

            $dateRange = $this->schoolYearDateRange($syId);

            if ($viewer->isFaculty()) {
                return $this->buildFacultyEngagement($viewer, $filters, $dateRange);
            }

            $facultyLeaderboard = $this->buildLeaderboard(
                $this->scopedUserIds($viewer, $filters['program'], 'Faculty Employee'),
                $dateRange,
                $viewer
            );

            $coordinatorLeaderboard = $this->buildLeaderboard(
                $this->scopedUserIds($viewer, $filters['program'], 'Program Coordinator'),
                $dateRange,
                $viewer
            );

            $inactiveUsers = $this->findInactiveUsers(
                $this->scopedUserIds($viewer, $filters['program']),
                $dateRange
            );

            $weeklyTrend = $this->aggregateWeeklyTrend(
                $this->scopedUserIds($viewer, $filters['program']),
                $dateRange
            );

            $activityBreakdown = $this->aggregateActivityBreakdown(
                $this->scopedUserIds($viewer, $filters['program']),
                $dateRange
            );

            return [
                'engagementFilters' => $filters,
                'facultyLeaderboard' => $facultyLeaderboard,
                'coordinatorLeaderboard' => $coordinatorLeaderboard,
                'inactiveUsers' => $inactiveUsers,
                'weeklyTrend' => $weeklyTrend,
                'activityBreakdown' => $activityBreakdown,
                'engagementScopeLabel' => $this->scopeLabel($viewer, $filters),
            ];
        });
    }

    protected function buildFacultyEngagement(User $viewer, array $filters, array $dateRange): array
    {
        $userId = $viewer->id;
        $department = (optional($viewer->employee)->program ?? "__unassigned__");

        $myStats = $this->userActivityStats($userId, $dateRange, $filters['school_year_id']);

        $deptPeerIds = User::query()
            ->where('id', '!=', $userId)
            ->where('status', 'Active')
            ->whereHas('role', fn ($q) => $q->where('role_name', 'Faculty Employee'))
            ->when($department, fn ($q) => $q->whereHas('employee', fn ($eq) => $eq->where('program', $department)))
            ->pluck('id')
            ->all();

        $deptAvg = $this->departmentAverageStats($deptPeerIds, $dateRange, $filters['school_year_id']);

        $weeklyTrend = $this->userWeeklyTrend($userId, $dateRange);

        return [
            'engagementFilters' => $filters,
            'myEngagementStats' => $myStats,
            'departmentAvgStats' => $deptAvg,
            'weeklyTrend' => $weeklyTrend,
            'engagementScopeLabel' => $this->scopeLabel($viewer, $filters),
            'facultyLeaderboard' => collect(),
            'coordinatorLeaderboard' => collect(),
            'inactiveUsers' => collect(),
            'activityBreakdown' => collect(),
        ];
    }

    protected function userActivityStats(int $userId, array $dateRange, ?int $syId): array
    {
        $logCount = DashboardLog::where('user_id', $userId)
            ->whereBetween('log_date', $dateRange)
            ->count();

        $uploadCount = Document::where('uploaded_by', $userId)
            ->when($syId, fn ($q) => $q->where('school_year_id', $syId))
            ->when(!$syId, fn ($q) => $q->whereBetween('created_at', $dateRange))
            ->count();

        $viewCount = DocumentView::where('user_id', $userId)
            ->whereBetween('viewed_at', $dateRange)
            ->when($syId, fn ($q) => $q->whereHas('document', fn ($dq) => $dq->where('school_year_id', $syId)))
            ->count();

        $notifReadCount = Notification::where('user_id', $userId)
            ->where('is_read', true)
            ->whereBetween('created_at', $dateRange)
            ->count();

        $notifTotal = Notification::where('user_id', $userId)
            ->whereBetween('created_at', $dateRange)
            ->count();

        $loginCount = DashboardLog::where('user_id', $userId)
            ->where('activity_type', 'login')
            ->whereBetween('log_date', $dateRange)
            ->count();

        return [
            'total_actions' => $logCount,
            'uploads' => $uploadCount,
            'document_views' => $viewCount,
            'notifications_read' => $notifReadCount,
            'notifications_total' => $notifTotal,
            'read_rate' => $notifTotal > 0 ? round(($notifReadCount / $notifTotal) * 100, 1) : 0,
            'logins' => $loginCount,
        ];
    }

    protected function departmentAverageStats(array $peerIds, array $dateRange, ?int $syId): array
    {
        if (empty($peerIds)) {
            return [
                'total_actions' => 0, 'uploads' => 0, 'document_views' => 0,
                'notifications_read' => 0, 'notifications_total' => 0,
                'read_rate' => 0, 'logins' => 0,
            ];
        }

        $peerCount = count($peerIds);

        $logCount = DashboardLog::whereIn('user_id', $peerIds)
            ->whereBetween('log_date', $dateRange)
            ->count();

        $uploadCount = Document::whereIn('uploaded_by', $peerIds)
            ->when($syId, fn ($q) => $q->where('school_year_id', $syId))
            ->when(!$syId, fn ($q) => $q->whereBetween('created_at', $dateRange))
            ->count();

        $viewCount = DocumentView::whereIn('user_id', $peerIds)
            ->whereBetween('viewed_at', $dateRange)
            ->when($syId, fn ($q) => $q->whereHas('document', fn ($dq) => $dq->where('school_year_id', $syId)))
            ->count();

        $notifRead = Notification::whereIn('user_id', $peerIds)
            ->where('is_read', true)
            ->whereBetween('created_at', $dateRange)
            ->count();

        $notifTotal = Notification::whereIn('user_id', $peerIds)
            ->whereBetween('created_at', $dateRange)
            ->count();

        $loginCount = DashboardLog::whereIn('user_id', $peerIds)
            ->where('activity_type', 'login')
            ->whereBetween('log_date', $dateRange)
            ->count();

        return [
            'total_actions' => round($logCount / $peerCount),
            'uploads' => round($uploadCount / $peerCount),
            'document_views' => round($viewCount / $peerCount),
            'notifications_read' => round($notifRead / $peerCount),
            'notifications_total' => round($notifTotal / $peerCount),
            'read_rate' => $notifTotal > 0 ? round(($notifRead / $notifTotal) * 100, 1) : 0,
            'logins' => round($loginCount / $peerCount),
        ];
    }

    protected function buildLeaderboard(array $userIds, array $dateRange, User $viewer): Collection
    {
        if (empty($userIds)) {
            return collect();
        }

        $rows = DashboardLog::query()
            ->select('user_id', DB::raw('COUNT(*) as action_count'))
            ->whereIn('user_id', $userIds)
            ->whereBetween('log_date', $dateRange)
            ->groupBy('user_id')
            ->orderByDesc('action_count')
            ->limit(10)
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $users = User::with('employee')
            ->whereIn('id', $rows->pluck('user_id'))
            ->get()
            ->keyBy('id');

        $loginCounts = DashboardLog::query()
            ->select('user_id', DB::raw('COUNT(*) as login_count'))
            ->whereIn('user_id', $rows->pluck('user_id'))
            ->where('activity_type', 'login')
            ->whereBetween('log_date', $dateRange)
            ->groupBy('user_id')
            ->pluck('login_count', 'user_id');

        $uploadCounts = Document::query()
            ->select('uploaded_by', DB::raw('COUNT(*) as upload_count'))
            ->whereIn('uploaded_by', $rows->pluck('user_id'))
            ->whereBetween('created_at', $dateRange)
            ->groupBy('uploaded_by')
            ->pluck('upload_count', 'uploaded_by');

        return $rows->values()->map(function ($row, $index) use ($users, $loginCounts, $uploadCounts) {
            $user = $users->get($row->user_id);

            return [
                'rank' => $index + 1,
                'name' => $user?->employee?->full_name ?? $user?->username ?? 'Unknown',
                'program' => $user?->employee?->program ?? '—',
                'actions' => (int) $row->action_count,
                'logins' => (int) ($loginCounts[$row->user_id] ?? 0),
                'uploads' => (int) ($uploadCounts[$row->user_id] ?? 0),
            ];
        });
    }

    protected function findInactiveUsers(array $userIds, array $dateRange): Collection
    {
        if (empty($userIds)) {
            return collect();
        }

        $activeUserIds = DashboardLog::query()
            ->whereIn('user_id', $userIds)
            ->whereBetween('log_date', $dateRange)
            ->distinct()
            ->pluck('user_id')
            ->all();

        $inactiveIds = array_diff($userIds, $activeUserIds);

        if (empty($inactiveIds)) {
            return collect();
        }

        return User::with('employee')
            ->whereIn('id', $inactiveIds)
            ->get()
            ->map(fn ($user) => [
                'name' => $user->employee?->full_name ?? $user->username ?? 'Unknown',
                'program' => $user->employee?->program ?? '—',
                'last_activity' => DashboardLog::where('user_id', $user->id)
                    ->orderByDesc('log_date')
                    ->value('log_date'),
            ])
            ->sortBy('last_activity')
            ->values();
    }

    protected function aggregateWeeklyTrend(array $userIds, array $dateRange): Collection
    {
        if (empty($userIds)) {
            return collect();
        }

        return DashboardLog::query()
            ->select(
                DB::raw('YEARWEEK(log_date, 1) as yw'),
                DB::raw('MIN(DATE(log_date)) as week_start'),
                DB::raw('COUNT(*) as action_count'),
                DB::raw('COUNT(DISTINCT user_id) as active_users')
            )
            ->whereIn('user_id', $userIds)
            ->whereBetween('log_date', $dateRange)
            ->groupBy('yw')
            ->orderBy('yw')
            ->get()
            ->map(fn ($row) => [
                'week' => date('M d', strtotime($row->week_start)),
                'actions' => (int) $row->action_count,
                'active_users' => (int) $row->active_users,
            ]);
    }

    protected function userWeeklyTrend(int $userId, array $dateRange): Collection
    {
        return DashboardLog::query()
            ->select(
                DB::raw('YEARWEEK(log_date, 1) as yw'),
                DB::raw('MIN(DATE(log_date)) as week_start'),
                DB::raw('COUNT(*) as action_count')
            )
            ->where('user_id', $userId)
            ->whereBetween('log_date', $dateRange)
            ->groupBy('yw')
            ->orderBy('yw')
            ->get()
            ->map(fn ($row) => [
                'week' => date('M d', strtotime($row->week_start)),
                'actions' => (int) $row->action_count,
                'active_users' => 1,
            ]);
    }

    protected function aggregateActivityBreakdown(array $userIds, array $dateRange): Collection
    {
        if (empty($userIds)) {
            return collect();
        }

        return DashboardLog::query()
            ->select('activity_type', DB::raw('COUNT(*) as count'))
            ->whereIn('user_id', $userIds)
            ->whereBetween('log_date', $dateRange)
            ->whereNotNull('activity_type')
            ->groupBy('activity_type')
            ->orderByDesc('count')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'type' => ucfirst(str_replace('_', ' ', $row->activity_type)),
                'count' => (int) $row->count,
            ]);
    }

    protected function scopedUserIds(User $viewer, ?string $department, ?string $roleName = null): array
    {
        $query = User::query()->where('status', 'Active');

        if ($roleName) {
            $query->whereHas('role', fn ($q) => $q->where('role_name', $roleName));
        } else {
            $query->whereHas('role', fn ($q) => $q->whereIn('role_name', ['Faculty Employee', 'Program Coordinator']));
        }

        $programs = $department ? [$department] : \App\Support\CourseCatalog::programsForUser($viewer);
        if ($programs !== null) {
            $query->whereHas('employee', fn ($q) => $q->whereIn('program', $programs));
        }

        return $query->pluck('id')->all();
    }

    protected function schoolYearDateRange(?int $syId): array
    {
        if ($syId) {
            $sy = SchoolYear::find($syId);
            if ($sy) {
                $start = $sy->start_year . '-06-01';
                $end = $sy->end_year . '-05-31';
                return [$start, $end];
            }
        }

        return [now()->subYear()->toDateString(), now()->toDateString()];
    }

    protected function normalizeFilters(User $viewer, array $filters): array
    {
        $schoolYear = !empty($filters['school_year'])
            ? (int) $filters['school_year']
            : null;

        if (!$schoolYear) {
            $active = SchoolYear::active();
            $schoolYear = $active?->start_year ?? AcademicYear::currentStartYear();
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
            'program' => $department,
        ];
    }

    protected function cacheKey(User $viewer, array $filters): string
    {
        return 'engagement_analytics_' . md5(json_encode([
            'role' => $viewer->role->role_name ?? 'user',
            'user' => $viewer->id,
            'programs' => \App\Support\CoordinatorDepartment::programs($viewer),
            'filters' => $filters,
        ]));
    }

    protected function scopeLabel(User $viewer, array $filters): string
    {
        $parts = [AcademicYear::label((int) $filters['school_year'])];

        if ($viewer->isDeanOrSecretary()) {
            $parts[] = $filters['program'] ?: 'All Departments';
        } elseif ($viewer->isProgramCoordinator()) {
            $parts[] = $filters['program'] ?: implode(', ', \App\Support\CoordinatorDepartment::programs($viewer));
        } elseif ($viewer->isFaculty()) {
            $parts[] = 'Your activity';
        }

        return implode(' · ', $parts);
    }
}
