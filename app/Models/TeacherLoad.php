<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherLoad extends Model
{
    protected $guarded = [];
    protected $attributes = ['lock_version' => 1, 'revision_number' => 1, 'status' => 'draft'];
    protected $hidden = ['revision_draft', 'revision_reason'];

    protected $casts = [
        'finalized_at' => 'datetime',
        'total_units' => 'decimal:2',
        'total_load' => 'decimal:3',
        'revision_draft' => 'array',
        'lock_version' => 'integer',
        'revision_number' => 'integer',
    ];

    public function employee() { return $this->belongsTo(Employee::class, 'employee_id', 'employee_id'); }
    public function schoolYear() { return $this->belongsTo(SchoolYear::class); }
    public function items() { return $this->hasMany(TeacherLoadItem::class)->orderBy('position'); }
    public function revisions() { return $this->hasMany(TeacherLoadRevision::class)->orderByDesc('revision_number'); }

    public function scopeVisibleTo($query, User $viewer)
    {
        if ($viewer->hasOversightAccess()) return $query;
        if ($viewer->isProgramCoordinator()) {
            return $query->whereIn('program', \App\Support\CourseCatalog::programsForUser($viewer));
        }
        if ($viewer->isFaculty()) {
            return $query->where('employee_id', $viewer->employee?->employee_id ?? 0)->whereIn('status', ['finalized', 'void']);
        }
        return $query->whereRaw('1 = 0');
    }
}
