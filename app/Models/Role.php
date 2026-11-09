<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    public const DEAN = 'Dean';
    public const COORDINATOR = 'Program Coordinator';
    public const FACULTY = 'Faculty Employee';
    public const SECRETARY = 'Secretary';
    public const ADMIN = 'Admin';

    protected $primaryKey = 'role_id';
    
    protected $fillable = [
        'role_name',
    ];

    public static function idFor(string $roleName): int
    {
        return (int) static::where('role_name', $roleName)->valueOrFail('role_id');
    }

    public function users()
    {
        return $this->hasMany(User::class, 'role_id', 'role_id');
    }
}
