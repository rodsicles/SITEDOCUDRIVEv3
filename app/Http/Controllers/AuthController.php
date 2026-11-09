<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Employee;
use App\Models\DashboardLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function showRoleSelection()
    {
        if (Auth::check()) {
            $role = Auth::user()->role->role_name;
            return match($role) {
                'Dean'                => redirect()->route('dean.dashboard'),
                'Program Coordinator' => redirect()->route('coordinator.dashboard'),
                'Faculty Employee'    => redirect()->route('faculty.dashboard'),
                'Secretary', 'Admin'  => redirect()->route('dean.dashboard'),
                default               => redirect()->route('login'),
            };
        }

        return response()->view('auth.login')->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'        => 'no-cache',
            'Expires'       => '0',
        ]);
    }

    public function showLogin()
    {
        if (Auth::check()) {
            $role = Auth::user()->role->role_name;
            return match($role) {
                'Dean'                => redirect()->route('dean.dashboard'),
                'Program Coordinator' => redirect()->route('coordinator.dashboard'),
                'Faculty Employee'    => redirect()->route('faculty.dashboard'),
                'Secretary', 'Admin'  => redirect()->route('dean.dashboard'),
                default               => redirect()->route('login'),
            };
        }

        return response()->view('auth.login')->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'        => 'no-cache',
            'Expires'       => '0',
        ]);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        // Per-username + IP rate limiter (defence in depth alongside the
        // route-level IP throttle): 5 attempts / minute / (username + IP).
        $throttleKey = Str::lower($credentials['username']) . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            DashboardLog::create([
                'user_id'       => null,
                'activity'      => 'Login throttled for username: ' . $credentials['username'],
                'activity_type' => 'login_throttled',
                'visibility'    => 'dean',
            ]);
            throw ValidationException::withMessages([
                'username' => "Too many login attempts. Please try again in {$seconds} seconds.",
            ])->status(429);
        }

        if (Auth::attempt(['username' => $credentials['username'], 'password' => $credentials['password'], 'status' => 'Active'])) {
            RateLimiter::clear($throttleKey);
            $user = Auth::user();
            $role = $user->role->role_name;

            $request->session()->regenerate();

            DashboardLog::create([
                'user_id' => Auth::id(),
                'activity' => 'User logged in',
                'activity_type' => 'login',
            ]);

            // Mandatory-change gate: if the admin (Dean / Coordinator) flagged
            // this account, the user is locked into the change-password screen
            // until they complete the rotation. This protects the creator from
            // liability over the temporary password they handed out.
            if ($user->must_change_password) {
                return redirect()->route('password.force-change.show');
            }

            return match($role) {
                'Dean' => redirect()->route('dean.dashboard'),
                'Program Coordinator' => redirect()->route('coordinator.dashboard'),
                'Faculty Employee' => redirect()->route('faculty.dashboard'),
                'Secretary', 'Admin' => redirect()->route('dean.dashboard'),
                default => redirect()->route('login'),
            };
        }

        // Count this failed attempt (decay 60s).
        RateLimiter::hit($throttleKey, 60);

        DashboardLog::create([
            'user_id' => null,
            'activity' => 'Failed login attempt for username: ' . $credentials['username'],
            'activity_type' => 'login_failed',
            'visibility' => 'dean',
        ]);

        return back()->withErrors([
            'username' => 'Invalid credentials.',
        ])->withInput($request->only('username'));
    }

    /**
     * Render the locked, blurred-overlay password-change interface.
     * Reachable only when the user has must_change_password = true.
     */
    public function showForceChange()
    {
        $user = Auth::user();
        if (!$user) {
            return redirect()->route('login');
        }

        // If the flag has already been cleared, kick the user back to their dashboard.
        if (!$user->must_change_password) {
            $role = $user->role->role_name;
            return match($role) {
                'Dean'                => redirect()->route('dean.dashboard'),
                'Program Coordinator' => redirect()->route('coordinator.dashboard'),
                'Faculty Employee'    => redirect()->route('faculty.dashboard'),
                'Secretary', 'Admin'  => redirect()->route('dean.dashboard'),
                default               => redirect()->route('login'),
            };
        }

        return response()->view('auth.force-password-change')->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'        => 'no-cache',
            'Expires'       => '0',
        ]);
    }

    /**
     * Process the mandatory password rotation. On success, clears the flag,
     * stamps password_changed_at, writes a chain-of-custody audit record,
     * and releases the user to their normal dashboard.
     */
    public function forceChange(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return redirect()->route('login');
        }

        $validated = $request->validate([
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:8|max:40|confirmed|different:current_password',
        ], [
            'new_password.different' => 'Your new password must be different from the temporary one.',
        ]);

        if (!Hash::check($validated['current_password'], $user->password)) {
            DashboardLog::create([
                'user_id'       => $user->id,
                'activity'      => 'Failed forced-password-change attempt (wrong current password)',
                'activity_type' => 'password_force_change_failed',
                'visibility'    => 'dean',
            ]);
            return back()->withErrors([
                'current_password' => 'The current (temporary) password is incorrect.',
            ]);
        }

        $user->update([
            'password'              => $validated['new_password'],
            'must_change_password'  => false,
            'password_changed_at'   => now(),
        ]);

        // Re-hash session id so the temp-password session token cannot be reused.
        $request->session()->regenerate();

        DashboardLog::create([
            'user_id'       => $user->id,
            'activity'      => 'User completed mandatory password change on first login',
            'activity_type' => 'password_force_changed',
            'visibility'    => 'dean',
        ]);

        $role = $user->role->role_name;
        return match($role) {
            'Dean'                => redirect()->route('dean.dashboard')->with('success', 'Password updated. Welcome!'),
            'Program Coordinator' => redirect()->route('coordinator.dashboard')->with('success', 'Password updated. Welcome!'),
            'Faculty Employee'    => redirect()->route('faculty.dashboard')->with('success', 'Password updated. Welcome!'),
            'Secretary', 'Admin'  => redirect()->route('dean.dashboard')->with('success', 'Password updated. Welcome!'),
            default               => redirect()->route('login'),
        };
    }

    public function showRegister()
    {
        return view('auth.register');
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:255',
            'username'   => 'required|string|max:50|unique:users,username',
            'email'      => 'nullable|email|max:255',
            'password'   => 'required|string|min:8|max:40',
            'role_id'    => 'required|in:1,2,3,4',
            'program' => 'required|in:BLIS,BSEnSE,BSIT,BSCpE',
        ]);

        $user = User::create([
            'role_id'  => $validated['role_id'],
            'username' => $validated['username'],
            'name'     => $validated['name'],
            'email'    => $validated['email'] ?? null,
            'password' => $validated['password'],
            'status'   => 'Active',
        ]);

        $roleNames = [1 => 'Dean', 2 => 'Program Coordinator', 3 => 'Faculty Employee', 4 => 'Secretary'];

        Employee::create([
            'user_id'     => $user->id,
            'employee_no' => strtoupper(substr($validated['username'], 0, 4)) . str_pad($user->id, 3, '0', STR_PAD_LEFT),
            'full_name'   => $validated['name'],
            'program'  => $validated['program'],
            'position'    => $roleNames[(int) $validated['role_id']],
            'hire_date'   => now()->toDateString(),
        ]);

        return redirect()->route('login')->with('success', 'Account created! You can now log in.');
    }

    public function logout(Request $request)
    {
        DashboardLog::create([
            'user_id' => Auth::id(),
            'activity' => 'User logged out',
            'activity_type' => 'logout',
        ]);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
