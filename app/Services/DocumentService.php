<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentView;
use App\Models\DocumentFavorite;
use App\Models\DashboardLog;
use App\Models\Folder;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\TeachingGuideSyncService;
use App\Models\SchoolYear;
use App\Support\AcademicYear;
use App\Support\DocumentNaming;
use App\Support\SubmissionLocation;
use App\Support\UploadStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Jobs\IndexDocumentContentJob;
use App\Models\DocumentSearchIndex;
use App\Models\Course;

class DocumentService
{
    public function __construct(
        protected TeachingGuideSyncService $teachingGuideSync,
        protected ExamQuestionnaireSyncService $examQuestionnaireSync,
        protected NotificationService $notificationService,
        protected DocumentSearchService $documentSearch,
    ) {}
    /**
     * Document categories available in the system (must match documents.category ENUM).
     */
    const CATEGORIES = [
        'Accreditation and Certifications',
        'Event Letters',
        'Academics',
        'Teaching Guides',
        'Exam Questionnaires',
    ];

    /**
     * All values allowed in documents.category column.
     */
    public static function allowedCategories(): array
    {
        return array_merge(self::CATEGORIES, ['Other']);
    }

    /**
     * Resolve the documents.category value for a folder (walks to root system category).
     */
    public function resolveCategoryForFolder(?int $folderId): string
    {
        if (!$folderId) {
            return 'Other';
        }

        $folder = Folder::find($folderId);
        if (!$folder) {
            return 'Other';
        }

        $rootName = $folder->top_level_category;

        return in_array($rootName, self::allowedCategories(), true) ? $rootName : 'Other';
    }

    /**
     * Resolve the active documents tab slug, mirroring the fallback used by the
     * folder-tree partial (unknown tab falls back to the first category).
     */
    public function resolveActiveTab(?string $tab, Collection $folderTree): ?string
    {
        $slugs = $folderTree->map(fn ($category) => $category->tabKey());

        return $tab && $slugs->contains($tab) ? $tab : $slugs->first();
    }

    /**
     * Map the active tab to the documents.category value it represents, so the
     * document list only shows files belonging to the tab the user is viewing.
     */
    public function categoryForTab(?string $tab, Collection $folderTree): ?string
    {
        $activeTab = $this->resolveActiveTab($tab, $folderTree);

        if (!$activeTab) {
            return null;
        }

        foreach ($folderTree as $category) {
            if ($category->tabKey() === $activeTab) {
                if ($category->document_category_id) return 'managed:'.$category->document_category_id;
                return in_array($category->folder_name, self::allowedCategories(), true)
                    ? $category->folder_name
                    : 'Other';
            }
        }

        return null;
    }

    /**
     * Get filtered and paginated documents for a user.
     */
    public function getFilteredDocuments(User $user, ?string $categoryFilter, ?string $folderFilter, array $queryParams = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->buildDocumentListQuery($user, $categoryFilter, $folderFilter, $queryParams);

        $sort = $queryParams['sort'] ?? 'date';
        match ($sort) {
            'size' => $query->orderByDesc('file_size'),
            'title' => $query->orderBy('document_title'),
            'author' => $query->orderBy('uploaded_by'),
            'category' => $query->orderBy('category'),
            default => $query->latest('created_at'),
        };

        $term = trim((string) ($queryParams['search'] ?? $queryParams['name'] ?? ''));
        $page = $query->paginate($perPage)->withQueryString();
        $page->getCollection()->transform(fn (Document $document) => $this->documentSearch->decorateResult($document, $term));

        return $page;
    }

    /**
     * Title suggestions for the documents list search (min 3 characters).
     *
     * @return list<string>
     */
    public function suggestDocumentTitles(
        User $user,
        ?string $categoryFilter,
        ?string $folderFilter,
        array $queryParams,
        string $term,
        int $limit = 8,
    ): array {
        $term = trim($term);
        if (mb_strlen($term) < 3) {
            return [];
        }

        return $this->documentSearch->suggestTitles(
            $user,
            $categoryFilter,
            is_numeric($folderFilter) ? (int) $folderFilter : null,
            $queryParams,
            $term,
            $limit,
        );
    }

