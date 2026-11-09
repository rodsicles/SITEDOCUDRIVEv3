<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Support\CoordinatorDepartment;

trait AuthorizesSubmissionReview
{
    protected function submissionReviewer(): User
    {
        $user = auth()->user();

        if (!$user->isDeanOrSecretary() && !$user->isProgramCoordinator()) {
            abort(403, 'You are not allowed to review submissions.');
        }

        if ($user->isProgramCoordinator()) {
            CoordinatorDepartment::require($user);
        }

        return $user;
    }
}
