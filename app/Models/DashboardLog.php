<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DashboardLog extends Model
{
    use HasFactory;

    protected $primaryKey = 'log_id';
    
    protected $fillable = [
        'user_id',
        'target_user_id',
        'activity',
        'activity_type',
        'visibility',
        'ip_address',
        'log_date',
    ];

    protected $casts = [
        'log_date' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (DashboardLog $log) {
            if (empty($log->log_date)) {
                $log->log_date = now();
            }

            if (empty($log->ip_address) && request()) {
                $log->ip_address = request()->ip();
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function targetUser()
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    /**
     * Get filtered logs based on user role
     */
    public static function getFilteredLogs($user, $limit = 10)
    {
        $query = self::with(['user.employee', 'targetUser.employee']);

        if ($user->hasOversightAccess()) {
            // Dean and Admin see everything
            $query->latest('log_date');
        } elseif ($user->isProgramCoordinator()) {
            $coordinatorDept = \App\Support\CoordinatorDepartment::programs($user);

            if (!$coordinatorDept) {
                $query->where(function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->orWhere('target_user_id', $user->id);
                });
            } else {
                $query->where(function ($q) use ($user, $coordinatorDept) {
                    $q->where('user_id', $user->id)
                        ->orWhere('target_user_id', $user->id)
                        ->orWhereHas('user', function ($subQ) use ($coordinatorDept) {
                            $subQ->whereHas('role', fn ($r) => $r->where('role_name', Role::FACULTY))
                                ->whereHas('employee', function ($empQ) use ($coordinatorDept) {
                                    $empQ->whereIn('program', $coordinatorDept);
                                });
                        });
                });
            }
        } else { // Faculty
            // Faculty sees:
            // 1. Only their own activities
            // 2. Activities where they are the target (e.g., password reset by coordinator)
            $query->where(function($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhere('target_user_id', $user->id);
            });
        }

        return $query->latest('log_date')->limit($limit)->get();
    }

    /**
     * Get paginated filtered logs based on user role
     */
    public static function getPaginatedLogs($user, $perPage = 20, array $filters = [])
    {
        $query = self::roleScopedQuery($user)->with(['user.employee', 'targetUser.employee']);

        if (!empty($filters['q'])) {
            $term = $filters['q'];
            $query->where('activity', 'like', "%{$term}%");
        }

        if (!empty($filters['activity_type'])) {
            $query->where('activity_type', $filters['activity_type']);
        }

        return $query->latest('log_date')->paginate($perPage)->withQueryString();
    }

    public static function visibleActivityTypes($user)
    {
        return self::roleScopedQuery($user)
            ->whereNotNull('activity_type')
            ->distinct()
            ->orderBy('activity_type')
            ->pluck('activity_type');
    }

    protected static function roleScopedQuery($user)
    {
        $query = self::query();

        if ($user->hasOversightAccess()) {
            return $query;
        }

        if ($user->isProgramCoordinator()) {
            $coordinatorDept = \App\Support\CoordinatorDepartment::programs($user);

            if (!$coordinatorDept) {
                return $query->where(function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->orWhere('target_user_id', $user->id);
                });
            }

            return $query->where(function ($q) use ($user, $coordinatorDept) {
                $q->where('user_id', $user->id)
                    ->orWhere('target_user_id', $user->id)
                    ->orWhereHas('user', function ($subQ) use ($coordinatorDept) {
                        $subQ->where('role_id', 3)
                            ->whereHas('employee', function ($empQ) use ($coordinatorDept) {
                                $empQ->whereIn('program', $coordinatorDept);
                            });
                    });
            });
        }

        return $query->where(function ($q) use ($user) {
            $q->where('user_id', $user->id)
                ->orWhere('target_user_id', $user->id);
        });
    }
}
