@php
    $eFilters = $engagementFilters ?? [];
    $isFaculty = auth()->user()->isFaculty();
    $isDeanOrSec = auth()->user()->isDeanOrSecretary();
    $maxWeekly = max(($weeklyTrend ?? collect())->max('actions') ?: 1, 1);
@endphp

<section class="analytics-section content-card mb-0" aria-labelledby="engagement-heading">
    <div class="card-header">
        <div>
            <h3 id="engagement-heading" class="card-title">Engagement and activity</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $engagementScopeLabel ?? '' }}</p>
        </div>
    </div>

    @if($isFaculty)
    <div class="p-4">
        <h4>Your activity vs program average</h4>
        @php
            $my = $myEngagementStats ?? [];
            $avg = $departmentAvgStats ?? [];
            $metrics = [
                ['label' => 'Total Actions', 'key' => 'total_actions'],
                ['label' => 'Logins', 'key' => 'logins'],
                ['label' => 'Uploads', 'key' => 'uploads'],
                ['label' => 'Document Views', 'key' => 'document_views'],
                ['label' => 'Notifications Read', 'key' => 'notifications_read'],
                ['label' => 'Read Rate', 'key' => 'read_rate'],
            ];
        @endphp
        <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mb-6">
            @foreach($metrics as $m)
            @php
                $myVal = $my[$m['key']] ?? 0;
                $avgVal = $avg[$m['key']] ?? 0;
                $suffix = $m['key'] === 'read_rate' ? '%' : '';
                $better = $myVal >= $avgVal;
            @endphp
            <div class="analytics-metric">
                <div class="analytics-metric__label">{{ $m['label'] }}</div>
                <div class="analytics-metric__value">{{ number_format($myVal) }}{{ $suffix }}</div>
                <div class="analytics-metric__hint">
                    Dept avg: {{ number_format($avgVal) }}{{ $suffix }}
                    @if($better)
                        · at or above average
                    @else
                        · below average
                    @endif
                </div>
            </div>
            @endforeach
        </div>

        <h4>Weekly activity</h4>
        @if(($weeklyTrend ?? collect())->isNotEmpty())
        <div>
            @foreach($weeklyTrend as $point)
            <div class="ui-meter">
                <div class="ui-meter__row">
                    <span class="ui-meter__label">{{ $point['week'] }}</span>
                    <span class="ui-meter__value">{{ $point['actions'] }} actions</span>
                </div>
                <div class="ui-meter__track" aria-hidden="true">
                    <div class="ui-meter__fill" style="width: {{ ($point['actions'] / $maxWeekly) * 100 }}%;"></div>
                </div>
            </div>
            @endforeach
        </div>
        @else
            @include('partials.ui.empty-state', ['title' => 'No weekly activity', 'text' => 'No activity data for this period.'])
        @endif
    </div>

    @else
    <div class="analytics-grid">
        <div>
            <h4>Faculty activity</h4>
            @if(($facultyLeaderboard ?? collect())->isNotEmpty())
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Faculty</th>
                        <th>Program</th>
                        <th>Actions</th>
                        <th>Logins</th>
                        <th>Uploads</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($facultyLeaderboard as $row)
                    <tr>
                        <td>{{ $row['rank'] }}</td>
                        <td><strong>{{ $row['name'] }}</strong></td>
                        <td>{{ $row['program'] }}</td>
                        <td>{{ number_format($row['actions']) }}</td>
                        <td>{{ $row['logins'] }}</td>
                        <td>{{ $row['uploads'] }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @else
                @include('partials.ui.empty-state', ['title' => 'No faculty activity', 'text' => 'No faculty activity for this period.'])
            @endif
        </div>

        <div>
            <h4>Coordinator activity</h4>
            @if(($coordinatorLeaderboard ?? collect())->isNotEmpty())
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Coordinator</th>
                        <th>Program</th>
                        <th>Actions</th>
                        <th>Logins</th>
                        <th>Uploads</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($coordinatorLeaderboard as $row)
                    <tr>
                        <td>{{ $row['rank'] }}</td>
                        <td><strong>{{ $row['name'] }}</strong></td>
                        <td>{{ $row['program'] }}</td>
                        <td>{{ number_format($row['actions']) }}</td>
                        <td>{{ $row['logins'] }}</td>
                        <td>{{ $row['uploads'] }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @else
                @include('partials.ui.empty-state', ['title' => 'No coordinator activity', 'text' => 'No coordinator activity for this period.'])
            @endif
        </div>
    </div>

    <div class="analytics-grid">
        <div>
            <h4>Weekly activity trend</h4>
            @if(($weeklyTrend ?? collect())->isNotEmpty())
            <div role="img" aria-label="Weekly activity trend">
                @foreach($weeklyTrend as $point)
                <div class="ui-meter">
                    <div class="ui-meter__row">
                        <span class="ui-meter__label">{{ $point['week'] }}</span>
                        <span class="ui-meter__value">{{ $point['actions'] }} actions · {{ $point['active_users'] }} users</span>
                    </div>
                    <div class="ui-meter__track" aria-hidden="true">
                        <div class="ui-meter__fill" style="width: {{ ($point['actions'] / $maxWeekly) * 100 }}%;"></div>
                    </div>
                </div>
                @endforeach
            </div>
            @else
                @include('partials.ui.empty-state', ['title' => 'No weekly data', 'text' => 'No weekly data for this period.'])
            @endif
        </div>

        <div>
            <h4>Activity breakdown</h4>
            @php $maxBreakdown = max(($activityBreakdown ?? collect())->max('count') ?: 1, 1); @endphp
            @if(($activityBreakdown ?? collect())->isNotEmpty())
            <div>
                @foreach($activityBreakdown as $item)
                <div class="ui-meter">
                    <div class="ui-meter__row">
                        <span class="ui-meter__label">{{ $item['type'] }}</span>
                        <span class="ui-meter__value">{{ number_format($item['count']) }}</span>
                    </div>
                    <div class="ui-meter__track" aria-hidden="true">
                        <div class="ui-meter__fill" style="width: {{ ($item['count'] / $maxBreakdown) * 100 }}%;"></div>
                    </div>
                </div>
                @endforeach
            </div>
            @else
                @include('partials.ui.empty-state', ['title' => 'No activity data', 'text' => 'No activity data for this period.'])
            @endif
        </div>
    </div>

    @if(($inactiveUsers ?? collect())->isNotEmpty())
    <div class="p-4 pt-0">
        <h4>Inactive users <span class="badge badge-danger ml-2">{{ $inactiveUsers->count() }}</span></h4>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Program</th>
                    <th>Last Activity</th>
                </tr>
            </thead>
            <tbody>
                @foreach($inactiveUsers->take(10) as $user)
                <tr>
                    <td><strong>{{ $user['name'] }}</strong></td>
                    <td>{{ $user['program'] }}</td>
                    <td>
                        @if($user['last_activity'])
                            {{ \Carbon\Carbon::parse($user['last_activity'])->diffForHumans() }}
                        @else
                            Never
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
    @endif
</section>