    protected function buildDocumentListQuery(
        User $user,
        ?string $categoryFilter,
        ?string $folderFilter,
        array $queryParams,
        bool $applyTextSearch = true,
    ): Builder {
        $filters = $this->documentSearch->normalizeFilters($user, $queryParams);
        if (!$applyTextSearch) {
            $filters['q'] = '';
        }

        $scope = $filters['scope'] ?? DocumentSearchService::SCOPE_FOLDER;
        $folderId = null;
        if ($scope === DocumentSearchService::SCOPE_FOLDER && $folderFilter !== null) {
            $folderId = $folderFilter === 'uncategorized' ? 0 : (int) $folderFilter;
            $filters['folder_id'] = $folderId;
        }

        $query = Document::getFilteredDocuments($user, $categoryFilter)
            ->with('searchIndex')
            ->onlyApprovedShareable();

        $this->documentSearch->applyFilters($query, $user, $filters, $categoryFilter, $folderId);

        $sizeRange = $queryParams['size_range'] ?? null;
        if ($sizeRange === 'small') {
            $query->where('file_size', '<', 1048576);
        } elseif ($sizeRange === 'medium') {
            $query->whereBetween('file_size', [1048576, 5242880]);
        } elseif ($sizeRange === 'large') {
            $query->where('file_size', '>', 5242880);
        }

        $tag = $queryParams['tag'] ?? null;
        if ($tag) {
            $query->where('tags', 'like', "%{$tag}%");
        }

        $uploadedBy = $queryParams['uploaded_by'] ?? null;
        if ($uploadedBy) {
            $query->where('uploaded_by', $uploadedBy);
        }

        $title = $queryParams['title'] ?? null;
        if ($title) {
            $query->where('document_title', 'like', "%{$title}%");
        }

        return $query;
    }

    /**
     * Get uploaders visible to the current user for filter dropdowns.
     */
    public function getAvailableUploaders(User $user): Collection
    {
        $uploaderIds = Document::getFilteredDocuments($user)
            ->reorder()
            ->select('uploaded_by')
            ->distinct()
            ->pluck('uploaded_by');

        return User::with('employee')
            ->whereIn('id', $uploaderIds)
            ->get()
            ->sortBy(fn (User $uploader) => $uploader->employee->full_name ?? $uploader->username)
            ->values();
    }

    public function getSearchFilterOptions(User $user): array
    {
        $options = $this->documentSearch->filterOptions($user);

        return [
            'searchDepartments' => $options['programs'],
            'searchCourses' => $options['courses'],
            'searchSchoolYears' => $options['schoolYears'],
            'archivedSchoolYears' => $options['archivedSchoolYears'],
        ];
    }

    /**
     * Get user's folders with document counts.
     */
    public function getUserFolders(int $userId): Collection
    {
        return Folder::where('user_id', $userId)
            ->withCount('documents')
            ->orderBy('folder_name')
            ->get();
    }

    /**
     * Get recently viewed documents for a user.
     */
    public function getRecentDocuments(int $userId, int $limit = 5): Collection
    {
        $user = User::findOrFail($userId);
        return DocumentView::getRecentDocuments($userId, $limit)
            ->filter(fn ($document) => $document instanceof Document && $document->canView($user))
            ->values();
    }

    /**
     * Get user's favorite documents.
     */
    public function getFavoriteDocuments(User $user): Collection
    {
        return $user->documentFavorites()
            ->with('document')
            ->get()
            ->pluck('document')
            ->filter(fn ($document) => $document && $document->canView($user))
            ->values();
    }

