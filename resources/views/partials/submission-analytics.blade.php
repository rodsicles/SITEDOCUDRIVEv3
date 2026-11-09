@php
    $filters = $filters ?? [];
    $routeName = $analyticsRoute ?? 'dean.analytics';
    $maxMonthly = max($monthlyTrend->max('count') ?: 1, 1);
@endphp

<div class="analytics-section content-card mb-0">
    <div class="card-header flex flex-wrap items-center justify-between gap-3">
        <div>
            <h3 class="card-title">Submission analytics</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $scopeLabel ?? '' }}</p>
        </div>
        <span class="badge badge-info">{{ number_format($totalSubmissions ?? 0) }} total submissions</span>
    </div>

    <form method="GET" action="{{ route($routeName) }}" class="submission-analytics-filters">
        @if(auth()->user()->isFaculty())
        <div class="submission-analytics-filters__grid submission-analytics-filters__grid--single">
            <div class="submission-analytics-filters__field">
                <label class="submission-analytics-filters__label" for="submission-school-year">
                    <i class="fas fa-calendar-alt"></i> School Year
                </label>
                <select id="submission-school-year" name="school_year" class="submission-analytics-filters__select" onchange="this.form.submit()">
                    @foreach($schoolYearOptions ?? [] as $value => $label)
                        <option value="{{ $value }}" @selected((string) ($filters['school_year'] ?? '') === (string) $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        @else
        <div class="submission-analytics-filters__grid">
            <div class="submission-analytics-filters__field">
                <label class="submission-analytics-filters__label" for="submission-school-year">
                    <i class="fas fa-calendar-alt"></i> School Year
                </label>
                <select id="submission-school-year" name="school_year" class="submission-analytics-filters__select">
                    @foreach($schoolYearOptions ?? [] as $value => $label)
                        <option value="{{ $value }}" @selected((string) ($filters['school_year'] ?? '') === (string) $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="submission-analytics-filters__field">
                <label class="submission-analytics-filters__label" for="submission-semester">
                    <i class="fas fa-book-open"></i> Semester
                </label>
                <select id="submission-semester" name="semester" class="submission-analytics-filters__select">
                    <option value="">All semesters</option>
                    <option value="1st" @selected(($filters['semester'] ?? '') === '1st')>1st Semester</option>
                    <option value="2nd" @selected(($filters['semester'] ?? '') === '2nd')>2nd Semester</option>
                </select>
            </div>
            @if(auth()->user()->isDeanOrSecretary())
            <div class="submission-analytics-filters__field">
                <label class="submission-analytics-filters__label" for="submission-program">
                    <i class="fas fa-building"></i> Program
                </label>
                <select id="submission-program" name="program" class="submission-analytics-filters__select">
                    <option value="">All programs</option>
                    @foreach(\App\Models\Program::labels() as $code => $label)<option value="{{ $code }}" @selected(($filters['program'] ?? '') === $code)>{{ $label }}</option>@endforeach
                </select>
            </div>
            @elseif(auth()->user()->isProgramCoordinator())
            @php $coordinatorPrograms = \App\Support\CoordinatorDepartment::programs(auth()->user()); @endphp
            <div class="submission-analytics-filters__field">
                <label class="submission-analytics-filters__label" for="submission-program">
                    <i class="fas fa-building"></i> Program
                </label>
                @if(count($coordinatorPrograms) > 1)
                <select id="submission-program" name="program" class="submission-analytics-filters__select">
                    <option value="">All my programs</option>
                    @foreach($coordinatorPrograms as $code)<option value="{{ $code }}" @selected(($filters['program'] ?? '') === $code)>{{ $code }}</option>@endforeach
                </select>
                @else
                <input type="text" class="submission-analytics-filters__input submission-analytics-filters__input--readonly" value="{{ $coordinatorPrograms[0] ?? '—' }}" readonly>
                @endif
            </div>
            @endif
            <div class="submission-analytics-filters__actions">
                <button type="submit" class="btn btn-primary submission-analytics-filters__btn">
                    <i class="fas fa-search"></i> Apply
                </button>
                <a href="{{ route($routeName) }}" class="btn btn-secondary submission-analytics-filters__btn">Reset</a>
            </div>
        </div>
        @endif
        @if(isset($activeSchoolYearStart) && (string) ($filters['school_year'] ?? '') === (string) $activeSchoolYearStart)
        <p class="submission-analytics-filters__hint">
            <i class="fas fa-check-circle"></i>
            Showing data for the current school year.
        </p>
        @endif
    </form>

    <div class="analytics-grid">
        <div>
            <h4>
                @if($showResponsivenessTable ?? true)
                    Faculty responsiveness
                @else
                    Your responsiveness
                @endif
            </h4>
            @if($showResponsivenessTable ?? true)
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Faculty</th>
                            <th>Avg. Task Response</th>
                            <th>Announcement Read Rate</th>
                            <th>Submissions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($facultyResponsiveness as $row)
                        <tr>
                            <td>
                                <div>
                                    <strong>{{ $row['name'] }}</strong>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row['program'] }}</div>
                                </div>
                            </td>
                            <td>
                                @if($row['avg_response_days'] !== null)
                                    @php
                                        $days = $row['avg_response_days'];
                                        $responseClass = $days <= 2 ? 'text-emerald-600' : ($days <= 5 ? 'text-amber-600' : 'text-red-600');
                                    @endphp
                                    <span class="font-semibold {{ $responseClass }}">{{ $days }} day{{ $days != 1 ? 's' : '' }}</span>
                                @else
                                    <span class="text-gray-400">No tasks</span>
                                @endif
                            </td>
                            <td>
                                @php
                                    $rate = $row['read_rate'];
                                    $rateClass = $rate >= 75 ? 'text-emerald-600' : ($rate >= 40 ? 'text-amber-600' : 'text-red-600');
                                @endphp
                                <div class="flex items-center gap-2">
                                    <div class="flex-1 bg-gray-200 dark:bg-gray-700 h-2 max-w-[80px]">
                                        <div class="h-full {{ $rate >= 75 ? 'bg-emerald-500' : ($rate >= 40 ? 'bg-amber-500' : 'bg-red-500') }}" style="width: {{ $rate }}%;"></div>
                                    </div>
                                    <span class="text-xs font-semibold {{ $rateClass }}">{{ $rate }}%</span>
                                </div>
                            </td>
                            <td>{{ $row['submissions'] }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="4" class="text-center text-gray-500 dark:text-gray-400 py-6">No faculty data for the selected filters.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            @else
                @php $you = $facultyResponsiveness->first(); @endphp
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="analytics-metric">
                        <div class="analytics-metric__label">Avg. Task Response</div>
                        <div class="analytics-metric__value">
                            @if($you && $you['avg_response_days'] !== null)
                                {{ $you['avg_response_days'] }} day{{ $you['avg_response_days'] != 1 ? 's' : '' }}
                            @else
                                —
                            @endif
                        </div>
                    </div>
                    <div class="analytics-metric">
                        <div class="analytics-metric__label">Announcement Read Rate</div>
                        <div class="analytics-metric__value">{{ $you['read_rate'] ?? 0 }}%</div>
                    </div>
                    <div class="analytics-metric">
                        <div class="analytics-metric__label">Submissions</div>
                        <div class="analytics-metric__value">{{ $you['submissions'] ?? 0 }}</div>
                    </div>
                </div>
            @endif
        </div>

        <div>
            <h4>Monthly submission trend</h4>
            @if($monthlyTrend->isNotEmpty())
                <div role="img" aria-label="Monthly submission trend">
                    @foreach($monthlyTrend as $point)
                    <div class="ui-meter">
                        <div class="ui-meter__row">
                            <span class="ui-meter__label">{{ $point['label'] }}</span>
                            <span class="ui-meter__value">{{ $point['count'] }}</span>
                        </div>
                        <div class="ui-meter__track" aria-hidden="true">
                            <div class="ui-meter__fill" style="width: {{ ($point['count'] / $maxMonthly) * 100 }}%;"></div>
                        </div>
                    </div>
                    @endforeach
                </div>
            @else
                @include('partials.ui.empty-state', ['title' => 'No monthly data', 'text' => 'No monthly data for the selected filters.'])
            @endif
        </div>
    </div>

    @if(auth()->user()->isFaculty() && $monthlyTrend->isNotEmpty())
    <div class="p-4 pt-0 border-t border-gray-200 dark:border-gray-700">
        <h4>Submission overview</h4>
        <div class="submission-overview-chart" role="img" aria-label="Monthly submission bar chart">
            @foreach($monthlyTrend as $point)
            <div class="submission-overview-chart__bar-col">
                <div class="submission-overview-chart__bar" style="height: {{ max(8, ($point['count'] / $maxMonthly) * 100) }}%;" title="{{ $point['label'] }}: {{ $point['count'] }}"></div>
                <span class="submission-overview-chart__label">{{ $point['label'] }}</span>
                <span class="submission-overview-chart__value">{{ $point['count'] }}</span>
            </div>
            @endforeach
        </div>
    </div>
    @endif
</div>
