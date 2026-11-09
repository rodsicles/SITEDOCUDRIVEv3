<?php

namespace App\Services;

use App\Models\ProfessionalDevelopment;
use App\Models\DashboardLog;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Support\UploadStorage;

class ProfessionalDevelopmentService
{
    public function getForUser(int $userId): LengthAwarePaginator
    {
        return ProfessionalDevelopment::where('user_id', $userId)
            ->orderByDesc('date_attended')
            ->paginate(15);
    }

    public function getAll(): LengthAwarePaginator
    {
        return ProfessionalDevelopment::with('user.employee')
            ->orderByDesc('date_attended')
            ->paginate(15);
    }

    public function getSummary(): Collection
    {
        return DB::table('professional_developments')
            ->join('users', 'professional_developments.user_id', '=', 'users.id')
            ->join('employees', 'users.id', '=', 'employees.user_id')
            ->where('users.role_id', 3)
            ->selectRaw('employees.full_name, employees.program, professional_developments.user_id, COUNT(*) as total_trainings, SUM(professional_developments.hours) as total_hours')
            ->groupBy('professional_developments.user_id', 'employees.full_name', 'employees.program')
            ->orderByDesc('total_trainings')
            ->get();
    }

    public function create(array $validated, ?UploadedFile $certificate, User $user): ProfessionalDevelopment
    {
        DB::beginTransaction();

        try {
            $certificatePath = null;
            if ($certificate) {
                $filename = 'cert_' . time() . '_' . $certificate->hashName();
                $certificatePath = UploadStorage::putFileAs("certificates/{$user->id}", $certificate, $filename);
            }

            $pd = ProfessionalDevelopment::create([
                'user_id' => $user->id,
                'seminar_name' => $validated['seminar_name'],
                'date_attended' => $validated['date_attended'],
                'organizer' => $validated['organizer'],
                'hours' => $validated['hours'],
                'certificate_path' => $certificatePath,
                'original_filename' => $certificate ? \App\Support\DocumentNaming::uploadedFilename($certificate) : null,
            ]);

            DashboardLog::create([
                'user_id' => $user->id,
                'activity' => 'Logged training: ' . $validated['seminar_name'],
                'activity_type' => 'training_logged',
                'log_date' => now(),
            ]);

            // Notify supervisors
            $supervisors = User::whereHas('role', fn ($q) => $q->whereIn('role_name', [\App\Models\Role::DEAN, \App\Models\Role::COORDINATOR, \App\Models\Role::ADMIN]))->get();
            $employeeName = $user->employee->full_name ?? $user->username;
            foreach ($supervisors as $supervisor) {
                Notification::create([
                    'user_id' => $supervisor->id,
                    'message' => "{$employeeName} logged a training: {$validated['seminar_name']}",
                    'is_read' => false,
                ]);
            }

            DB::commit();
            return $pd;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update(ProfessionalDevelopment $pd, array $validated, ?UploadedFile $certificate, int $userId): ProfessionalDevelopment
    {
        if ($pd->user_id !== $userId) {
            abort(403, 'Unauthorized');
        }

        if ($certificate) {
            // Delete old certificate
            if ($pd->certificate_path && UploadStorage::exists($pd->certificate_path)) {
                UploadStorage::delete($pd->certificate_path);
            }
            $filename = 'cert_' . time() . '_' . $certificate->hashName();
            $pd->certificate_path = UploadStorage::putFileAs("certificates/{$userId}", $certificate, $filename);
            $pd->original_filename = \App\Support\DocumentNaming::uploadedFilename($certificate);
        }

        $pd->update([
            'seminar_name' => $validated['seminar_name'],
            'date_attended' => $validated['date_attended'],
            'organizer' => $validated['organizer'],
            'hours' => $validated['hours'],
            'certificate_path' => $pd->certificate_path,
            'original_filename' => $pd->original_filename,
        ]);

        DashboardLog::create([
            'user_id' => $userId,
            'activity' => 'Updated training: ' . $validated['seminar_name'],
            'activity_type' => 'training_updated',
            'log_date' => now(),
        ]);

        return $pd;
    }

    public function delete(ProfessionalDevelopment $pd, int $userId): void
    {
        if ($pd->user_id !== $userId) {
            abort(403, 'Unauthorized');
        }

        if ($pd->certificate_path && UploadStorage::exists($pd->certificate_path)) {
            UploadStorage::delete($pd->certificate_path);
        }

        $seminarName = $pd->seminar_name;
        $pd->delete();

        DashboardLog::create([
            'user_id' => $userId,
            'activity' => 'Deleted training: ' . $seminarName,
            'activity_type' => 'training_deleted',
            'log_date' => now(),
        ]);
    }
}
