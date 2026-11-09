<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use App\Services\EngagementAnalyticsService;
use App\Services\SubmissionAnalyticsService;
use App\Services\MonthlyAnalyticsService;
use App\Models\MonthlyAnalyticsSnapshot;
use App\Models\SystemResetEvent;
use App\Services\SystemResetService;
use App\Support\CoordinatorDepartment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AnalyticsController extends Controller
{
    public function __construct(
        protected DashboardService $dashboardService,
        protected SubmissionAnalyticsService $submissionAnalytics,
        protected EngagementAnalyticsService $engagementAnalytics,
    ) {}

    public function index(Request $request)
    {
        $user = auth()->user();

        $filterInput = $user->isFaculty()
            ? ['school_year' => $request->query('school_year')]
            : [
                'school_year' => $request->query('school_year'),
                'semester' => $request->query('semester'),
                'program' => $request->query('program'),
            ];

        $submissionData = $this->submissionAnalytics->getAnalytics($user, $filterInput);
        $engagementData = $this->engagementAnalytics->getEngagement($user, $filterInput);

        $data = array_merge($submissionData, $engagementData, [
            'analyticsRoute' => $this->routeNameFor($user),
        ]);

        if ($user->isDeanOrSecretary()) {
            $data = array_merge($data, $this->dashboardService->getAnalyticsData());
            if ($user->isDean()) {
                $data['monthlyHistoryReady'] = Schema::hasTable('monthly_analytics_snapshots');
                $data['monthlyHistory'] = $data['monthlyHistoryReady']
                    ? MonthlyAnalyticsSnapshot::with('closer')->orderByDesc('period_start')->get() : collect();
                $resets = app(SystemResetService::class);
                $data['monthlyResetReady'] = Schema::hasTable('system_reset_events');
                $data['currentMonthReset'] = $resets->latest('analytics_month');
                $data['currentMonthMetrics'] = $resets->monthMetrics(now(),
                    $data['currentMonthReset']?->reset_at->isSameMonth(now()) ? $data['currentMonthReset'] : null);
                $data['monthlyResetHistory'] = $data['monthlyResetReady']
                    ? SystemResetEvent::where('scope', 'analytics_month')->latest('reset_at')->limit(12)->get() : collect();
            }
            return view('dean.analytics', $data);
        }

        if ($user->isProgramCoordinator()) {
            CoordinatorDepartment::require($user);

            return view('coordinator.analytics', $data);
        }

        return view('faculty.analytics', $data);
    }

    public function closeMonth(Request $request, MonthlyAnalyticsService $monthlyAnalytics)
    {
        abort_unless($request->user()->isDean(), 403);
        abort_unless(Schema::hasTable('monthly_analytics_snapshots'), 503, 'Monthly analytics is awaiting its database migration.');
        $snapshot = $monthlyAnalytics->closePreviousMonth($request->user());

        return redirect()->route('dean.analytics')->with('success', $snapshot->period_start->format('F Y').' analytics saved to monthly history.');
    }

    public function resetThisMonth(Request $request, SystemResetService $resets)
    {
        abort_unless($request->user()->isDean(), 403);
        $resets->resetThisMonth($request->user());
        return redirect()->route('dean.analytics')->with('success', 'This month’s summary restarted. Earlier records remain available.');
    }

    protected function routeNameFor($user): string
    {
        if ($user->isDeanOrSecretary()) {
            return 'dean.analytics';
        }
        if ($user->isProgramCoordinator()) {
            return 'coordinator.analytics';
        }

        return 'faculty.analytics';
    }
}
