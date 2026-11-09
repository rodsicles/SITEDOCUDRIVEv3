<?php

namespace App\Services;

use App\Models\User;
use App\Models\Employee;
use App\Models\Task;
use App\Models\Document;
use App\Models\Folder;
use App\Models\Report;
use App\Models\Notification;
use App\Models\DashboardLog;
use App\Models\PerformanceReport;
use App\Support\EmployeeNumberGenerator;
use App\Services\FacultyDocumentTreeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class EmployeeService
{
    /**
     * Create a coordinator account (user + employee) in a transaction.
     */
    public function createCoordinator(array $validated, int $creatorUserId): Employee
    {
        $this->validateProgramAssignments($validated);
        $this->applyGeneratedAccountFields($validated, EmployeeNumberGenerator::ROLE_COORDINATOR);

        DB::beginTransaction();
        try {
            $user = User::create([
                'role_id' => 2,
                'name' => $validated['full_name'],
                'username' => $validated['username'],
                'email' => $validated['email'] ?? null,
                'password' => Hash::make($validated['password']),
                'status' => 'Active',
                // Force a password rotation on first login so the Dean is not
                // liable for whatever the temporary password is later used for.
                'must_change_password' => true,
                'password_changed_at'  => null,
            ]);

            $employee = Employee::create([
                'user_id' => $user->id,
                'employee_no' => $validated['employee_no'],
                'full_name' => $validated['full_name'],
                'first_name' => $validated['first_name'] ?? null,
                'middle_name' => $validated['middle_name'] ?? null,
                'last_name' => $validated['last_name'] ?? null,
                'program' => $validated['program'],
                'position' => 'Program Coordinator',
                'hire_date' => now(),
            ]);
            $employee->syncExtraPrograms($validated['extra_programs'] ?? []);

            if (!empty($validated['course_ids'])) {
                $user->assignedCourses()->sync($validated['course_ids']);
            }

            DashboardLog::create([
                'user_id' => $creatorUserId,
                'target_user_id' => $user->id,
                'activity' => $this->accountCreatedActivity('Program Coordinator', $validated),
                'activity_type' => 'account_created',
                'visibility' => 'dean',
            ]);

            DB::commit();
            return $employee;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Create a faculty account (user + employee) in a transaction.
     */
    public function createFaculty(array $validated, int $creatorUserId): Employee
    {
        $this->validateProgramAssignments($validated);
        $this->applyGeneratedAccountFields($validated, EmployeeNumberGenerator::ROLE_FACULTY);

        DB::beginTransaction();
        try {
            $user = User::create([
                'role_id' => 3,
                'name' => $validated['full_name'],
                'username' => $validated['username'],
                'email' => $validated['email'] ?? null,
                'password' => Hash::make($validated['password']),
                'status' => 'Active',
                // Force a password rotation on first login (chain-of-custody).
                'must_change_password' => true,
                'password_changed_at'  => null,
            ]);

            $employee = Employee::create([
                'user_id' => $user->id,
                'employee_no' => $validated['employee_no'],
                'full_name' => $validated['full_name'],
                'first_name' => $validated['first_name'] ?? null,
                'middle_name' => $validated['middle_name'] ?? null,
                'last_name' => $validated['last_name'] ?? null,
                'program' => $validated['program'],
                'faculty_type' => $validated['faculty_type'],
                'position' => 'Faculty Employee',
                'hire_date' => now(),
            ]);

            // Sync assigned courses (subjects this faculty member will teach)
            if (!empty($validated['course_ids'])) {
                $user->assignedCourses()->sync($validated['course_ids']);
            }

            DashboardLog::create([
                'user_id' => $creatorUserId,
                'target_user_id' => $user->id,
                'activity' => $this->accountCreatedActivity('Faculty Employee', $validated),
                'activity_type' => 'account_created',
                'visibility' => 'coordinator',
            ]);

            DB::commit();
            return $employee;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Create an Admin account (Dean only). Admins cover every program, so no home program is stored.
     */
    public function createAdmin(array $validated, int $creatorUserId): Employee
    {
        return DB::transaction(function () use ($validated, $creatorUserId) {
            $user = User::create([
                'role_id' => \App\Models\Role::idFor(\App\Models\Role::ADMIN),
                'name' => $validated['full_name'],
                'username' => $validated['username'],
                'email' => null,
                'password' => Hash::make($validated['password']),
                'status' => 'Active',
                'must_change_password' => true,
                'password_changed_at' => null,
            ]);

            $employee = Employee::create([
                'user_id' => $user->id,
                'employee_no' => app(EmployeeNumberGenerator::class)->next('', EmployeeNumberGenerator::ROLE_ADMIN),
                'full_name' => $validated['full_name'],
                'first_name' => $validated['first_name'] ?? null,
                'middle_name' => $validated['middle_name'] ?? null,
                'last_name' => $validated['last_name'] ?? null,
                'department' => \App\Models\Program::DEPARTMENT_NAME,
                'position' => 'Admin',
                'hire_date' => now(),
            ]);

            DashboardLog::create([
                'user_id' => $creatorUserId,
                'target_user_id' => $user->id,
                'activity' => \Illuminate\Support\Str::limit('Created Admin account: '.$validated['full_name'], 250),
                'activity_type' => 'account_created',
                'visibility' => 'dean',
            ]);

            return $employee;
        });
    }

    /**
     * Update a faculty member's information in a transaction.
     */
    public function updateFaculty(Employee $employee, array $validated, int $updaterUserId): Employee
    {
        $this->validateProgramAssignments($validated);
        DB::beginTransaction();
        try {
            $employee->update([
                'full_name' => $validated['full_name'],
                'employee_no' => array_key_exists('employee_no', $validated) ? $validated['employee_no'] : $employee->employee_no,
                'program' => $validated['program'],
                'faculty_type' => $validated['faculty_type'] ?? $employee->faculty_type,
                'position' => $validated['position'] ?? $employee->position,
                'hire_date' => $validated['hire_date'] ?? $employee->hire_date,
            ] + $this->namePartsForUpdate($employee, $validated));

            $employee->user->update([
                'name' => $validated['full_name'],
                'email' => $validated['email'] ?? null,
            ]);

            // Sync course assignments (replace all, empty array = clear)
            $this->syncCurrentAssignments($employee->user, $validated);

            DashboardLog::create([
                'user_id' => $updaterUserId,
                'target_user_id' => $employee->user_id,
                'activity' => 'Updated faculty information: ' . $validated['full_name'],
                'activity_type' => 'profile_update',
                'visibility' => 'coordinator',
            ]);

            DB::commit();
            return $employee->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Reset a faculty member's password with notification and logging.
     */
    public function resetFacultyPassword(Employee $employee, string $newPassword, User $resetter): void
    {
        $employee->user->update([
            'password'             => Hash::make($newPassword),
            // Treat any admin reset like a fresh issuance: faculty must rotate again.
            'must_change_password' => true,
            'password_changed_at'  => null,
        ]);

        Notification::create([
            'user_id' => $employee->user_id,
            'message' => 'Your password has been reset by ' . $resetter->employee->full_name . ' (Program Coordinator). Please use your new password to login.',
        ]);

        DashboardLog::create([
            'user_id' => $resetter->id,
            'target_user_id' => $employee->user_id,
            'activity' => 'Reset password for faculty: ' . $employee->full_name,
            'activity_type' => 'password_reset',
            'visibility' => 'coordinator',
        ]);
    }

    /**
     * Update an employee's information (used by Dean).
     */
    public function updateEmployee(Employee $employee, array $validated, int $updaterUserId): Employee
    {
        $this->validateProgramAssignments($validated);
        DB::beginTransaction();
        try {
            $employee->update([
                'full_name' => $validated['full_name'],
                'employee_no' => array_key_exists('employee_no', $validated) ? $validated['employee_no'] : $employee->employee_no,
                'program' => $validated['program'],
                'faculty_type' => $validated['faculty_type'] ?? $employee->faculty_type,
                'position' => $validated['position'] ?? $employee->position,
                'hire_date' => $validated['hire_date'] ?? $employee->hire_date,
            ] + $this->namePartsForUpdate($employee, $validated));
            if ($employee->user->isProgramCoordinator()) {
                $employee->syncExtraPrograms($validated['extra_programs'] ?? []);
            }

            $employee->user->update([
                'name' => $validated['full_name'],
                'email' => $validated['email'] ?? null,
            ]);

            // Sync course assignments for faculty/coordinator (replace all)
            $this->syncCurrentAssignments($employee->user, $validated);

            DashboardLog::create([
                'user_id' => $updaterUserId,
                'target_user_id' => $employee->user_id,
                'activity' => 'Updated employee information: ' . $validated['full_name'],
                'activity_type' => 'profile_update',
                'visibility' => 'dean',
            ]);

            DB::commit();
            return $employee->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Activate or deactivate a faculty/coordinator account (Dean). Does not delete records or uploads.
     */
    public function setAccountStatus(Employee $employee, string $status, User $actor): Employee
    {
        if (!in_array($status, ['Active', 'Inactive'], true)) {
            throw new \InvalidArgumentException('Status must be Active or Inactive.');
        }

        $user = $employee->user;

        if ($user->isDean()) {
            abort(403, 'Cannot change status for Dean accounts.');
        }

        if ($user->isAdmin() && !$actor->isDean()) {
            abort(403, 'Only the Dean can change the status of Admin accounts.');
        }

        if ($actor->isAdmin() && !$user->isFaculty()) {
            abort(403, 'Admins can only manage Faculty accounts.');
        }

        if ($actor->id === $user->id && $status === 'Inactive') {
            abort(403, 'You cannot deactivate your own account.');
        }

        $previous = $user->status;
        if ($previous === $status) {
            return $employee->fresh(['user.role']);
        }

        $user->update(['status' => $status]);

        $label = $status === 'Inactive' ? 'Deactivated' : 'Reactivated';
        DashboardLog::create([
            'user_id' => $actor->id,
            'target_user_id' => $user->id,
            'activity' => "{$label} account: {$employee->full_name}",
            'activity_type' => $status === 'Inactive' ? 'account_deactivated' : 'account_reactivated',
            'visibility' => 'dean',
        ]);

        if ($status === 'Inactive') {
            Notification::create([
                'user_id' => $user->id,
                'message' => 'Your SITE Employee Dashboard account has been deactivated by the Dean. You can no longer sign in. Uploaded documents remain on record for the department.',
                'tone' => \App\Models\Notification::TONE_DANGER,
            ]);
        } else {
            Notification::create([
                'user_id' => $user->id,
                'message' => 'Your SITE Employee Dashboard account has been reactivated. You may sign in again with your username and password.',
                'tone' => \App\Models\Notification::TONE_SUCCESS,
            ]);
        }

        return $employee->fresh(['user.role']);
    }

    /**
     * Reset an employee's password (used by Dean).
     */
    public function resetEmployeePassword(Employee $employee, string $newPassword, User $resetter): void
    {
        $employee->user->update([
            'password'             => Hash::make($newPassword),
            // Treat any admin reset like a fresh issuance: target must rotate again.
            'must_change_password' => true,
            'password_changed_at'  => null,
        ]);

        Notification::create([
            'user_id' => $employee->user_id,
            'message' => 'Your password has been reset by ' . ($resetter->employee->full_name ?? ($resetter->isAdmin() ? 'Admin' : 'Dean')) . '. Please use your new password to login.',
        ]);

        DashboardLog::create([
            'user_id' => $resetter->id,
            'target_user_id' => $employee->user_id,
            'activity' => 'Reset password for: ' . $employee->full_name,
            'activity_type' => 'password_reset',
            'visibility' => 'dean',
        ]);
    }

    /**
     * Get employee profile data with all associated stats.
     */
    public function getEmployeeProfile(int $employeeId): array
    {
        $employee = Employee::with(['user.role', 'performanceReports.evaluator.employee'])
            ->where('employee_id', $employeeId)
            ->firstOrFail();

        $performanceReports = PerformanceReport::with('evaluator.employee')
            ->where('employee_id', $employeeId)
            ->orderBy('report_date', 'desc')
            ->get();

        $tasks = Task::with('assignedBy.employee')
            ->where('assigned_to', $employee->user_id)
            ->orderBy('created_at', 'desc')
            ->get();

        $taskStats = [
            'total' => $tasks->count(),
            'completed' => $tasks->where('status', 'Completed')->count(),
            'pending' => $tasks->where('status', 'Pending')->count(),
        ];

        $treeService = app(FacultyDocumentTreeService::class);
        $documents = $treeService->displayableDocumentsForUser($employee->user_id);

        $documentStats = [
            'total' => $documents->count(),
            'byType' => $documents->groupBy('document_type')->map->count(),
            'byCategory' => $documents->groupBy(fn ($d) => $d->category ?: ($d->folder?->top_level_category ?? 'Other'))->map->count(),
        ];

        $documentTree = $treeService->buildForUser($employee->user_id);

        $reports = Report::query()
            ->where('submitted_by', $employee->user_id)
            ->orderBy('created_at', 'desc')
            ->get();

        $reportStats = [
            'total' => $reports->count(),
            'byCategory' => $reports->groupBy('report_category')->map->count(),
        ];

        return compact(
            'employee',
            'performanceReports',
            'tasks',
            'taskStats',
            'documents',
            'documentStats',
            'documentTree',
            'reports',
            'reportStats'
        );
    }

    /**
     * Get employee profile data visible to coordinator (no performance reports).
     */
    public function getEmployeeProfileForCoordinator(int $employeeId): array
    {
        $data = $this->getEmployeeProfile($employeeId);
        $data['performanceReports'] = collect(); // Coordinator cannot see performance reports

        return $data;
    }

    /**
     * Auto-assign the employee number for new coordinator/faculty accounts.
     *
     * Email is intentionally NOT auto-generated. Until SMTP is wired up, the
     * field is optional and left NULL when the admin does not supply one.
     */
    protected function applyGeneratedAccountFields(array &$validated, string $role): void
    {
        $generator = app(EmployeeNumberGenerator::class);
        $validated['employee_no'] = $generator->next($validated['program'], $role);
    }

    private function accountCreatedActivity(string $roleName, array $validated): string
    {
        $line = sprintf(
            'Created %s account: %s · Program: %s · Subjects: %s',
            $roleName,
            $validated['full_name'],
            $validated['program'],
            \App\Support\CourseAssignment::summary($validated['course_ids'] ?? [])
        );

        return \Illuminate\Support\Str::limit($line, 250);
    }

    private function validateProgramAssignments(array $data): void
    {
        validator($data, [
            'program' => ['required', \Illuminate\Validation\Rule::in(\App\Models\Program::codes())],
            'course_ids' => ['nullable', 'array'],
            'course_ids.*' => ['integer', \Illuminate\Validation\Rule::exists('courses', 'id')->where('program', $data['program'] ?? '')->where('is_active', true)],
        ])->validate();
    }

    private function namePartsForUpdate(Employee $employee, array $validated): array
    {
        if (filled($validated['first_name'] ?? null) && filled($validated['last_name'] ?? null)) {
            return [
                'first_name' => $validated['first_name'],
                'middle_name' => $validated['middle_name'] ?? null,
                'last_name' => $validated['last_name'],
            ];
        }

        if ($validated['full_name'] !== $employee->full_name) {
            // An older full_name-only caller cannot supply trustworthy name parts.
            return ['first_name' => null, 'middle_name' => null, 'last_name' => null];
        }

        return [];
    }

    private function syncCurrentAssignments(User $user, array $data): void
    {
        // An unrelated account edit must not erase teaching assignments or archived history.
        if (!array_key_exists('course_ids', $data)) return;
        $historical = $user->assignedCourses()->where(function ($query) {
            $query->whereNull('courses.program')->orWhere('courses.is_active', false);
        })->pluck('courses.id')->all();
        $user->assignedCourses()->sync(array_unique(array_merge($historical, $data['course_ids'] ?? [])));
    }
}
