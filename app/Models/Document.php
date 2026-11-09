<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use HasFactory, SoftDeletes;

    protected $primaryKey = 'document_id';
    
    protected $fillable = [
        'uploaded_by',
        'folder_id',
        'trashed_folder_id',
        'deleted_by',
        'document_title',
        'subject',
        'file_path',
        'original_filename',
        'file_size',
        'document_type',
        'category',
        'category_id',
        'school_year_id',
        'tags',
        'version',
    ];

    // Tags are stored as comma-separated string
    public function getTagsArrayAttribute()
    {
        return $this->tags ? explode(',', $this->tags) : [];
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function category()
    {
        return $this->belongsTo(DocumentCategory::class, 'category_id', 'category_id');
    }

    public function folder()
    {
        return $this->belongsTo(Folder::class, 'folder_id', 'folder_id');
    }

    public function schoolYear()
    {
        return $this->belongsTo(SchoolYear::class, 'school_year_id');
    }

    public function trashedFolder()
    {
        return $this->belongsTo(Folder::class, 'trashed_folder_id', 'folder_id');
    }

    public function deletedBy()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /**
     * Human-readable original folder path stored when moved to Recycle Bin.
     */
    public function getRecycleBinFolderLabelAttribute(): string
    {
        if (!$this->trashed_folder_id) {
            return 'Uncategorized';
        }

        $folder = $this->relationLoaded('trashedFolder')
            ? $this->trashedFolder
            : Folder::find($this->trashed_folder_id);

        if (!$folder) {
            return 'Uncategorized';
        }

        $names = array_map(fn (Folder $f) => $f->folder_name, $folder->getAncestors());
        $names[] = $folder->folder_name;

        return implode(' / ', $names);
    }

    public function comments()
    {
        return $this->hasMany(DocumentComment::class, 'document_id', 'document_id');
    }

    public function favorites()
    {
        return $this->hasMany(DocumentFavorite::class, 'document_id', 'document_id');
    }

    public function views()
    {
        return $this->hasMany(DocumentView::class, 'document_id', 'document_id');
    }

    /**
     * Read receipts: latest view timestamp per user (excluding the uploader), most recent first.
     */
    public function viewReceipts()
    {
        return $this->views()
            ->with('user.employee')
            ->selectRaw('MAX(id) as id, user_id, MAX(viewed_at) as viewed_at')
            ->where('user_id', '!=', $this->uploaded_by)
            ->groupBy('user_id')
            ->orderByDesc('viewed_at')
            ->get();
    }

    public function recipients()
    {
        return $this->belongsToMany(User::class, 'document_recipients', 'document_id', 'user_id')
            ->withTimestamps();
    }

    public function teachingGuide()
    {
        return $this->hasOne(TeachingGuide::class, 'document_id', 'document_id');
    }

    public const SHAREABLE_CATEGORIES = ['Teaching Guides', 'Exam Questionnaires'];

    // Check if document is favorited by user
    public function isFavoritedBy($userId)
    {
        return $this->favorites()->where('user_id', $userId)->exists();
    }

    // Toggle favorite for user
    public function toggleFavorite($userId)
    {
        $favorite = $this->favorites()->where('user_id', $userId)->first();
        
        if ($favorite) {
            $favorite->delete();
            return false; // Unfavorited
        } else {
            $fav = new DocumentFavorite(['document_id' => $this->document_id]);
            $fav->user_id = $userId;
            $fav->save();
            return true; // Favorited
        }
    }

    /**
     * Check if a user is allowed to view/download this document.
     */
    public function canView(User $user): bool
    {
        $folder = $this->relationLoaded('folder') ? $this->folder : $this->folder()->first();
        if ($folder && ! $folder->canBeViewedBy($user)) {
            return false;
        }

        if ($user->isDeanOrSecretary()) {
            return true;
        }

        if ((int) $this->uploaded_by === (int) $user->id) {
            return true;
        }

        if ($this->recipients()->where('users.id', $user->id)->exists()) {
            return true;
        }

        if ($user->isProgramCoordinator()) {
            $uploader = $this->relationLoaded('uploader') ? $this->uploader : $this->uploader()->with('employee')->first();

            if ($uploader && $uploader->isFaculty()) {
                return \App\Support\CoordinatorDepartment::handles($user, optional($uploader->employee)->program);
            }

            return false;
        }

        if ($this->isSharedWithAllFaculty()) {
            return true;
        }

        return static::query()
            ->whereKey($this->getKey())
            ->visibleTo($user)
            ->exists();
    }

    /**
     * Teaching Guides / Exam Questionnaires with no explicit recipients are visible to all faculty.
     */
    public function isSharedWithAllFaculty(): bool
    {
        if (!in_array($this->category, self::SHAREABLE_CATEGORIES, true)) {
            return false;
        }

        if ($this->recipients()->exists()) {
            return false;
        }

        return $this->uploader && $this->uploader->canUploadSharedDocuments();
    }

    public function examQuestionnaire()
    {
        return $this->hasOne(ExamQuestionnaire::class, 'document_id', 'document_id');
    }

    /**
     * All past versions of this document (newest first).
     */
    public function versions()
    {
        return $this->hasMany(DocumentVersion::class, 'document_id', 'document_id')
            ->orderByDesc('version_number');
    }

    public function canManageVersions(User $user): bool
    {
        return (int) $this->uploaded_by === (int) $user->id
            || $user->isDeanOrSecretary();
    }

    public function searchIndex()
    {
        return $this->hasOne(DocumentSearchIndex::class, 'document_id', 'document_id');
    }

    public function requestSubmissions()
    {
        return $this->hasMany(DocumentRequestRecipient::class, 'submitted_document_id', 'document_id');
    }

    public function getRequestedOriginLabelAttribute(): ?string
    {
        $submission = $this->requestSubmissions()->with('request.requester.role')->first();
        $role = $submission?->request?->requester?->role?->role_name;

        return match ($role) {
            'Dean' => 'Requested by Dean',
            'Admin' => 'Requested by Admin',
            'Program Coordinator' => 'Requested by Coordinator',
            default => $submission ? 'Requested document' : null,
        };
    }

    /**
     * Hide Teaching Guides / Exam Questionnaires until linked submission is approved.
     */
    public function scopeOnlyApprovedShareable($query)
    {
        return $query
            ->where(function ($requestReview) {
                $requestReview->whereDoesntHave('requestSubmissions')
                    ->orWhereHas('requestSubmissions', fn ($submission) => $submission->where('status', 'approved'));
            })
            ->where(function ($q) {
            $q->whereNotIn('category', self::SHAREABLE_CATEGORIES)
                ->orWhereNull('category')
                ->orWhere(function ($sub) {
                    $sub->where('category', 'Teaching Guides')
                        ->whereHas('teachingGuide', fn ($tg) => $tg->where('status', 'approved'));
                })
                ->orWhere(function ($sub) {
                    $sub->where('category', 'Exam Questionnaires')
                        ->whereHas('examQuestionnaire', fn ($eq) => $eq->where('status', 'approved'));
                });
            });
    }

    public function scopeVisibleTo($query, User $user)
    {
        $query->where(function ($folders) use ($user) {
            $folders->whereNull('folder_id')
                ->orWhereHas('folder', fn ($folder) => $folder->visibleTo($user));
        });

        if ($user->isDeanOrSecretary()) {
            return $query;
        }

        if ($user->isProgramCoordinator()) {
            $coordinatorPrograms = \App\Support\CoordinatorDepartment::programs($user);

            return $query->where(function ($q) use ($user, $coordinatorPrograms) {
                $q->where('uploaded_by', $user->id)
                    ->orWhereHas('recipients', fn ($r) => $r->where('users.id', $user->id));

                if ($coordinatorPrograms) {
                    $q->orWhereHas('uploader', function ($subQ) use ($coordinatorPrograms) {
                        $subQ->whereHas('role', fn ($r) => $r->where('role_name', 'Faculty Employee'))
                            ->whereHas('employee', fn ($empQ) => $empQ->whereIn('program', $coordinatorPrograms));
                    });
                }
            });
        }

        // Faculty: own uploads, explicit shares, or dean/secretary/coordinator broadcasts only.
        return $query->where(function ($q) use ($user) {
            $q->where('uploaded_by', $user->id)
                ->orWhereHas('recipients', fn ($r) => $r->where('users.id', $user->id))
                ->orWhere(function ($broadcast) {
                    $broadcast->whereIn('category', self::SHAREABLE_CATEGORIES)
                        ->whereDoesntHave('recipients')
                        ->whereHas('uploader', fn ($u) => $u->whereHas('role', function ($roleQ) {
                            $roleQ->whereIn('role_name', ['Dean', 'Secretary', 'Program Coordinator', 'Admin']);
                        }));
                });
        });
    }

    /**
     * Get filtered documents based on user role
     * Faculty uploads are visible to: owner, coordinator, and dean
     * Coordinator uploads are visible to: owner and dean
     */
    public static function getFilteredDocuments($user, $categoryFilter = null)
    {
        $query = self::with(['uploader.employee', 'category'])->visibleTo($user);

        if ($categoryFilter) {
            if (str_starts_with($categoryFilter, 'managed:')) {
                $query->where('category_id', (int) substr($categoryFilter, 8));
            } else {
                $query->where('category', $categoryFilter);
            }
        }

        return $query;
    }
}
