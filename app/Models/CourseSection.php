<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\CoordinatorDepartment;

class CourseSection extends Model
{
    protected $fillable = ['program', 'year_level', 'name', 'is_active', 'lock_version'];
    protected $casts = ['is_active' => 'boolean', 'year_level' => 'integer', 'lock_version' => 'integer'];

    public function scopeVisibleTo($query, User $user)
    {
        if ($user->hasOversightAccess()) return $query;
        if ($user->isProgramCoordinator()) return $query->whereIn('program', CoordinatorDepartment::requirePrograms($user));
        return $query->whereRaw('1 = 0');
    }
}
