<?php

namespace App\Services;

use App\Models\Document;
use App\Models\ExamQuestionnaire;
use App\Models\Notification;
use App\Models\TeachingGuide;
use App\Models\User;
use Illuminate\Support\Str;

class NotificationService
{
    /**
     * Create a notification for a single user.
     */
    public function notify(int $userId, string $message, ?string $tone = null, ?string $actionUrl = null): Notification
    {
        return Notification::create([
            'user_id' => $userId,
            'message' => $message,
            'tone' => $tone,
            'action_url' => $this->normalizeActionUrl($actionUrl),
        ]);
    }

    /**
     * Notify multiple users with the same message (bulk insert).
     * Optional $actionUrl is shared by all recipients.
     * Optional $actionUrlResolver(User $user): ?string overrides per recipient.
     *
     * @param  list<int>  $userIds
     * @param  (callable(User): (?string))|null  $actionUrlResolver
     */
    public function notifyMany(array $userIds, string $message, ?string $tone = null, ?string $actionUrl = null, ?callable $actionUrlResolver = null): void
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if ($userIds === []) {
            return;
        }

        if ($actionUrlResolver) {
            $users = User::with('role')->whereIn('id', $userIds)->get()->keyBy('id');
            foreach ($userIds as $userId) {
                $user = $users->get($userId);
                $url = $user ? $actionUrlResolver($user) : $actionUrl;
                $this->notify($userId, $message, $tone, $url);
            }

            return;
        }

        $now = now();
        $normalized = $this->normalizeActionUrl($actionUrl);
        $notifications = array_map(fn ($userId) => [
            'user_id' => $userId,
            'message' => $message,
            'tone' => $tone,
            'action_url' => $normalized,
            'is_read' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ], $userIds);

