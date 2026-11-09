<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentCategory extends Model
{
    protected $primaryKey = 'category_id';
    
    protected $fillable = [
        'category_name',
        'color',
        'created_by', 'owner_id', 'description', 'is_active', 'scope_key', 'name_key',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function scopeAccessibleTo($query, User $user)
    {
        return $query->whereNotNull('created_by')->where(fn ($q) => $q->whereNull('owner_id')->orWhere('owner_id', $user->id));
    }

    public function canManage(User $user): bool
    {
        return $this->created_by && ($this->owner_id ? (int) $this->owner_id === (int) $user->id : $user->hasOversightAccess());
    }

    public function folders()
    {
        return $this->hasMany(Folder::class, 'document_category_id', 'category_id');
    }

    public function documents()
    {
        return $this->hasMany(Document::class, 'category_id', 'category_id');
    }
}