    /**
     * Data for the in-app file preview page (same layout as exam questionnaires / teaching guides).
     *
     * @return array{title: string, folderPath: string, documentsUrl: ?string, streamUrl: string, downloadUrl: string, backUrl: string}
     */
    public function documentPreviewPage(int $documentId, User $user): array
    {
        $document = Document::with('folder.parent.parent')->findOrFail($documentId);

        if (!$document->canView($user)) {
            abort(403, 'Unauthorized access');
        }

        UploadStorage::assertPathAllowed($document->file_path);

        if (!UploadStorage::exists($document->file_path)) {
            throw new \RuntimeException('This file is no longer available. It was uploaded to a previous storage provider and no longer exists in the current storage.');
        }

        $routePrefix = match (true) {
            $user->isFaculty() => 'faculty',
            $user->isProgramCoordinator() => 'coordinator',
            default => 'dean',
        };

        $documentsUrl = SubmissionLocation::documentsUrl($user, $document->folder);
        $backUrl = $documentsUrl ?? route($routePrefix.'.documents');

        $folderPath = SubmissionLocation::folderBreadcrumb($document->folder);
        if ($folderPath === '' && !empty($document->category)) {
            $folderPath = $document->category;
        }

        $canSeeReceipts = (int) $document->uploaded_by === (int) $user->id || $user->canManageDocuments();

        $fileMime = UploadStorage::mimeType($document->file_path) ?? '';
        $isImage  = str_starts_with($fileMime, 'image/');

        // Version data — faculty can version own files and shared TG/EQ; Dean always
        $canVersion = $this->userCanVersionDocument($document, $user);
        $versions   = $document->versions()->with('uploader.employee')->get();

        return [
            'title'              => $document->document_title,
            'folderPath'         => $folderPath,
            'documentsUrl'       => $documentsUrl,
            'streamUrl'          => route($routePrefix.'.view-document', ['id' => $documentId, 'stream' => 1]),
            'downloadUrl'        => route($routePrefix.'.download-document', $documentId),
            'copyUrl'            => route($routePrefix.'.documents.copy', $documentId),
            'versionUploadUrl'   => route($routePrefix.'.documents.version', $documentId),
            'backUrl'            => $backUrl,
            'viewers'            => $canSeeReceipts ? $document->viewReceipts() : null,
            'isImage'            => $isImage,
            'fileMime'           => $fileMime,
            'document'           => $document,
            'canVersion'         => $canVersion,
            'currentVersion'     => $document->version ?? 1,
            'versions'           => $versions,
            'canCopy'            => $document->canView($user),
            'foldersListUrl'     => route($routePrefix.'.folders.list'),
        ];
    }

    /**
     * View a document (returns file response).
     */
    public function viewDocument(int $documentId, User $user, bool $trackView = false)
    {
        $document = Document::findOrFail($documentId);

        if (!$document->canView($user)) {
            abort(403, 'Unauthorized access');
        }

        UploadStorage::assertPathAllowed($document->file_path);

        if (!UploadStorage::exists($document->file_path)) {
            throw new \RuntimeException('This file is no longer available. It was uploaded to a previous storage provider and no longer exists in the current storage.');
        }

        if ($trackView) {
            DocumentView::trackView($user->id, $documentId);

            DashboardLog::create([
                'user_id' => $user->id,
                'activity' => 'Viewed document: ' . $document->document_title,
                'activity_type' => 'document_viewed',
                'visibility' => 'own',
            ]);
        }

        $mimeType = UploadStorage::mimeType($document->file_path);
        $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        // For Word docs: serve the sibling PDF for inline preview
        $serveFilePath = $document->file_path;
        if (!in_array($mimeType, $allowedMimes)) {
            $pdfSibling = preg_replace('/\.[^.]+$/', '.pdf', $document->file_path);
            if (UploadStorage::exists($pdfSibling)) {
                $serveFilePath = $pdfSibling;
                $mimeType      = 'application/pdf';
            } else {
                $mimeType = 'application/octet-stream';
            }
        }

        UploadStorage::assertPathAllowed($serveFilePath);

        return UploadStorage::inlineResponse(
            $serveFilePath,
            DocumentNaming::downloadFilename($document->document_title, $serveFilePath, $document->original_filename),
            $mimeType,
        );
    }

    /**
     * Download a document.
     */
    public function downloadDocument(int $documentId, User $user, string $format = 'word')
    {
        $document = Document::findOrFail($documentId);

        if (!$document->canView($user)) {
            abort(403, 'Unauthorized access');
        }

        UploadStorage::assertPathAllowed($document->file_path);

        if (!UploadStorage::exists($document->file_path)) {
            throw new \RuntimeException('This file is no longer available. It was uploaded to a previous storage provider and no longer exists in the current storage.');
        }

        // Determine which file to download
        $downloadPath = $document->file_path;
        if ($format === 'pdf') {
            $pdfPath = preg_replace('/\.[^.]+$/', '.pdf', $document->file_path);
            if (UploadStorage::exists($pdfPath)) {
                $downloadPath = $pdfPath;
            }
        }

        UploadStorage::assertPathAllowed($downloadPath);

        DashboardLog::create([
            'user_id' => $user->id,
            'activity' => 'Downloaded document: ' . $document->document_title,
            'activity_type' => 'document_downloaded',
            'visibility' => 'own',
        ]);

        return UploadStorage::downloadResponse(
            $downloadPath,
            \App\Support\DocumentNaming::downloadFilename($document->document_title, $downloadPath, $document->original_filename),
        );
    }

