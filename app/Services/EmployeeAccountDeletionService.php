<?php

namespace App\Services;

use App\Models\DashboardLog;
use App\Models\Document;
use App\Models\DocumentComment;
use App\Models\DocumentFavorite;
use App\Models\DocumentView;
use App\Models\Employee;
use App\Models\ExamQuestionnaire;
use App\Models\Report;
use App\Models\ExamRecord;
use App\Models\Folder;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TeachingGuide;
use App\Models\User;
use App\Support\UploadStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmployeeAccountDeletionService
{
    /**
     * Permanently remove a faculty/coordinator account and all associated records/files.
     *
     * @return array<string, int> Counts of removed entities (for flash message).
     */
    public function permanentlyDelete(Employee $employee, User $actor): array
    {
        if (!config('employee.allow_hard_delete', false)) {
            abort(403, 'Permanent account deletion is disabled on this environment.');
        }

        $user = $employee->user()->with('role')->firstOrFail();

        if ($user->isDean()) {
            abort(403, 'Cannot delete Dean accounts.');
        }

        if (!$user->isProgramCoordinator() && !$user->isFaculty()) {
            abort(403, 'Only faculty and coordinator accounts can be permanently deleted.');
        }

        if ($actor->id === $user->id) {
            abort(403, 'You cannot delete your own account.');
        }

        // Permanent account deletion stays with the Dean office (Dean/Secretary), not Admin.
        if (!$actor->isDean() && !$actor->isSecretary()) {
            abort(403, 'Unauthorized.');
        }

        $summary = [
            'documents' => 0,
            'teaching_guides' => 0,
            'exam_questionnaires' => 0,
            'reports' => 0,
            'exam_records' => 0,
            'tasks' => 0,
            'folders' => 0,
        ];

        $displayName = $employee->full_name;
        $username = $user->username;
        $userId = (int) $user->id;

        $email = $user->email;

        DB::transaction(function () use ($userId, $user, $actor, $displayName, $username, $email, &$summary) {
            // Unlink exam records from documents we are about to remove.
            ExamRecord::query()
                ->where('recorded_by', $userId)
                ->update(['document_id' => null]);

            $summary['exam_questionnaires'] = $this->deleteExamQuestionnaires($userId);
            $summary['reports'] = $this->deleteReports($userId);
            $summary['teaching_guides'] = $this->deleteTeachingGuides($userId);
            $summary['documents'] = $this->deleteDocuments($userId);
            $summary['exam_records'] = ExamRecord::query()->where('recorded_by', $userId)->delete();
            $summary['tasks'] = $this->deleteTasks($userId);
            $summary['folders'] = $this->deleteUserFolders($userId);

            DB::table('sessions')->where('user_id', $userId)->delete();

            if ($email) {
                DB::table('password_reset_tokens')->where('email', $email)->delete();
            }

            DashboardLog::create([
                'user_id' => $actor->id,
                'target_user_id' => null,
                'activity' => "Permanently deleted account and all data: {$displayName} ({$username})",
                'activity_type' => 'account_deleted',
                'visibility' => 'dean',
            ]);

            $user->delete();
        });

        return $summary;
    }

    protected function deleteReports(int $userId): int
    {
        $count = 0;

        Report::query()
            ->where('submitted_by', $userId)
            ->orderBy('report_id')
            ->chunkById(50, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    $this->deleteStoredFile($row->file_path);
                    $row->delete();
                    $count++;
                }
            }, 'report_id');

        return $count;
    }

    protected function deleteExamQuestionnaires(int $userId): int
    {
        $count = 0;

        ExamQuestionnaire::query()
            ->where('submitted_by', $userId)
            ->orderBy('id')
            ->chunkById(50, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    $this->deleteStoredFile($row->file_path);
                    $row->delete();
                    $count++;
                }
            });

        return $count;
    }

    protected function deleteTeachingGuides(int $userId): int
    {
        $count = 0;

        TeachingGuide::query()
            ->where('user_id', $userId)
            ->orderBy('id')
            ->chunkById(50, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    $this->deleteStoredFile($row->file_path);
                    $row->delete();
                    $count++;
                }
            });

        return $count;
    }

    protected function deleteDocuments(int $userId): int
    {
        $count = 0;

        Document::withTrashed()
            ->where('uploaded_by', $userId)
            ->orderBy('document_id')
            ->chunkById(50, function ($rows) use (&$count) {
                foreach ($rows as $document) {
                    $this->purgeDocument($document);
                    $count++;
                }
            }, 'document_id');

        return $count;
    }

    protected function purgeDocument(Document $document): void
    {
        $documentId = $document->document_id;

        DB::table('document_recipients')->where('document_id', $documentId)->delete();
        DocumentComment::query()->where('document_id', $documentId)->delete();
        DocumentView::query()->where('document_id', $documentId)->delete();
        DocumentFavorite::query()->where('document_id', $documentId)->delete();

        ExamRecord::query()->where('document_id', $documentId)->update(['document_id' => null]);

        if (Schema::hasColumn('exam_questionnaires', 'document_id')) {
            ExamQuestionnaire::query()->where('document_id', $documentId)->update(['document_id' => null]);
        }

        if (Schema::hasColumn('teaching_guides', 'document_id')) {
            TeachingGuide::query()->where('document_id', $documentId)->update(['document_id' => null]);
        }

        $this->deleteStoredFile($document->file_path);
        $document->forceDelete();
    }

    protected function deleteTasks(int $userId): int
    {
        $taskIds = Task::query()
            ->where(function ($q) use ($userId) {
                $q->where('assigned_to', $userId)->orWhere('assigned_by', $userId);
            })
            ->pluck('task_id');

        if ($taskIds->isEmpty()) {
            return 0;
        }

        TaskAttachment::query()
            ->whereIn('task_id', $taskIds)
            ->get()
            ->each(function (TaskAttachment $attachment) {
                $this->deleteStoredFile($attachment->file_path);
            });

        return Task::query()->whereIn('task_id', $taskIds)->delete();
    }

    protected function deleteUserFolders(int $userId): int
    {
        $folderIds = Folder::query()->where('user_id', $userId)->pluck('folder_id');

        if ($folderIds->isEmpty()) {
            return 0;
        }

        Document::withTrashed()
            ->whereIn('folder_id', $folderIds)
            ->where('uploaded_by', '!=', $userId)
            ->update(['folder_id' => null]);

        $deleted = 0;
        $remaining = Folder::query()->where('user_id', $userId)->count();

        while ($remaining > 0) {
            $batch = Folder::query()
                ->where('user_id', $userId)
                ->orderByDesc('level')
                ->limit(50)
                ->get();

            if ($batch->isEmpty()) {
                break;
            }

            foreach ($batch as $folder) {
                $folder->delete();
                $deleted++;
            }

            $remaining = Folder::query()->where('user_id', $userId)->count();
        }

        return $deleted;
    }

    protected function deleteStoredFile(?string $path): void
    {
        if (!$path) {
            return;
        }

        try {
            if (UploadStorage::exists($path)) {
                UploadStorage::delete($path);
            }
        } catch (\Throwable) {
            // Continue DB cleanup even if object storage hiccups.
        }
    }
}
