<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use App\Models\DashboardLog;
use App\Support\EmployeeNameInput;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    public function edit()
    {
        $user = auth()->user();
        $employee = $user->employee;
        $canEditFullName = $user->hasOversightAccess() || $user->isProgramCoordinator();
        $canEditEmployeeNo = $user->isDean();
        $teachingAssignments = $user->isFaculty() || $user->isProgramCoordinator()
            ? app(\App\Services\TeachingAssignmentService::class)->current($user)
            : null;

        return view('profile.edit', compact('user', 'employee', 'canEditFullName', 'canEditEmployeeNo', 'teachingAssignments'));
    }

    public function update(Request $request)
    {
        $user = auth()->user();
        $employee = $user->employee;
        $canEditFullName = $user->hasOversightAccess() || $user->isProgramCoordinator();
        $canEditEmployeeNo = $user->isDean();

        $rules = [
            // Email is optional (no SMTP wired up) and not enforced unique
            // because the system uses username for identity. Once SMTP lands
            // we may re-introduce uniqueness then.
            'email' => 'nullable|email|max:45',
        ];

        if ($canEditFullName) {
            $rules += $employee
                ? EmployeeNameInput::prepareForUpdate($request, $employee)
                : EmployeeNameInput::prepare($request);
        }

        if ($canEditEmployeeNo) {
            $rules['employee_no'] = 'nullable|string|max:20|regex:/^[A-Za-z0-9-]+$/|unique:employees,employee_no,'.optional($employee)->employee_id.',employee_id';
        }

        $validated = $request->validate($rules, [
            'employee_no.regex' => 'Employee number may only contain letters, numbers, and dashes.',
        ]);

        DB::transaction(function () use ($user, $employee, $validated, $canEditFullName, $canEditEmployeeNo) {
            $userData = ['email' => ($validated['email'] ?? null) ?: null];
            if ($canEditFullName) $userData['name'] = $validated['full_name'];
            $user->update($userData);

            // Program and (for non-Dean users) employee number are managed through employee management.
            $employeeData = [];
            if ($canEditEmployeeNo) $employeeData['employee_no'] = $validated['employee_no'] ?? null;
            if ($canEditFullName) {
                $employeeData['full_name'] = $validated['full_name'];
                if (filled($validated['first_name'] ?? null) && filled($validated['last_name'] ?? null)) {
                    $employeeData += [
                        'first_name' => $validated['first_name'],
                        'middle_name' => $validated['middle_name'] ?? null,
                        'last_name' => $validated['last_name'],
                    ];
                } elseif ($employee && $validated['full_name'] !== $employee->full_name) {
                    $employeeData += ['first_name' => null, 'middle_name' => null, 'last_name' => null];
                }
            }
            if ($employee && $employeeData) $employee->update($employeeData);
        });

        DashboardLog::create([
            'user_id' => auth()->id(),
            'activity' => 'Updated own profile',
            'activity_type' => 'profile_update',
            'visibility' => 'own',
        ]);

        return redirect()->back()->with('success', 'Profile updated successfully');
    }

    public function uploadAvatar(Request $request)
    {
        $request->validate([
            'avatar' => 'required|file|image|mimes:jpg,jpeg,png,webp|max:2048',
        ], [
            'avatar.required' => 'Please choose a photo to upload.',
            'avatar.image' => 'The file must be an image (JPG, PNG, or WebP).',
            'avatar.mimes' => 'Only JPG, PNG, and WebP images are allowed.',
            'avatar.max' => 'The photo must be 2MB or smaller.',
        ]);

        $user = auth()->user();
        $previous = $user->avatar_path;

        $path = $request->file('avatar')->store('avatars', 'public');
        $user->update(['avatar_path' => $path]);

        if ($previous && $previous !== $path) {
            try {
                Storage::disk('public')->delete($previous);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        DashboardLog::create([
            'user_id' => auth()->id(),
            'activity' => 'Updated profile picture',
            'activity_type' => 'profile_update',
            'visibility' => 'own',
        ]);

        return redirect()->back()->with('success', 'Profile picture updated successfully');
    }

    public function changePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required',
            'new_password' => ['required', 'confirmed', Password::min(8)],
        ]);

        if (!Hash::check($validated['current_password'], auth()->user()->password)) {
            return back()->withErrors(['current_password' => 'Current password is incorrect']);
        }

        auth()->user()->update([
            'password' => Hash::make($validated['new_password']),
        ]);

        DashboardLog::create([
            'user_id' => auth()->id(),
            'activity' => 'Changed own password',
            'activity_type' => 'password_change',
            'visibility' => 'own',
        ]);

        return redirect()->back()->with('success', 'Password changed successfully');
    }
}
