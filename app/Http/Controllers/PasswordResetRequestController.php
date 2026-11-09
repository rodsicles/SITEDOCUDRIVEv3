<?php

namespace App\Http\Controllers;

use App\Models\PasswordResetRequest;
use App\Services\PasswordResetRequestService;
use Illuminate\Http\Request;

class PasswordResetRequestController extends Controller
{
    public function __construct(private PasswordResetRequestService $service)
    {
    }

    public function index(Request $request)
    {
        // Auto-expire any overdue pending requests before listing.
        $this->service->expireOverdue();

        $tab = $request->query('tab', 'pending');

        $query = $this->service->handleableQuery($request->user())
            ->with(['user.employee', 'user.role', 'handler.employee'])
            ->latest('created_at');

        if ($tab === 'handled') {
            $query->whereIn('status', [
                PasswordResetRequest::STATUS_APPROVED,
                PasswordResetRequest::STATUS_DENIED,
                PasswordResetRequest::STATUS_EXPIRED,
            ]);
        } else {
            $query->where('status', PasswordResetRequest::STATUS_PENDING);
        }

        $requests = $query->paginate(15)->withQueryString();

        $pendingCount = $this->service->handleableQuery($request->user())->pending()->count();

        return view('dean.password-reset-requests', [
            'requests'     => $requests,
            'tab'          => $tab,
            'pendingCount' => $pendingCount,
            'tempPassword' => session('temp_password'),
            'tempPasswordForUser' => session('temp_password_user'),
        ]);
    }

    public function approve(Request $request, int $id)
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        $resetRequest = PasswordResetRequest::with('user.employee', 'user.role')->findOrFail($id);
        abort_unless($this->service->canHandle($resetRequest, auth()->user()), 403, 'You cannot handle this password reset request.');

        try {
            $tempPassword = $this->service->approve($resetRequest, auth()->user(), $validated['reason'] ?? null);
        } catch (\RuntimeException $e) {
            return redirect()->route('password-reset-requests.index')
                ->withErrors(['error' => $e->getMessage()]);
        }

        $userLabel = $resetRequest->user->employee->full_name
            ?? $resetRequest->user->username;

        return redirect()
            ->route('password-reset-requests.index')
            ->with('success', 'Password reset approved. Hand the temporary password to the user securely.')
            ->with('temp_password', $tempPassword)
            ->with('temp_password_user', $userLabel);
    }

    public function deny(Request $request, int $id)
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        $resetRequest = PasswordResetRequest::with('user.role')->findOrFail($id);
        abort_unless($this->service->canHandle($resetRequest, auth()->user()), 403, 'You cannot handle this password reset request.');

        try {
            $this->service->deny($resetRequest, auth()->user(), $validated['reason'] ?? null);
        } catch (\RuntimeException $e) {
            return redirect()->route('password-reset-requests.index')
                ->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()
            ->route('password-reset-requests.index')
            ->with('success', 'Password reset request denied.');
    }
}