    /**
     * Upload one or more documents.
     *
     * @return array{count: int, submitted_for_approval: bool}
     */
    public function uploadDocuments(array $validated, array $files, int $userId, array $recipientIds = [], ?int $schoolYearId = null): array
    {
        $tags = !empty($validated['tags']) ? implode(',', array_map('trim', explode(',', $validated['tags']))) : '';

        $category = $this->resolveCategoryForFolder($validated['folder_id'] ?? null);
        $folder = !empty($validated['folder_id']) ? Folder::find($validated['folder_id']) : null;
        $recipientIds = array_values(array_unique(array_filter(array_map('intval', $recipientIds))));
        $subject = $validated['subject'] ?? null;
        $uploader = User::findOrFail($userId);
        $hierarchy = app(AcademicHierarchyService::class);

        if ($folder && $subject && $hierarchy->isSemesterTypeLeafFolder($folder)
            && !$hierarchy->isUnderTgCategory($folder) && !$hierarchy->isUnderEqCategory($folder)) {
            $folder = $hierarchy->ensureCourseFolder($folder, $subject);
            $validated['folder_id'] = $folder->folder_id;
        }
        $autoApprove = $uploader->isDeanOrSecretary();
        $submittedForApproval = false;

        $uploadedCount = 0;
        $lastDocumentId = null;
        $customTitle = trim((string) ($validated['document_title'] ?? ''));
        $resolvedTitles = [];

        foreach ($files as $index => $file) {
            // Custom title (optional): use it for all files, with (2)/(3) suffixes.
            // Blank title: each file uses its own original uploaded filename.
            if ($customTitle !== '') {
                $title = $customTitle . ($uploadedCount > 0 ? ' (' . ($uploadedCount + 1) . ')' : '');
            } else {
                $title = DocumentNaming::titleFromUploadedFile($file);
            }
            $resolvedTitles[] = $title;

            $extension = strtolower($file->getClientOriginalExtension());
            $fileType = $validated['document_type'] === 'pdf' || $extension === 'pdf' ? 'pdf' : 'word';

            if ($category === 'Exam Questionnaires' && $folder) {
                $filename = time() . '_' . $index . '_' . $file->hashName();
                $storedPath = UploadStorage::storeAs($file, 'exam-questionnaires', $filename);
                $examType = $validated['exam_type'] ?? 'Quiz';
                $status = $autoApprove ? 'approved' : 'pending';
                $submissionTitle = $title;

                $questionnaire = $this->examQuestionnaireSync->createFromFolderUpload(
                    $userId,
                    $folder,
                    $submissionTitle,
                    $storedPath,
                    $fileType,
                    $examType,
                    $subject,
                    $status,
                    $autoApprove ? $userId : null,
                );

                $questionnaire->update(['original_filename' => DocumentNaming::uploadedFilename($file)]);

                if ($autoApprove) {
                    $this->examQuestionnaireSync->syncToDocument($questionnaire->fresh());
                } else {
                    $submittedForApproval = true;
                }

                $uploadedCount++;
                continue;
            }

            if ($category === 'Teaching Guides' && $folder) {
                $filename = time() . '_' . $index . '_' . $file->hashName();
                $storedPath = UploadStorage::storeAs($file, 'teaching-guides', $filename);
                $status = $autoApprove ? 'approved' : 'pending';
                $guideTitle = $title;

                $guide = $this->teachingGuideSync->createFromFolderUpload(
                    $userId,
                    $folder,
                    $guideTitle,
                    $storedPath,
                    $fileType,
                    $subject,
                    $status,
                    $autoApprove ? $userId : null,
                    $recipientIds,
                );

                if (!$guide) {
                    continue;
                }
                $guide->update(['original_filename' => DocumentNaming::uploadedFilename($file)]);

                if ($autoApprove) {
                    $this->teachingGuideSync->syncDocumentFromGuide($guide, $uploader, $recipientIds);
                } else {
                    $submittedForApproval = true;
                }

                $uploadedCount++;
                continue;
            }

            $filename = time() . '_' . $index . '_' . $file->hashName();
            $storedRelative = 'documents/' . $filename;
            try {
                UploadStorage::putFileAs('documents', $file, $filename);

                $document = \Illuminate\Support\Facades\DB::transaction(function () use ($userId, $validated, $title, $subject, $storedRelative, $file, $category, $folder, $tags, $recipientIds, $schoolYearId) {
                    $document = Document::create([
                        'uploaded_by' => $userId,
                        'folder_id' => $validated['folder_id'] ?? null,
                        'document_title' => $title,
                        'subject' => $subject,
                        'file_path' => $storedRelative,
                        'original_filename' => DocumentNaming::uploadedFilename($file),
                        'file_size' => (int) ($file->getSize() ?? 0),
                        'document_type' => $validated['document_type'],
                        'category' => $category,
                        'category_id' => $folder?->document_category_id,
                        'school_year_id' => $schoolYearId ?? SchoolYear::activeId(),
                        'tags' => in_array($category, Document::SHAREABLE_CATEGORIES, true) ? '' : $tags,
                    ]);

                    if (!empty($recipientIds)) {
                        $document->recipients()->sync($recipientIds);
                    }

                    $realPath = $file->getRealPath();
                    DocumentSearchIndex::updateOrCreate(
                        ['document_id' => $document->document_id],
                        ['file_hash' => $realPath ? (hash_file('sha256', $realPath) ?: null) : null, 'index_status' => 'pending']
                    );

                    return $document;
                });

                IndexDocumentContentJob::dispatch($document->document_id)->afterResponse();
                $lastDocumentId = (int) $document->document_id;
                $uploadedCount++;
            } catch (\Throwable $e) {
                if (UploadStorage::exists($storedRelative)) {
                    UploadStorage::delete($storedRelative);
                }
                report($e);
                continue;
            }
        }

        $primaryTitle = $customTitle !== ''
            ? $customTitle
            : ($resolvedTitles[0] ?? 'Untitled');

        $linkDocumentId = ($uploadedCount === 1 && $lastDocumentId) ? $lastDocumentId : null;

        if (!empty($recipientIds) && $uploadedCount > 0 && !$submittedForApproval) {
            $label = $uploadedCount === 1
                ? "\"{$primaryTitle}\""
                : "{$uploadedCount} document(s)";
            $categoryLabel = (string) ($category ?? 'Documents');
            $this->notificationService->notifyMany(
                $recipientIds,
                "New shared document: {$label} in {$categoryLabel}. Click to open.",
                null,
                null,
                fn (\App\Models\User $recipient) => $this->notificationService->documentsActionUrl(
                    $recipient,
                    $categoryLabel,
                    $linkDocumentId,
                ),
            );
        }

        $activity = $submittedForApproval
            ? 'Submitted ' . $uploadedCount . ' file(s) for Dean approval: ' . $primaryTitle
            : 'Uploaded ' . $uploadedCount . ' document(s): ' . $primaryTitle;

        DashboardLog::create([
            'user_id' => $userId,
            'activity' => $activity,
            'activity_type' => 'document_upload',
            'visibility' => 'own',
        ]);

        if ($uploadedCount > 0) {
            $this->notificationService->notifyDeanOnFileUpload(
                $uploader,
                $uploadedCount,
                $primaryTitle,
                (string) ($category ?? 'Documents'),
                $submittedForApproval,
                $submittedForApproval ? null : $linkDocumentId,
            );
        }

        return [
            'count' => $uploadedCount,
            'submitted_for_approval' => $submittedForApproval,
        ];
    }

