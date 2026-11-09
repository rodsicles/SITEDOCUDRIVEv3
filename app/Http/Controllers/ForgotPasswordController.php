<?php

namespace App\Http\Controllers;

use App\Services\PasswordResetRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ForgotPasswordController extends Controller
{
    public function __construct(private PasswordResetRequestService $service)
    {
    }

    public function show()
    {
        // An authenticated user has no reason to be on this page; bounce them
        // back to their dashboard. Real password rotation belongs on Profile.
        if (Auth::check()) {
            $role = Auth::user()->role->role_name ?? null;
            return match ($role) {
                'Dean'                => redirect()->route('dean.dashboard'),
                'Program Coordinator' => redirect()->route('coordinator.dashboard'),
                'Faculty Employee'    => redirect()->route('faculty.dashboard'),
                'Secretary', 'Admin'  => redirect()->route('dean.dashboard'),
                default               => redirect()->route('login'),
            };
        }

        return response()->view('auth.forgot-password')->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'        => 'no-cache',
            'Expires'       => '0',
        ]);
    }

    public function submit(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string|max:50',
        ]);

        $username = trim($data['username']);

        // Per-username throttle on top of the route-level IP throttle.
        // 3 attempts per hour per (username + IP).
        $perUserKey = 'forgot-pw:' . Str::lower($username) . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($perUserKey, 3)) {
            $seconds = RateLimiter::availableIn($perUserKey);
            throw ValidationException::withMessages([
                'username' => "Too many requests for this username. Please try again in {$seconds} seconds.",
            ])->status(429);
        }
        RateLimiter::hit($perUserKey, 3600);

        $outcome = $this->service->submitRequest($username, $request->ip());

        return match ($outcome['result']) {
            PasswordResetRequestService::RESULT_NOT_FOUND => back()
                ->withErrors(['username' => 'No account found with this username. Please check and try again.'])
                ->withInput($request->only('username')),

            PasswordResetRequestService::RESULT_INACTIVE => back()
                ->withErrors(['username' => 'This account is inactive. Please contact the Dean or Admin directly.'])
                ->withInput($request->only('username')),

            PasswordResetRequestService::RESULT_DEAN => back()
                ->withErrors(['username' => 'Please contact your system administrator directly to reset this account.'])
                ->withInput($request->only('username')),

            PasswordResetRequestService::RESULT_DUPLICATE => redirect()
                ->route('password.forgot.show')
                ->with('info', 'A reset request is already pending review. Please wait for the Dean or Admin to act on it.'),

            default => redirect()
                ->route('password.forgot.show')
                ->with('success', 'Your request was sent for review. The Dean or Admin will give you a temporary password in person.'),
        };
    }
}
