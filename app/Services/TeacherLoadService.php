<?php

namespace App\Services;

use App\Models\{Course, Employee, Program, SchoolYear, TeacherLoad, User};
use App\Support\SchoolTerm;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\{Rule, ValidationException};

class TeacherLoadService
{
    public function facultyQuery(User $actor)
    {
        $query = Employee::query()
            ->whereIn('program', Program::codes())
            ->whereHas('user', function ($q) {
                $q->where('status', 'Active')
                    ->whereHas('role', fn ($r) => $r->whereIn('role_name', ['Faculty Employee', 'Program Coordinator']));
            });

        if (! $actor->hasOversightAccess()) {
            $query->whereIn('program', \App\Support\CourseCatalog::programsForUser($actor) ?? ['__unassigned__']);
        }

        return $query;
    }

    /**
     * Faculty employees and program coordinators available for Teacher’s Load selection.
     * Includes people with zero assigned courses (tagged in the UI).
     */
    public function selectableFaculty(User $actor)
    {
        $people = $this->facultyQuery($actor)
            ->with(['user.role'])
            ->orderBy('full_name')
            ->get(['employee_id', 'full_name', 'program', 'user_id']);

        $counts = DB::table('faculty_courses')
            ->whereIn('user_id', $people->pluck('user_id')->filter()->all())
            ->selectRaw('user_id, COUNT(*) as aggregate')
            ->groupBy('user_id')
            ->pluck('aggregate', 'user_id');

        return $people->each(function (Employee $person) use ($counts) {
            $roleName = $person->user?->role?->role_name ?? 'Faculty Employee';
            $assigned = (int) ($counts[$person->user_id] ?? 0);
            $person->setAttribute('role_label', $roleName === 'Program Coordinator' ? 'Program Coordinator' : 'Faculty');
            $person->setAttribute('assigned_courses_count', $assigned);
            $person->setAttribute('has_assigned_courses', $assigned > 0);
        });
    }

    public function assignedCourses(Employee $faculty, string $semester)
    {
        // Existing assignments are not year-specific; never fall back to unassigned catalog courses.
        return $faculty->user->assignedCourses()->where('courses.program', $faculty->program)
            ->where('is_active', true)->where(fn ($q) => $q->where('semester', $semester)->orWhereNull('semester'))
            ->orderBy('code')->get(array_merge(
                ['courses.id', 'code', 'title'],
                \App\Support\CourseUnits::available() ? ['lecture_units', 'lab_units'] : []
            ));
    }