        Notification::insert($notifications);
    }

    /**
     * Active users with Dean or Secretary role (by role name — not hard-coded IDs).
     *
     * @return list<int>
     */
    public function deanAndSecretaryIds(?int $exceptUserId = null): array
    {
        $query = User::query()
            ->where('status', 'Active')
            ->whereHas('role', fn ($q) => $q->whereIn('role_name', ['Dean', 'Secretary', 'Admin']));

        if ($exceptUserId) {
            $query->where('id', '!=', $exceptUserId);
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Dean, Secretary, and Program Coordinators (for approval workflows).
     *
     * @return list<int>
     */
    public function supervisorIds(?int $exceptUserId = null): array
    {
        $query = User::query()
            ->where('status', 'Active')
            ->whereHas('role', fn ($q) => $q->whereIn('role_name', ['Dean', 'Secretary', 'Admin', 'Program Coordinator']));

        if ($exceptUserId) {
            $query->where('id', '!=', $exceptUserId);
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function notifyDeanAndSecretary(string $message, ?string $tone = null, ?int $exceptUserId = null, ?string $actionUrl = null, ?callable $actionUrlResolver = null): void
    {
        $this->notifyMany($this->deanAndSecretaryIds($exceptUserId), $message, $tone, $actionUrl, $actionUrlResolver);
    }

    /**
     * Notify all supervisors (Dean + Secretary + Program Coordinator).
     */
    public function notifySupervisors(string $message, ?string $tone = null, ?int $exceptUserId = null, ?string $actionUrl = null, ?callable $actionUrlResolver = null): void
    {
        $this->notifyMany($this->supervisorIds($exceptUserId), $message, $tone, $actionUrl, $actionUrlResolver);
    }

    /**
     * Notify Dean/Secretary when someone uploads file(s). Skips self-uploads by Dean/Secretary.
     */
    public function notifyDeanOnFileUpload(
        User $uploader,
        int $fileCount,
        string $title,
        string $category,
        bool $pendingApproval = false,
        ?int $documentId = null,
    ): void {
        if ($uploader->isDeanOrSecretary() || $fileCount < 1) {
            return;
        }

        $uploader->loadMissing('employee');
        $name = $uploader->employee?->full_name ?? $uploader->username ?? 'A user';
        $countLabel = $fileCount === 1 ? '1 file' : "{$fileCount} files";
        $titleLabel = trim($title) !== '' ? "\"{$title}\"" : 'a file';
        $categoryLabel = trim($category) !== '' ? " ({$category})" : '';

        if ($pendingApproval) {
            $message = "{$name} submitted {$countLabel} for your approval: {$titleLabel}{$categoryLabel}. Click to review.";
            $isTeaching = stripos($category, 'Teaching') !== false;

            $this->notifySupervisors(
                $message,
                Notification::TONE_DANGER,
                $uploader->id,
                null,
                function (User $recipient) use ($isTeaching) {
                    return $this->pendingReviewUrlFor($recipient, $isTeaching ? 'tg' : 'eq');
                }
            );

            return;
        }

        $message = "{$name} uploaded {$countLabel}: {$titleLabel}{$categoryLabel}. Click to open.";
        $this->notifyDeanAndSecretary(
            $message,
            null,
            $uploader->id,
            null,
            function (User $recipient) use ($category, $documentId) {
                return $this->documentsUrlFor($recipient, $category, $documentId);
            }
        );
    }

    /**
     * Notify the faculty submitter that their exam questionnaire was approved.
     */
    public function notifyExamQuestionnaireApproved(ExamQuestionnaire $questionnaire, User $reviewer): void
    {
        $submitterId = (int) $questionnaire->submitted_by;
        if ($submitterId <= 0 || $submitterId === (int) $reviewer->id) {
            return;
        }

        $label = $questionnaire->title ?: $questionnaire->subject ?: 'your file';
        $type = strtoupper((string) ($questionnaire->submission_type ?: 'EQ'));
        $examPart = $questionnaire->exam_type ? " ({$questionnaire->exam_type})" : '';
        $reviewerLabel = $this->reviewerLabel($reviewer);
        $actionUrl = $this->facultySubmissionUrl('eq', (int) $questionnaire->id, $questionnaire->document_id);

        $this->notify(
            $submitterId,
            "Your {$type} submission \"{$label}\"{$examPart} has been approved by the {$reviewerLabel}. Click to view.",
            Notification::TONE_SUCCESS,
            $actionUrl,
        );
    }

    /**
     * Notify the faculty submitter that their exam questionnaire was rejected.
     */
    public function notifyExamQuestionnaireRejected(ExamQuestionnaire $questionnaire, User $reviewer): void
    {
        $submitterId = (int) $questionnaire->submitted_by;
        if ($submitterId <= 0 || $submitterId === (int) $reviewer->id) {
            return;
        }

        $label = $questionnaire->title ?: $questionnaire->subject ?: 'your file';
        $type = strtoupper((string) ($questionnaire->submission_type ?: 'EQ'));
        $examPart = $questionnaire->exam_type ? " ({$questionnaire->exam_type})" : '';
        $reviewerLabel = $this->reviewerLabel($reviewer);
        $remarks = trim((string) ($questionnaire->remarks ?? ''));
        $reason = $remarks !== '' ? " Reason: {$remarks}" : '';
        $actionUrl = route('faculty.exam-questionnaires.index');

        $this->notify(
            $submitterId,
            "Your {$type} submission \"{$label}\"{$examPart} was rejected by the {$reviewerLabel}.{$reason}",
            Notification::TONE_DANGER,
            $actionUrl,
        );
    }

    /**
     * Notify the faculty submitter that their teaching guide was approved.
     */
    public function notifyTeachingGuideApproved(TeachingGuide $guide, User $reviewer): void
    {
        $submitterId = (int) $guide->user_id;
        if ($submitterId <= 0 || $submitterId === (int) $reviewer->id) {
            return;
        }

        $label = $guide->title ?: $guide->subject ?: 'your file';
        $reviewerLabel = $this->reviewerLabel($reviewer);
        $actionUrl = $this->facultySubmissionUrl('tg', (int) $guide->id, $guide->document_id);

        $this->notify(
            $submitterId,
            "Your teaching guide \"{$label}\" has been approved by the {$reviewerLabel}. Click to view.",
            Notification::TONE_SUCCESS,
            $actionUrl,
        );
    }

    /**
     * Notify the faculty submitter that their teaching guide was rejected.
     */
    public function notifyTeachingGuideRejected(TeachingGuide $guide, User $reviewer): void
    {
        $submitterId = (int) $guide->user_id;
        if ($submitterId <= 0 || $submitterId === (int) $reviewer->id) {
            return;
        }

        $label = $guide->title ?: $guide->subject ?: 'your file';
        $reviewerLabel = $this->reviewerLabel($reviewer);
        $remarks = trim((string) ($guide->remarks ?? ''));
        $reason = $remarks !== '' ? " Reason: {$remarks}" : '';

        $this->notify(
            $submitterId,
            "Your teaching guide \"{$label}\" was rejected by the {$reviewerLabel}.{$reason}",
            Notification::TONE_DANGER,
            route('faculty.teaching-guides.index'),
        );
    }

    protected function reviewerLabel(User $reviewer): string
    {
        if ($reviewer->isSecretary()) {
            return 'Secretary';
        }
        if ($reviewer->isAdmin()) {
            return 'Admin';
        }
        if ($reviewer->isProgramCoordinator()) {
            return 'Program Coordinator';
        }

        return 'Dean';
    }

    /**
     * Notify the document uploader that the Dean permanently removed their file from the Recycle Bin.
     */
    public function notifyDocumentPermanentlyDeleted(Document $document, User $dean): void
    {
        $uploaderId = (int) $document->uploaded_by;
        if ($uploaderId <= 0 || $uploaderId === (int) $dean->id) {
            return;
        }

        $title = trim((string) ($document->document_title ?? ''));
        $label = $title !== '' ? "\"{$title}\"" : 'your file';

        $this->notify(
            $uploaderId,
            "The Dean permanently deleted {$label} from the Recycle Bin. It cannot be recovered.",
            Notification::TONE_DANGER,
            route('faculty.recycle-bin.index'),
        );
    }

    /**
     * Build a documents deep-link for a recipient (used by DocumentService shared uploads).
     */
    public function documentsActionUrl(User $recipient, string $category, ?int $documentId = null): string
    {
        return $this->documentsUrlFor($recipient, $category, $documentId);
    }

    /**
     * Notify a faculty member or program coordinator that a task was assigned to them.
     */
    public function notifyTaskAssigned(
        int $assigneeUserId,
        string $taskTitle,
        ?User $assignedBy = null,
        ?string $description = null,
        array $attachmentNames = [],
        ?User $assignee = null,
    ): Notification {
        $creator = $assignedBy?->employee?->full_name
            ?? $assignedBy?->name
            ?? $assignedBy?->username
            ?? 'the Dean';

        $assignee ??= User::with('role')->find($assigneeUserId);
        $tasksRoute = $assignee?->isProgramCoordinator()
            ? route('coordinator.tasks')
            : route('faculty.tasks');

        $lines = [
            "New task assigned: \"{$taskTitle}\" from {$creator}.",
        ];

        $description = trim((string) $description);
        if ($description !== '') {
            $lines[] = 'Description: ' . $description;
        }

        if ($attachmentNames !== []) {
            $lines[] = 'Attachment(s): ' . implode(', ', $attachmentNames);
        } else {
            $lines[] = 'Attachment(s): None';
        }

        $lines[] = 'Click to open My Tasks.';

        return $this->notify(
            $assigneeUserId,
            implode("\n", $lines),
            Notification::TONE_SUCCESS,
            $tasksRoute,
        );
    }

    /**
     * Mark a notification as read (owned by user).
     */
    public function markAsRead(int $notificationId, int $userId): void
    {
        $notification = Notification::where('notification_id', $notificationId)
            ->where('user_id', $userId)
            ->firstOrFail();

        $notification->update(['is_read' => true]);
    }

    public function markAllAsRead(int $userId): int
    {
        return Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->update(['is_read' => true]);
    }

    // ── Action URL helpers ───────────────────────────────────────────────────

    protected function rolePrefixFor(User $user): string
    {
        if ($user->isProgramCoordinator()) {
            return 'coordinator';
        }
        if ($user->isFaculty()) {
            return 'faculty';
        }

        return 'dean';
    }

    protected function pendingReviewUrlFor(User $recipient, string $kind): string
    {
        $prefix = $this->rolePrefixFor($recipient);
        if ($kind === 'tg') {
            return route($prefix.'.teaching-guides.index', ['status' => 'pending']);
        }

        return route($prefix.'.exam-questionnaires.index', ['status' => 'pending']);
    }

    protected function documentsUrlFor(User $recipient, string $category, ?int $documentId = null): string
    {
        $prefix = $this->rolePrefixFor($recipient);

        if ($documentId) {
            return route($prefix.'.view-document', $documentId);
        }

        $tab = match ($category) {
            'Academics' => 'academics',
            'Accreditation and Certifications' => 'accreditation-and-certifications',
            'Event Letters' => 'event-letters',
            'Teaching Guides' => 'teaching-guides',
            'Exam Questionnaires' => 'exam-questionnaires',
            'Custom Folders' => 'custom-folders',
            default => null,
        };

        return $tab
            ? route($prefix.'.documents', ['tab' => $tab])
            : route($prefix.'.documents');
    }

    protected function facultySubmissionUrl(string $kind, int $submissionId, $documentId = null): string
    {
        if ($documentId) {
            return route('faculty.view-document', $documentId);
        }

        return $kind === 'tg'
            ? route('faculty.teaching-guides.view', $submissionId)
            : route('faculty.exam-questionnaires.view', $submissionId);
    }

    /**
     * Prefer stored action_url; otherwise infer a sensible destination from the message.
     * Used so older notifications (created before action_url) still deep-link on click.
     */
    public function resolvedActionUrl(Notification $notification, ?User $user = null): ?string
    {
        $stored = $this->normalizeActionUrl($notification->action_url);
        if ($stored && str_starts_with($stored, '/')) {
            return $stored;
        }

        $user ??= $notification->relationLoaded('user')
            ? $notification->user
            : User::with('role')->find($notification->user_id);

        if (!$user) {
            return null;
        }

        return $this->normalizeActionUrl($this->inferActionUrlFromMessage((string) $notification->message, $user));
    }

    /**
     * Backfill action_url for notifications that were created before deep-links existed.
     */
    public function backfillMissingActionUrls(): int
    {
        $updated = 0;
        Notification::query()
            ->whereNull('action_url')
            ->with('user.role')
            ->orderBy('notification_id')
            ->chunkById(100, function ($chunk) use (&$updated) {
                foreach ($chunk as $notification) {
                    $url = $this->resolvedActionUrl($notification, $notification->user);
                    if (!$url) {
                        continue;
                    }
                    $notification->update(['action_url' => $url]);
                    $updated++;
                }
            }, 'notification_id');

        return $updated;
    }

    protected function inferActionUrlFromMessage(string $message, User $user): ?string
    {
        $prefix = $this->rolePrefixFor($user);
        $lower = Str::lower($message);

        if (str_contains($lower, 'new task assigned') || str_contains($lower, 'my tasks')) {
            return route($prefix.'.tasks');
        }

        if (str_contains($lower, 'permanently deleted') || str_contains($lower, 'recycle bin')) {
            return route($prefix.'.recycle-bin.index');
        }

        if (str_contains($lower, 'new shared document')) {
            if (preg_match('/in\s+exam questionnaires/i', $message)) {
                return $this->documentsUrlFor($user, 'Exam Questionnaires');
            }
            if (preg_match('/in\s+teaching guides/i', $message)) {
                return $this->documentsUrlFor($user, 'Teaching Guides');
            }

            return route($prefix.'.documents');
        }

        if (str_contains($lower, 'new teaching guide uploaded')) {
            return route($prefix.'.teaching-guides.index');
        }

        if (str_contains($lower, 'for your approval') || str_contains($lower, 'awaiting dean approval') || str_contains($lower, 'review under pending')) {
            $isTeaching = str_contains($lower, 'teaching guide');
            $isExam = str_contains($lower, 'exam questionnaire') || str_contains($lower, '(exam questionnaires)');

            if ($isTeaching && !$isExam) {
                return $this->pendingReviewUrlFor($user, 'tg');
            }

            return $this->pendingReviewUrlFor($user, 'eq');
        }

        if (str_contains($lower, 'your teaching guide')) {
            return route($prefix.'.teaching-guides.index');
        }

        if (
            str_contains($lower, 'toq submission')
            || str_contains($lower, 'tos submission')
            || str_contains($lower, 'eq submission')
            || str_contains($lower, 'your ') && str_contains($lower, 'submission')
            || str_contains($lower, 'exam questionnaire')
            || str_contains($lower, 'check exam questionnaires')
        ) {
            return route($prefix.'.exam-questionnaires.index');
        }

        if (str_contains($lower, 'check documents') || str_contains($lower, 'uploaded')) {
            if (preg_match('/\((Academics|Accreditation and Certifications|Event Letters|Teaching Guides|Exam Questionnaires|Custom Folders|Other)\)/i', $message, $m)) {
                return $this->documentsUrlFor($user, $m[1]);
            }

            return route($prefix.'.documents');
        }

        return null;
    }

    protected function normalizeActionUrl(?string $actionUrl): ?string
    {
        if ($actionUrl === null) {
            return null;
        }

        $actionUrl = trim($actionUrl);
        if ($actionUrl === '') {
            return null;
        }

        // Prefer relative path for portability across domains.
        if (Str::startsWith($actionUrl, ['http://', 'https://'])) {
            $path = parse_url($actionUrl, PHP_URL_PATH) ?: '/';
            $query = parse_url($actionUrl, PHP_URL_QUERY);
            return $query ? ($path.'?'.$query) : $path;
        }

        return $actionUrl;
    }
}
