<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dean/Secretary: all submissions.
 * Program Coordinator: own + faculty in the programs they handle.
 * Faculty: own uploads only (+ explicit recipients when applicable).
 */
trait ScopesSubmissionVisibility
{
    abstract protected function submissionOwnerColumn(): string;

    abstract protected function submissionSubmitterRelation(): string;

    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        if ($viewer->isDeanOrSecretary()) {
            return $query;
        }

        $ownerColumn = $this->submissionOwnerColumn();
        $submitterRelation = $this->submissionSubmitterRelation();

        if ($viewer->isProgramCoordinator()) {
            $programs = \App\Support\CoordinatorDepartment::programs($viewer);
            if (!$programs) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where(function ($q) use ($viewer, $programs, $ownerColumn, $submitterRelation) {
                $q->where($ownerColumn, $viewer->id)
                    ->orWhereHas($submitterRelation, function ($subQ) use ($programs) {
                        $subQ->whereHas('role', fn ($r) => $r->where('role_name', 'Faculty Employee'))
                            ->whereHas('employee', fn ($e) => $e->whereIn('program', $programs));
                    });
            });
        }

        return $query->where(function ($q) use ($viewer, $ownerColumn) {
            $q->where($ownerColumn, $viewer->id);

            if (method_exists($this, 'recipients')) {
                $q->orWhereHas('recipients', fn ($r) => $r->where('users.id', $viewer->id));
            }
        });
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->visibleTo($user);
    }

    public function isVisibleTo(User $viewer): bool
    {
        if ($viewer->isDeanOrSecretary()) {
            return true;
        }

        $ownerColumn = $this->submissionOwnerColumn();
        $ownerId = (int) $this->{$ownerColumn};

        if ($viewer->isProgramCoordinator()) {
            if ($ownerId === (int) $viewer->id) {
                return true;
            }

            $submitter = $this->{$this->submissionSubmitterRelation()};
            if (!$submitter || !$submitter->isFaculty()) {
                return false;
            }

            return \App\Support\CoordinatorDepartment::handles($viewer, optional($submitter->employee)->program);
        }

        if ($ownerId === (int) $viewer->id) {
            return true;
        }

        if (method_exists($this, 'recipients')) {
            return $this->recipients()->where('users.id', $viewer->id)->exists();
        }

        return false;
    }
}