    /**
     * Search faculty and coordinators for recipient picker (dean/coordinator uploads).
     */
    public function searchRecipients(User $user, string $term, int $limit = 20): Collection
    {
        $term = trim($term);

        $query = User::query()
            ->with(['employee', 'role'])
            ->where('status', 'Active')
            ->whereHas('role', fn ($q) => $q->where('role_name', 'Faculty Employee'));

        if ($user->isProgramCoordinator()) {
            $programs = \App\Support\CoordinatorDepartment::requirePrograms($user);
            $query->whereHas('employee', fn ($e) => $e->whereIn('program', $programs));
        }

        return $query
            ->when($term !== '', function ($q) use ($term) {
                $q->where(function ($inner) use ($term) {
                    $inner->where('username', 'like', "%{$term}%")
                        ->orWhereHas('employee', fn ($e) => $e->where('full_name', 'like', "%{$term}%"));
                });
            })
            ->orderBy('username')
            ->limit($limit)
            ->get();
    }

    public function isShareableCategory(?string $category): bool
    {
        return in_array($category, Document::SHAREABLE_CATEGORIES, true);
    }

    /**
     * Toggle document favorite for a user.
     */
    public function toggleFavorite(int $documentId, int $userId): array
    {
        $document = Document::findOrFail($documentId);
        abort_unless($document->canView(User::findOrFail($userId)), 404);
        $isFavorited = $document->toggleFavorite($userId);

        DashboardLog::create([
            'user_id' => $userId,
            'activity' => ($isFavorited ? 'Favorited' : 'Unfavorited') . ' document: ' . $document->document_title,
            'activity_type' => $isFavorited ? 'document_favorited' : 'document_unfavorited',
            'visibility' => 'own',
        ]);

        return [
            'favorited' => $isFavorited,
            'message' => $isFavorited ? 'Document added to favorites' : 'Document removed from favorites',
        ];
    }

