<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\Employee;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class GlobalSearchService
{
    public function __construct(
        protected DocumentSearchService $documentSearch,
    ) {}

    public function search(User $user, string $query, int $limitPerGroup = 6): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 3) {
            return [];
        }

        $results = [];

        foreach ($this->searchDocuments($user, $query, $limitPerGroup) as $row) {
            $results[] = $row;
        }

        foreach ($this->searchAnnouncements($user, $query, $limitPerGroup) as $row) {
            $results[] = $row;
        }

        foreach ($this->searchUsers($user, $query, $limitPerGroup) as $row) {
            $results[] = $row;
        }

        if ($user->hasOversightAccess() || $user->isProgramCoordinator()) {
            foreach ($this->searchEmployees($user, $query, $limitPerGroup) as $row) {
                $results[] = $row;
            }
        }

        foreach ($this->searchTasks($user, $query, $limitPerGroup) as $row) {
            $results[] = $row;
        }

        return $results;
    }

    private function searchDocuments(User $user, string $query, int $limit): Collection
    {
        return collect($this->documentSearch->globalDocumentHits($user, $query, $limit))
            ->map(fn (array $row) => [
                'title' => $row['title'],
                'subtitle' => ($row['match'] ?? 'Document').' · '.($row['subtitle'] ?? ''),
                'type' => 'Document',
                'url' => $row['url'],
            ]);
    }

    private function searchAnnouncements(User $user, string $query, int $limit): Collection
    {
        return Announcement::query()
            ->active()
            ->visibleTo($user)
            ->where(function ($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                    ->orWhere('body', 'like', "%{$query}%");
            })
            ->ordered()
            ->limit($limit)
            ->get()
            ->map(fn (Announcement $announcement) => [
                'title' => $announcement->title,
                'subtitle' => Str::limit(strip_tags((string) $announcement->body), 80) ?: 'Announcement',
                'type' => 'Announcement',
                'url' => route('announcements.index', ['highlight' => $announcement->announcement_id]),
            ]);
    }

    private function searchUsers(User $user, string $query, int $limit): Collection
    {
        $usersQuery = User::query()
            ->where('status', 'Active')
            ->with(['employee', 'role'])
            ->where(function ($q) use ($query) {
                $q->where('username', 'like', "%{$query}%")
                    ->orWhere('name', 'like', "%{$query}%")
                    ->orWhereHas('employee', fn ($e) => $e->where('full_name', 'like', "%{$query}%"));
            });

        if ($user->isFaculty()) {
            $usersQuery->where('id', $user->id);
        } elseif ($user->isProgramCoordinator()) {
            $programs = \App\Support\CourseCatalog::programsForUser($user);
            $usersQuery->whereHas('employee', fn ($e) => $e->whereIn('program', $programs));
        }

        return $usersQuery
            ->limit($limit)
            ->get()
            ->map(function (User $found) use ($user) {
                $name = $found->employee->full_name ?? $found->username;
                $roleName = $found->role->role_name ?? 'User';
                $dept = $found->employee->program ?? null;

                $url = match (true) {
                    $user->hasOversightAccess() && $found->employee => route('dean.employee-profile', $found->employee->employee_id),
                    $user->hasOversightAccess() => route('dean.employees'),
                    $user->isProgramCoordinator() && $found->employee => route('coordinator.faculty-profile', $found->employee->employee_id),
                    $user->isProgramCoordinator() => route('coordinator.faculty'),
                    default => route('profile.edit'),
                };

                return [
                    'title' => $name,
                    'subtitle' => trim($roleName.($dept ? " · {$dept}" : '')),
                    'type' => 'User',
                    'url' => $url,
                ];
            });
    }

    private function searchEmployees(User $user, string $query, int $limit): Collection
    {
        $employeeQuery = Employee::query()
            ->whereHas('user', fn ($q) => $q->where('status', 'Active'))
            ->where(function ($q) use ($query) {
                $q->where('full_name', 'like', "%{$query}%")
                    ->orWhere('employee_no', 'like', "%{$query}%")
                    ->orWhere('program', 'like', "%{$query}%");
            });

        if ($user->isProgramCoordinator()) {
            $employeeQuery->whereIn('program', \App\Support\CourseCatalog::programsForUser($user));
        }

        return $employeeQuery
            ->limit($limit)
            ->get()
            ->map(function (Employee $employee) use ($user) {
                $url = $user->hasOversightAccess()
                    ? route('dean.employee-profile', $employee->employee_id)
                    : route('coordinator.faculty-profile', $employee->employee_id);

                return [
                    'title' => $employee->full_name,
                    'subtitle' => $employee->program ?? 'Employee',
                    'type' => 'Employee',
                    'url' => $url,
                ];
            });
    }

    private function searchTasks(User $user, string $query, int $limit): Collection
    {
        $tasksQuery = Task::query()->where(function ($q) use ($query) {
            $q->where('task_title', 'like', "%{$query}%")
                ->orWhere('task_description', 'like', "%{$query}%");
        });

        if ($user->isFaculty()) {
            $tasksQuery->where('assigned_to', $user->id);
        } elseif ($user->isProgramCoordinator()) {
            $tasksQuery->where('assigned_by', $user->id);
        }

        $url = match (true) {
            $user->hasOversightAccess() => route('dean.dashboard'),
            $user->isProgramCoordinator() => route('coordinator.tasks'),
            $user->isFaculty() => route('faculty.tasks'),
            default => '#',
        };

        return $tasksQuery
            ->limit($limit)
            ->get()
            ->map(fn (Task $task) => [
                'title' => $task->task_title,
                'subtitle' => 'Status: '.$task->status,
                'type' => 'Task',
                'url' => $url,
            ]);
    }

}
