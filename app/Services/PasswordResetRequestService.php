<?php

namespace App\Services;

use App\Models\DashboardLog;
use App\Models\Notification;
use App\Models\PasswordResetRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PasswordResetRequestService
{
    /**
     * Hours before a pending request automatically expires.
     */
    public const EXPIRY_HOURS = 24;

    /**
     * Reasons that the public submission can be rejected, returned as codes
     * so the controller can render specific messages.
     */
    public const RESULT_OK = 'ok';
    public const RESULT_NOT_FOUND = 'not_found';
    public const RESULT_INACTIVE = 'inactive';
    public const RESULT_DEAN = 'dean';
    public const RESULT_DUPLICATE = 'duplicate';

    /**
     * Requester role => roles allowed to approve it. Nobody handles their own request.
     * The Dean asks an Admin; an Admin asks the Dean; everyone else may ask the Dean office.
     */
    public const HANDLER_ROLES = [
        Role::DEAN => [Role::ADMIN],
        Role::ADMIN => [Role::DEAN],
        Role::SECRETARY => [Role::DEAN, Role::SECRETARY, Role::ADMIN],
        Role::COORDINATOR => [Role::DEAN, Role::SECRETARY, Role::ADMIN],
        Role::FACULTY => [Role::DEAN, Role::SECRETARY, Role::ADMIN],
    ];

    /** @return list<string> */
    public function handlerRolesFor(User $requester): array
    {
        return self::HANDLER_ROLES[$requester->role?->role_name] ?? [];
    }

    /** @return list<int> Active users who may approve this requester's reset. */
    public function handlerIdsFor(User $requester): array
    {
        return User::where('status', 'Active')
            ->whereKeyNot($requester->id)
            ->whereHas('role', fn ($q) => $q->whereIn('role_name', $this->handlerRolesFor($requester)))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function canHandle(PasswordResetRequest $request, User $handler): bool
    {
        $requester = $request->user;

        return $requester
            && (int) $requester->id !== (int) $handler->id
            && in_array($handler->role?->role_name, $this->handlerRolesFor($requester), true);
    }

    /** Requests this handler is allowed to see and act on (never their own). */
    public function handleableQuery(User $handler)
    {
        $requesterRoles = array_keys(array_filter(
            self::HANDLER_ROLES,
            fn (array $handlers) => in_array($handler->role?->role_name, $handlers, true)
        ));

        return PasswordResetRequest::query()
            ->where('user_id', '!=', $handler->id)
            ->whereHas('user.role', fn ($q) => $q->whereIn('role_name', $requesterRoles));
    }

    private function handlerLabel(User $handler): string
    {
        return match (true) {
            $handler->isSecretary() => 'Secretary',
            $handler->isAdmin() => 'Admin',
            default => 'Dean',
        };
    }

    /**
     * Attempt to file a password reset request for the given username.
     *
     * Returns a status code so the controller can show specific feedback.
     */
    public function submitRequest(string $username, ?string $ip = null): array
    {
        $user = User::with('role', 'employee')
            ->where('username', $username)
            ->first();

        if (!$user) {
            DashboardLog::create([
                'user_id'       => null,
                'activity'      => 'Password reset request for unknown username: ' . $username,
                'activity_type' => 'password_reset_request_not_found',
                'visibility'    => 'dean',
                'ip_address'    => $ip,
            ]);

            return ['result' => self::RESULT_NOT_FOUND];
        }

        if ($user->status !== 'Active') {
            DashboardLog::create([
                'user_id'       => $user->id,
                'activity'      => 'Password reset request blocked (inactive account): ' . $user->username,
                'activity_type' => 'password_reset_request_inactive',
                'visibility'    => 'dean',
                'ip_address'    => $ip,
            ]);

            return ['result' => self::RESULT_INACTIVE];
        }

        // Requests need someone else who may approve them. The Dean's request goes to an
        // active Admin; when no Admin exists the Dean must contact the system administrator.
        $handlerIds = $this->handlerIdsFor($user);
        if ($handlerIds === []) {
            DashboardLog::create([
                'user_id'       => $user->id,
                'activity'      => 'Password reset request blocked (no available approver): ' . $user->username,
                'activity_type' => 'password_reset_request_dean_blocked',
                'visibility'    => 'dean',
                'ip_address'    => $ip,
            ]);

            return ['result' => self::RESULT_DEAN];
        }

        $existingPending = PasswordResetRequest::where('user_id', $user->id)
            ->pending()
            ->notExpired()
            ->first();

        if ($existingPending) {
            return ['result' => self::RESULT_DUPLICATE];
        }

        DB::transaction(function () use ($user, $ip, $handlerIds) {
            $request = PasswordResetRequest::create([
                'user_id'      => $user->id,
                'status'       => PasswordResetRequest::STATUS_PENDING,
                'expires_at'   => now()->addHours(self::EXPIRY_HOURS),
                'requested_ip' => $ip,
            ]);

            $userName = $user->employee->full_name ?? $user->username;
            $roleLabel = $user->role->role_name ?? 'User';

            // Notify only the people who may approve this request.
            $now = now();
            Notification::insert(array_map(fn ($handlerId) => [
                'user_id' => $handlerId,
                'message' => "{$userName} ({$roleLabel}) requested a password reset. Review it under Password Reset Requests.",
                'tone' => Notification::TONE_DANGER,
                'action_url' => route('password-reset-requests.index'),
                'is_read' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ], $handlerIds));

            DashboardLog::create([
                'user_id'       => $user->id,
                'activity'      => 'Password reset request created for ' . $user->username,
                'activity_type' => 'password_reset_request_created',
                'visibility'    => 'dean',
                'ip_address'    => $ip,
            ]);
        });

        return ['result' => self::RESULT_OK];
    }

    /**
     * Approve a pending request: generate a temporary password, force a
     * mandatory rotation on next login, and return the plaintext temp
     * password ONCE so the handler can hand it over in person. The plaintext
     * is never persisted.
     */
    public function approve(PasswordResetRequest $request, User $handler, ?string $note = null): string
    {
        abort_unless($this->canHandle($request, $handler), 403, 'You cannot handle this password reset request.');
        $note = trim((string) $note);

        if ($request->status !== PasswordResetRequest::STATUS_PENDING) {
            throw new \RuntimeException('This request has already been handled.');
        }

        if ($request->isExpired()) {
            $request->update(['status' => PasswordResetRequest::STATUS_EXPIRED]);
            throw new \RuntimeException('This request has expired.');
        }

        if ($request->user->isDean() && mb_strlen($note) < 5) {
            throw new \RuntimeException('Enter a reason (at least 5 characters) before resetting the Dean account.');
        }

        $tempPassword = $this->generateTempPassword();

        DB::transaction(function () use ($request, $handler, $tempPassword, $note) {
            $user = $request->user;
            $user->update([
                'password'             => Hash::make($tempPassword),
                'must_change_password' => true,
                'password_changed_at'  => null,
            ]);

            $request->update([
                'status'     => PasswordResetRequest::STATUS_APPROVED,
                'handled_by' => $handler->id,
                'handled_at' => now(),
                'reason'     => $note !== '' ? \Illuminate\Support\Str::limit($note, 255, '') : null,
            ]);

            $handlerRole = $this->handlerLabel($handler);
            $handlerName = $handler->employee->full_name ?? $handler->username;
            Notification::create([
                'user_id' => $user->id,
                'message' => "Your password was reset by the {$handlerRole} ({$handlerName}). Please contact them to receive your temporary password. You will be required to set a new password on first login.",
                'tone'    => Notification::TONE_SUCCESS,
            ]);

            DashboardLog::create([
                'user_id'        => $handler->id,
                'target_user_id' => $user->id,
                'activity'       => \Illuminate\Support\Str::limit('Approved password reset request for ' . $user->username . ($note !== '' ? ' (reason: ' . $note . ')' : ''), 250),
                'activity_type'  => 'password_reset_request_approved',
                'visibility'     => 'dean',
            ]);
        });

        return $tempPassword;
    }

    public function deny(PasswordResetRequest $request, User $handler, ?string $reason = null): void
    {
        abort_unless($this->canHandle($request, $handler), 403, 'You cannot handle this password reset request.');

        if ($request->status !== PasswordResetRequest::STATUS_PENDING) {
            throw new \RuntimeException('This request has already been handled.');
        }

        DB::transaction(function () use ($request, $handler, $reason) {
            $request->update([
                'status'     => PasswordResetRequest::STATUS_DENIED,
                'handled_by' => $handler->id,
                'handled_at' => now(),
                'reason'     => $reason,
            ]);

            $user = $request->user;
            $message = 'Your password reset request was denied.';
            if ($reason) {
                $message .= ' Reason: ' . $reason;
            }
            $message .= ' Please contact the Dean or Admin directly if you need further assistance.';

            Notification::create([
                'user_id' => $user->id,
                'message' => $message,
                'tone'    => Notification::TONE_DANGER,
            ]);

            DashboardLog::create([
                'user_id'        => $handler->id,
                'target_user_id' => $user->id,
                'activity'       => 'Denied password reset request for ' . $user->username
                    . ($reason ? ' (reason: ' . $reason . ')' : ''),
                'activity_type'  => 'password_reset_request_denied',
                'visibility'     => 'dean',
            ]);
        });
    }

    /**
     * Mark expired pending requests so they don't pollute the queue.
     */
    public function expireOverdue(): int
    {
        return PasswordResetRequest::pending()
            ->where('expires_at', '<=', now())
            ->update(['status' => PasswordResetRequest::STATUS_EXPIRED]);
    }

    /**
     * Generate a temporary password using an unambiguous alphabet so users
     * don't confuse 0/O, 1/l/I, etc. when typing it in.
     */
    public function generateTempPassword(int $length = 12): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }
}