    /**
     * Get all document categories.
     */
    public function getCategories(): array
    {
        return self::CATEGORIES;
    }

    /**
     * Move a document to the Recycle Bin (soft delete).
     */
    public function userCanRenameDocument(Document $document, User $user): bool
    {
        if (!$document->canView($user)) {
            return false;
        }

        if ($user->isDeanOrSecretary()) {
            return true;
        }

        if ((int) $document->uploaded_by === (int) $user->id) {
            return true;
        }

        if ($user->isProgramCoordinator()) {
            return Document::query()
                ->where('document_id', $document->document_id)
                ->visibleTo($user)
                ->exists();
        }

        return false;
    }

    /**
     * Whether the user may upload a new version of this document.
     * Dean/Secretary: always. Owner: always.
     * Faculty/Coordinator: own files, plus Teaching Guides / Exam Questionnaires they can view
     * (so shared subject files can be updated from the faculty preview, not Dean-only).
     */
    public function userCanVersionDocument(Document $document, User $user): bool
    {
        if (!$document->canView($user)) {
            return false;
        }

        if ($user->isDeanOrSecretary()) {
            return true;
        }

        if ((int) $document->uploaded_by === (int) $user->id) {
            return true;
        }

        if (($user->isFaculty() || $user->isProgramCoordinator())
            && in_array($document->category, Document::SHAREABLE_CATEGORIES, true)) {
            return true;
        }

        return false;
    }

    public function renameDocument(int $documentId, User $user, string $title): Document
    {
        $document = Document::with(['teachingGuide', 'examQuestionnaire'])->findOrFail($documentId);

        if (!$this->userCanRenameDocument($document, $user)) {
            abort(403, 'You are not allowed to rename this document.');
        }

        $oldTitle = $document->document_title;
        $title = trim($title);

        $document->update(['document_title' => $title]);

        if ($document->teachingGuide) {
            $document->teachingGuide->update(['title' => $title]);
        }

        if ($document->examQuestionnaire) {
            $document->examQuestionnaire->update(['title' => $title]);
        }

        DashboardLog::create([
            'user_id' => $user->id,
            'activity' => "Renamed document from \"{$oldTitle}\" to \"{$title}\"",
            'activity_type' => 'document_renamed',
            'visibility' => $user->isDeanOrSecretary() ? 'dean' : 'own',
        ]);

        return $document->fresh();
    }

    public function deleteDocument(int $documentId, User $user): void
    {
        $document = Document::findOrFail($documentId);

        abort_unless($document->canView($user), 404);

        if (!($user->isDeanOrSecretary() || (int) $document->uploaded_by === (int) $user->id)) {
            abort(403, 'Unauthorized');
        }

        DashboardLog::create([
            'user_id' => $user->id,
            'activity' => 'Moved document to Recycle Bin: ' . $document->document_title,
            'activity_type' => 'document_deleted',
            'visibility' => $user->isDeanOrSecretary() ? 'dean' : 'own',
        ]);

        app(RecycleBinService::class)->moveToRecycleBin($document, $user);
    }

    // ── Copy / Duplicate ─────────────────────────────────────────────────────