    public function validate(User $actor, array $input, ?TeacherLoad $load = null, bool $finalizing = false): array
    {
        abort_unless($actor->hasOversightAccess() || $actor->isProgramCoordinator(), 403);
        $data = validator($input, [
            'employee_id' => ['required', 'integer'],
            'school_year_id' => ['required', 'integer', 'exists:school_years,id'],
            'semester' => ['required', Rule::in(SchoolTerm::options())],
            'employment_status' => ['required', Rule::in(array_values(Employee::FACULTY_TYPES))],
            'lock_version' => [$load ? 'required' : 'nullable', 'integer', 'min:1'],
            'items' => ['present', 'array', 'max:60', $finalizing ? 'min:1' : 'min:0'],
            'items.*.kind' => ['required', Rule::in(['course', 'duty'])],
            'items.*.course_id' => ['nullable', 'integer'],
            'items.*.title' => ['nullable', 'string', 'max:150'],
            'items.*.section' => ['nullable', 'string', 'max:40'],
            'items.*.lecture_units' => ['required', 'numeric', 'min:0', 'max:99', 'decimal:0,2'],
            'items.*.lab_units' => ['required', 'numeric', 'min:0', 'max:99', 'decimal:0,2'],
            'items.*.load_equivalent' => ['required', 'numeric', 'min:0', 'max:999', 'decimal:0,3'],
            'items.*.class_size' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'items.*.schedules' => ['present', 'array', 'max:12'],
            'items.*.schedules.*.days' => ['required', 'array', 'min:1', 'max:7'],
            'items.*.schedules.*.days.*' => ['required', Rule::in(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'])],
            'items.*.schedules.*.start' => ['required', 'date_format:H:i'],
            'items.*.schedules.*.end' => ['required', 'date_format:H:i'],
            'items.*.schedules.*.mode' => ['required', Rule::in(['Lec', 'Lab'])],
            'items.*.schedules.*.room' => ['required', 'string', 'max:40'],
        ], [
            'items.min' => 'Add at least one subject or additional duty before finalizing.',
        ], [
            'employee_id' => 'faculty member', 'school_year_id' => 'school year', 'employment_status' => 'faculty type',
            'items.*.course_id' => 'subject', 'items.*.section' => 'section', 'items.*.class_size' => 'class size',
            'items.*.title' => 'additional duty', 'items.*.lecture_units' => 'lecture units', 'items.*.lab_units' => 'lab units',
            'items.*.load_equivalent' => 'load equivalent', 'items.*.schedules.*.days' => 'schedule days',
            'items.*.schedules.*.start' => 'start time', 'items.*.schedules.*.end' => 'end time',
            'items.*.schedules.*.room' => 'room', 'items.*.schedules.*.mode' => 'schedule type',
        ])->validate();

        $faculty = $this->facultyQuery($actor)->find($data['employee_id']);
        abort_unless($faculty, 403, 'Choose an active faculty member or program coordinator within your scope.');
        $year = SchoolYear::findOrFail($data['school_year_id']);
        if ($year->isArchived()) throw ValidationException::withMessages(['school_year_id' => 'Archived school years are read-only.']);
        if ($load && ((int) $load->employee_id !== (int) $faculty->employee_id || (int) $load->school_year_id !== (int) $year->id || $load->semester !== $data['semester'])) {
            throw ValidationException::withMessages(['employee_id' => 'The faculty and term cannot change on an existing load. Create a separate record for a different term.']);
        }
        $duplicate = TeacherLoad::where('employee_id', $faculty->employee_id)->where('school_year_id', $year->id)->where('semester', $data['semester']);
        if ($load) $duplicate->where('id', '!=', $load->id);
        if ($duplicate->exists()) throw ValidationException::withMessages(['semester' => 'A load already exists for this faculty and term. Open that record to continue.']);

        $courses = $this->assignedCourses($faculty, $data['semester'])->keyBy('id');
        $sectionNames = \App\Models\CourseSection::where('program', $faculty->program)->where('is_active', true)->pluck('name');
        $savedRows = $load ? $load->items->toArray() : [];
        if ($load?->revision_draft) $savedRows = array_merge($savedRows, $load->revision_draft['items'] ?? []);
        $items = []; $sections = []; $meetings = []; $units = 0; $equivalents = 0;
        foreach ($data['items'] as $index => $row) {
            $row = \Illuminate\Support\Arr::only($row, ['kind', 'course_id', 'title', 'section', 'lecture_units', 'lab_units', 'load_equivalent', 'class_size', 'schedules']);
            $row['course_code'] = null;
            if ($row['kind'] === 'course') {
                $course = $courses->get($row['course_id'] ?? 0);
                if (!$course) throw ValidationException::withMessages(["items.$index.course_id" => 'Choose a subject assigned to this faculty for the selected semester.']);
                $missing = [];
                if (!trim($row['section'] ?? '')) $missing["items.$index.section"] = 'Enter the section.';
                if (empty($row['class_size'])) $missing["items.$index.class_size"] = 'Enter the class size.';
                if ($missing) throw ValidationException::withMessages($missing);
                $sectionName = trim($row['section']);
                $catalogName = $sectionNames->first(fn ($name) => mb_strtolower($name) === mb_strtolower($sectionName));
                $keptSnapshot = collect($savedRows)->contains(fn ($saved) => (int) ($saved['course_id'] ?? 0) === (int) $course->id && ($saved['section'] ?? '') === $sectionName);
                if (!$catalogName && !$keptSnapshot) throw ValidationException::withMessages(["items.$index.section" => 'Choose an active section for this program. Manage choices in Course Catalog → Sections.']);
                $row['section'] = $catalogName ?: $sectionName;
                $key = $course->id.'|'.mb_strtolower(trim($row['section']));
                if (isset($sections[$key])) throw ValidationException::withMessages(["items.$index.section" => 'This subject and section are already listed. Add another schedule to the existing row.']);
                $sections[$key] = true;
                $row['course_id'] = $course->id;
                $row['course_code'] = $course->code;
                $row['title'] = $course->title;
                $row['section'] = trim($row['section']);
                if ($finalizing && empty($row['schedules'])) throw ValidationException::withMessages(["items.$index.schedules" => 'Add at least one schedule before finalizing.']);
            } else {
                if (!trim($row['title'] ?? '')) throw ValidationException::withMessages(["items.$index.title" => 'Enter the additional duty.']);
                $row['title'] = trim($row['title']);
                $row['course_id'] = null;
                $row['lecture_units'] = $row['lab_units'] = 0;
                $row['class_size'] = $row['section'] = null;
                $row['schedules'] = [];
            }
            foreach ($row['schedules'] as $s => &$schedule) {
                $schedule = \Illuminate\Support\Arr::only($schedule, ['days', 'start', 'end', 'mode', 'room']);
                if ($schedule['end'] <= $schedule['start']) throw ValidationException::withMessages(["items.$index.schedules.$s.end" => 'The end time must be later than the start time.']);
                $schedule['days'] = array_values(array_unique($schedule['days']));
                foreach ($schedule['days'] as $day) {
                    foreach ($meetings[$day] ?? [] as [$start, $end]) {
                        if ($schedule['start'] < $end && $schedule['end'] > $start) throw ValidationException::withMessages(["items.$index.schedules.$s.start" => "The faculty already has a meeting at this time on $day."]);
                    }
                    $meetings[$day][] = [$schedule['start'], $schedule['end']];
                }
            }
            unset($schedule);
            $units += (int) round((float) $row['lecture_units'] * 100) + (int) round((float) $row['lab_units'] * 100);
            $equivalents += (int) round((float) $row['load_equivalent'] * 1000);
            $row['position'] = $index;
            $items[] = $row;
        }
        return [
            'header' => [
                'employee_id' => $faculty->employee_id, 'school_year_id' => $year->id, 'semester' => $data['semester'],
                'program' => $faculty->program, 'faculty_name' => $faculty->full_name, 'employee_number' => $faculty->employee_no,
                'department' => Program::DEPARTMENT_NAME, 'academic_year' => $year->start_year.'–'.$year->end_year,
                'employment_status' => $data['employment_status'], 'total_units' => $units / 100, 'total_load' => $equivalents / 1000,
            ],
            'items' => $items, 'lock_version' => $data['lock_version'] ?? null,
        ];
    }

    public function save(User $actor, array $input, ?TeacherLoad $load = null): TeacherLoad
    {
        return DB::transaction(function () use ($actor, $input, $load) {
            if ($load) {
                $load = TeacherLoad::visibleTo($actor)->lockForUpdate()->findOrFail($load->id);
                abort_if($load->status !== 'draft' && !(in_array($load->status, ['finalized', 'void'], true) && $load->revision_draft !== null), 409, 'Start a revision before editing a published load.');
                abort_if((int) ($input['lock_version'] ?? 0) !== $load->lock_version, 409, 'This load changed in another session. Reload it before saving.');
            }
            $data = $this->validate($actor, $input, $load);
            if ($load && in_array($load->status, ['finalized', 'void'], true)) {
                $load->update(['revision_draft' => $data, 'lock_version' => $load->lock_version + 1]);
                return $load->load('items');
            }
            if ($load) {
                $load->update($data['header'] + ['lock_version' => $load->lock_version + 1]);
                $load->items()->delete();
            } else {
                $load = TeacherLoad::create($data['header'] + ['created_by_employee_id' => $actor->employee?->employee_id]);
            }
            $load->items()->createMany($data['items']);
            return $load->load('items');
        });
    }

    public function finalize(User $actor, TeacherLoad $load, int $version): TeacherLoad
    {
        return DB::transaction(function () use ($actor, $load, $version) {
            $load = TeacherLoad::visibleTo($actor)->lockForUpdate()->findOrFail($load->id);
            $revising = in_array($load->status, ['finalized', 'void'], true) && $load->revision_draft !== null;
            abort_if(($load->status !== 'draft' && !$revising) || $version !== $load->lock_version, 409, 'This load has changed or is already finalized. Reload it to continue.');
            $input = $revising ? $load->revision_draft['header'] : $load->toArray();
            $input['items'] = $revising ? $load->revision_draft['items'] : $load->items->toArray();
            $input['lock_version'] = $load->lock_version;
            $data = $this->validate($actor, $input, $load, true);
            if ($revising) $this->snapshot($load, $actor, $load->revision_reason);
            $load->update($data['header'] + ['status' => 'finalized', 'finalized_at' => now(), 'lock_version' => $load->lock_version + 1,
                'revision_number' => $revising ? $load->revision_number + 1 : $load->revision_number,
                'revision_draft' => null, 'revision_reason' => null, 'void_reason' => null]);
            $load->items()->delete();
            $load->items()->createMany($data['items']);
            $this->record($load, $actor, $revising ? 'revision_published' : 'finalized');
            return $load->load('items');
        });
    }

    public function change(User $actor, int $id, string $action, int $version, string $reason): ?TeacherLoad
    {
        abort_unless($actor->hasOversightAccess() || $actor->isProgramCoordinator(), 403);
        return DB::transaction(function () use ($actor, $id, $action, $version, $reason) {
            $load = TeacherLoad::visibleTo($actor)->with('items')->lockForUpdate()->findOrFail($id);
            abort_if($load->schoolYear->isArchived(), 422, 'Archived teaching loads are read-only.');
            abort_if($load->lock_version !== $version, 409, 'This load changed. Reload before continuing.');
            if ($action === 'revise') {
                abort_unless(in_array($load->status, ['finalized', 'void'], true) && $load->revision_draft === null, 409, 'Only a published or voided load without an open revision can be revised.');
                $load->update(['revision_draft' => ['header' => $load->attributesToArray(), 'items' => $load->items->toArray()], 'revision_reason' => $reason]);
            } elseif ($action === 'discard') {
                abort_unless(in_array($load->status, ['finalized', 'void'], true) && $load->revision_draft !== null, 409, 'No revision draft is open.');
                $load->update(['revision_draft' => null, 'revision_reason' => null]);
            } elseif ($action === 'void') {
                abort_unless($load->status === 'finalized' && $load->revision_draft === null, 409, 'Discard the open revision before voiding a published load.');
                $this->snapshot($load, $actor, $reason);
                $load->update(['status' => 'void', 'void_reason' => $reason]);
            } else {
                abort_unless($action === 'delete-draft' && $load->status === 'draft' && !$load->finalized_at, 409, 'Only an unpublished draft can be deleted.');
                $this->record($load, $actor, 'draft_deleted', $reason);
                $load->delete();
                return null;
            }
            $load->increment('lock_version');
            $this->record($load, $actor, $action, $reason);
            return $load->fresh('items');
        });
    }

    private function snapshot(TeacherLoad $load, User $actor, string $reason): void
    {
        $load->load('items');
        $load->revisions()->firstOrCreate(['revision_number' => $load->revision_number], ['snapshot' => $load->toArray(), 'changed_by' => $actor->id, 'reason' => $reason]);
    }

    private function record(TeacherLoad $load, User $actor, string $action, ?string $reason = null): void
    {
        \App\Models\DashboardLog::create(['user_id' => $actor->id, 'target_user_id' => $load->employee->user_id,
            'activity' => \Illuminate\Support\Str::limit("Teacher's Load #{$load->id}: {$action}".($reason ? ' — '.$reason : ''), 250),
            'activity_type' => 'teacher_load_'.$action, 'visibility' => 'own']);
        if (in_array($action, ['finalized', 'revision_published', 'void'], true)) {
            \App\Models\Notification::create(['user_id' => $load->employee->user_id,
                'message' => "Your Teacher's Load was ".str_replace('_', ' ', $action).'.', 'action_url' => route('teacher-loads.index')]);
        }
    }
}
