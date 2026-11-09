<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'role_id',
        'username',
        'name',
        'email',
        'password',
        'status',
        'must_change_password',
        'password_changed_at',
        'avatar_path',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id', 'role_id');
    }

    public function employee()
    {
        return $this->hasOne(Employee::class, 'user_id');
    }

    public function tasksAssigned()
    {
        return $this->hasMany(Task::class, 'assigned_to');
    }

    public function tasksCreated()
    {
        return $this->hasMany(Task::class, 'assigned_by');
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class, 'user_id', 'id');
    }

    public function documents()
    {
        return $this->hasMany(Document::class, 'uploaded_by');
    }

    public function calendarEvents()
    {
        return $this->hasMany(CalendarEvent::class, 'created_by');
    }

    public function eventAttendances()
    {
        return $this->hasMany(EventAttendee::class, 'user_id');
    }

    public function documentFavorites()
    {
        return $this->hasMany(DocumentFavorite::class, 'user_id');
    }

    public function documentViews()
    {
        return $this->hasMany(DocumentView::class, 'user_id');
    }

    public function documentFilters()
    {
        return $this->hasMany(DocumentFilter::class, 'user_id');
    }

    public function documentRequestsCreated()
    {
        return $this->hasMany(DocumentRequest::class, 'requested_by');
    }

    public function documentRequestsReceived()
    {
        return $this->hasMany(DocumentRequestRecipient::class, 'user_id');
    }

    public function savedDocumentSearches()
    {
        return $this->hasMany(SavedDocumentSearch::class);
    }

    public function announcements()
    {
        return $this->hasMany(Announcement::class, 'author_id');
    }

    public function professionalDevelopments()
    {
        return $this->hasMany(ProfessionalDevelopment::class, 'user_id');
    }

    public function reports()
    {
        return $this->hasMany(Report::class, 'submitted_by');
    }

    /**
     * Courses explicitly assigned to this user (Faculty or Coordinator).
     * When a faculty member has assigned courses, only those subjects appear
     * in document upload pickers (Teaching Guides / Exam Questionnaires).
     */
    public function assignedCourses()
    {
        return $this->belongsToMany(\App\Models\Course::class, 'faculty_courses', 'user_id', 'course_id');
    }

    public function taskAttachments()
    {
        return $this->hasMany(TaskAttachment::class, 'uploaded_by');
    }

    public function isDean()
    {
        return $this->role->role_name === 'Dean';
    }

    public function isProgramCoordinator()
    {
        return $this->role->role_name === 'Program Coordinator';
    }

    public function isFaculty()
    {
        return $this->role->role_name === 'Faculty Employee';
    }

    public function isSecretary()
    {
        return $this->role->role_name === 'Secretary';
    }

    public function isAdmin(): bool
    {
        return $this->role?->role_name === Role::ADMIN;
    }

    /**
     * Dean or Admin: day-to-day oversight across every program (Teacher's Load,
     * course sections, document requests, announcements). Dean-only actions
     * such as backups, purges and school-year archiving keep using isDean().
     */
    public function hasOversightAccess(): bool
    {
        return $this->isDean() || $this->isAdmin();
    }

    /**
     * The user's own program, plus any extra programs a coordinator handles.
     *
     * @return list<string>
     */
    public function handledPrograms(): array
    {
        if ($this->isProgramCoordinator()) {
            return \App\Support\CoordinatorDepartment::programs($this);
        }

        $program = optional($this->employee)->program;

        return $program ? [$program] : [];
    }

    /**
     * Dean office access shared by Dean, Secretary and Admin (all-program visibility,
     * review queues, /dean routes). Use isDean() for actions reserved to the Dean.
     */
    public function isDeanOrSecretary()
    {
        return $this->isDean() || $this->isSecretary() || $this->isAdmin();
    }

    public function canManageDocuments()
    {
        return $this->isDeanOrSecretary() || $this->isProgramCoordinator();
    }

    public function canUploadSharedDocuments(): bool
    {
        return $this->canManageDocuments();
    }

    /**
     * Considered online if seen within the last 5 minutes.
     */
    public function isOnline(): bool
    {
        return $this->last_seen_at && $this->last_seen_at->gt(now()->subMinutes(5));
    }

    public function avatarUrl(): ?string
    {
        if (!$this->avatar_path) {
            return null;
        }

        if (!\Illuminate\Support\Facades\Storage::disk('public')->exists($this->avatar_path)) {
            return null;
        }

        // asset() follows the current request host/port; Storage::url() uses APP_URL
        // and breaks when the app is served on a different port (e.g. artisan serve :8000).
        return asset('storage/'.$this->avatar_path).'?v='.substr(sha1($this->avatar_path), 0, 8);
    }

    public function initials(): string
    {
        $name = optional($this->employee)->full_name ?: $this->username;

        return strtoupper(substr($name, 0, 2));
    }
}