    /**
     * Copy a document (physical file + DB record) to the given folder.
     * The new record belongs to $actor and is titled "[original] (Copy)".
     */
    public function copyDocument(Document $source, ?int $destinationFolderId, User $actor): Document
    {
        if (!$source->canView($actor)) {
            abort(403, 'Unauthorized to copy this document.');
        }
        abort_if($source->schoolYear?->isArchived(), 422, 'Archived files stay in their school year.');

        if ($destinationFolderId) {
            $destination = Folder::visibleTo($actor)->findOrFail($destinationFolderId);
            abort_if($destination->schoolYear?->isArchived(), 422, 'Use the Archive page for archived folders.');
            abort_if($destination->document_category_id && !$destination->managedCategory?->is_active, 422, 'This category is inactive.');
        }

        $ext         = pathinfo($source->file_path, PATHINFO_EXTENSION);
        $newRelPath  = 'documents/' . uniqid('copy_', true) . '.' . strtolower($ext);

        // Copy file on the configured disk (works for both local and S3)
        UploadStorage::disk()->copy($source->file_path, $newRelPath);

        $destCategory = $this->resolveCategoryForFolder($destinationFolderId);

        $copy = Document::create([
            'uploaded_by'    => $actor->id,
            'folder_id'      => $destinationFolderId,
            'document_title' => $source->document_title . ' (Copy)',
            'original_filename' => $source->original_filename,
            'file_path'      => $newRelPath,
            'file_size'      => $source->file_size,
            'document_type'  => $source->document_type,
            'category'       => $destCategory,
            'category_id'    => $destinationFolderId ? Folder::find($destinationFolderId)?->document_category_id : null,
            'school_year_id' => $source->school_year_id,
            'subject'        => $source->subject,
            'tags'           => $source->tags,
            'version'        => 1,
        ]);

        DashboardLog::create([
            'user_id'       => $actor->id,
            'activity'      => 'Copied document: ' . $source->document_title,
            'activity_type' => 'document_copied',
            'visibility'    => 'own',
        ]);

        return $copy;
    }

    // ── Versioning ───────────────────────────────────────────────────────────

    /**
     * Snapshot the current document file into document_versions,
     * then replace the live file with the newly uploaded one and rename to (v2)/(v3)/…
     */
    public function uploadNewVersion(
        Document $document,
        \Illuminate\Http\UploadedFile $file,
        User $actor,
        ?string $notes = null
    ): Document {
        $document->loadMissing(['teachingGuide', 'examQuestionnaire']);

        $currentVersion = (int) ($document->version ?? 1);
        if ($currentVersion < 1) {
            $currentVersion = 1;
        }
        $nextVersion = $currentVersion + 1;
        $versionedTitle = DocumentNaming::titleWithVersion((string) $document->document_title, $nextVersion);

        // Snapshot current state (archived as the previous version number)
        \App\Models\DocumentVersion::create([
            'document_id'    => $document->document_id,
            'version_number' => $currentVersion,
            'document_title' => $document->document_title,
            'original_filename' => $document->original_filename,
            'file_path'      => $document->file_path,
            'file_size'      => $document->file_size,
            'document_type'  => $document->document_type,
            'uploaded_by'    => $actor->id,
            'note'           => $notes ?: ('Archived as version '.$currentVersion),
            'created_at'     => $document->updated_at ?? $document->created_at ?? now(),
        ]);

        // Store new file
        $ext      = strtolower($file->getClientOriginalExtension());
        $filename = uniqid('ver_', true) . '.' . $ext;
        $newPath  = UploadStorage::putFileAs('documents', $file, $filename);

        $newType = match (true) {
            $ext === 'pdf'                          => 'pdf',
            in_array($ext, ['doc', 'docx'], true)  => 'word',
            in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) => 'image',
            default                                 => $document->document_type,
        };

        $document->update([
            'document_title' => $versionedTitle,
            'original_filename' => DocumentNaming::uploadedFilename($file),
            'file_path'      => $newPath,
            'file_size'      => $file->getSize(),
            'document_type'  => $newType,
            'version'        => $nextVersion,
        ]);

        if ($document->teachingGuide) {
            $document->teachingGuide->update(['title' => $versionedTitle]);
        }

        if ($document->examQuestionnaire) {
            $document->examQuestionnaire->update(['title' => $versionedTitle]);
        }

        DashboardLog::create([
            'user_id'       => $actor->id,
            'activity'      => 'Uploaded new version (v'.$nextVersion.') renamed to: '.$versionedTitle,
            'activity_type' => 'document_version_uploaded',
            'visibility'    => 'own',
        ]);

        return $document->fresh(['teachingGuide', 'examQuestionnaire']);
    }
}
