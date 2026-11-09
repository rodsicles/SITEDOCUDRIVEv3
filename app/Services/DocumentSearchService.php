<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Document;
use App\Models\Folder;
use App\Models\Program;
use App\Models\SavedDocumentSearch;
use App\Models\SchoolYear;
use App\Models\User;
use App\Support\AcademicYear;
use App\Support\CourseCatalog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DocumentSearchService
{
    public const SCOPE_FOLDER = 'folder';

    public const SCOPE_ALL = 'all';

    public const SCOPE_ARCHIVES = 'archives';

    public const MIN_TERM_LENGTH = 3;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function normalizeFilters(User $user, array $input): array
    {
        $filters = [
            'q' => trim((string) ($input['q'] ?? $input['search'] ?? $input['name'] ?? '')),
            'scope' => $this->normalizeScope((string) ($input['scope'] ?? self::SCOPE_FOLDER)),
            'employee_id' => isset($input['employee_id']) ? (int) $input['employee_id'] : null,
            'program' => isset($input['program']) ? trim((string) $input['program']) : null,
            'course_id' => isset($input['course_id']) ? (int) $input['course_id'] : null,
            'school_year_id' => isset($input['school_year_id']) ? (int) $input['school_year_id'] : null,
            'semester' => isset($input['semester']) ? trim((string) $input['semester']) : null,
            'status' => isset($input['status']) ? trim((string) $input['status']) : null,
            'type' => isset($input['type']) ? trim((string) $input['type']) : null,
            'file_type' => isset($input['file_type']) ? trim((string) $input['file_type']) : null,
            'date_from' => $input['date_from'] ?? null,
            'date_to' => $input['date_to'] ?? null,
            'folder_id' => isset($input['folder']) ? (int) $input['folder'] : (isset($input['folder_id']) ? (int) $input['folder_id'] : null),
            'managed_category_id' => isset($input['managed_category_id']) ? (int) $input['managed_category_id'] : null,
            'academic_year' => $input['academic_year'] ?? null,
        ];

        if ($filters['type'] === 'doc') {
            $filters['file_type'] = 'word';
        } elseif ($filters['type'] && !$filters['file_type']) {
            $filters['file_type'] = $filters['type'];
        }

        $filters = $this->revalidatePrograms($user, $filters);
        $filters = $this->revalidateSchoolYear($user, $filters);

        return $filters;
    }

    /**
     * @param  array<string, mixed>  $saved
     * @return array<string, mixed>
     */
    public function normalizeSavedFilters(User $user, array $saved): array
    {
        return $this->normalizeFilters($user, $saved);
    }

    public function authorizedQuery(User $user, ?string $categoryFilter = null): Builder
    {
        return Document::getFilteredDocuments($user, $categoryFilter)
            ->onlyApprovedShareable();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(Builder $query, User $user, array $filters, ?string $categoryFilter, ?int $folderFilter): Builder
    {
        if (!empty($filters['managed_category_id'])) {
            $query->where('category_id', (int) $filters['managed_category_id']);
        }

        $scope = $filters['scope'] ?? self::SCOPE_ALL;

        if ($scope === self::SCOPE_FOLDER && $folderFilter !== null) {
            if ($folderFilter === 0) {
                $query->whereNull('folder_id');
            } else {
                $ids = Folder::descendantIdsIncludingSelf($folderFilter);
                $query->where(function ($q) use ($ids) {
                    if ($ids === []) {
                        $q->whereRaw('1 = 0');
                    } else {
                        $q->whereIn('folder_id', $ids);
                    }
                });
            }
        }

        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $this->applyTermToQuery($query, $term);
        }

        $this->applyMetadataFilters($query, $filters);

        if ($scope === self::SCOPE_ARCHIVES) {
            $archivedIds = SchoolYear::query()->whereNotNull('archived_at')->pluck('id');
            if (!empty($filters['school_year_id'])) {
                $query->where('school_year_id', (int) $filters['school_year_id'])
                    ->whereIn('school_year_id', $archivedIds);
            } else {
                $query->whereIn('school_year_id', $archivedIds);
            }
        } elseif ($scope !== self::SCOPE_ALL) {
            $academicYearStart = AcademicYear::startYearFromQuery($filters['academic_year'] ?? null);
            if ($academicYearStart) {
                $this->applyAcademicYearFolders($query, $academicYearStart);
            } elseif (!empty($filters['school_year_id'])) {
                $query->where('school_year_id', (int) $filters['school_year_id']);
            } else {
                $activeId = SchoolYear::activeId();
                $query->where(function ($q) use ($activeId) {
                    $q->where('school_year_id', $activeId)->orWhereNull('school_year_id');
                });
            }
        } elseif (!empty($filters['school_year_id'])) {
            $query->where('school_year_id', (int) $filters['school_year_id']);
        }

        return $query;
    }

    public function applyTermToQuery(Builder $query, string $term): void
    {
        $like = '%'.$term.'%';
        $query->where(function ($q) use ($like, $term) {
            $q->where('document_title', 'like', $like)
                ->orWhere('subject', 'like', $like)
                ->orWhere('tags', 'like', $like)
                ->orWhere('category', 'like', $like)
                ->orWhereHas('searchIndex', fn ($index) => $index->where('content_text', 'like', $like))
                ->orWhereHas('folder', function ($folder) use ($like) {
                    $folder->where('folder_name', 'like', $like)
                        ->orWhere('slug', 'like', $like);
                });
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function applyMetadataFilters(Builder $query, array $filters): void
    {
        $fileType = $filters['file_type'] ?? null;
        if ($fileType === 'pdf') {
            $query->where(function ($q) {
                $q->where('file_path', 'like', '%.pdf')->orWhere('document_type', 'like', '%pdf%');
            });
        } elseif ($fileType === 'word') {
            $query->where(function ($q) {
                $q->where(function ($inner) {
                    $inner->where('file_path', 'like', '%.doc')->orWhere('file_path', 'like', '%.docx');
                })->orWhere('document_type', 'like', '%doc%');
            });
        } elseif ($fileType === 'image') {
            $query->where(function ($q) {
                $q->whereIn('document_type', ['image', 'jpg', 'jpeg', 'png', 'gif', 'webp'])
                    ->orWhere('file_path', 'like', '%.jpg')
                    ->orWhere('file_path', 'like', '%.jpeg')
                    ->orWhere('file_path', 'like', '%.png')
                    ->orWhere('file_path', 'like', '%.gif')
                    ->orWhere('file_path', 'like', '%.webp');
            });
        }

        if (!empty($filters['employee_id'])) {
            $query->where('uploaded_by', (int) $filters['employee_id']);
        }

        if (!empty($filters['program'])) {
            $query->whereHas('uploader.employee', fn ($q) => $q->where('program', $filters['program']));
        }

        if (!empty($filters['course_id']) && ($course = Course::find($filters['course_id']))) {
            $query->where('subject', 'like', '%'.$course->code.'%');
        }

        if (!empty($filters['semester'])) {
            $query->whereHas('folder', fn ($q) => $q->where('folder_name', 'like', $filters['semester'].'%'));
        }

        if (!empty($filters['status'])) {
            $query->whereHas('requestSubmissions', fn ($q) => $q->where('status', $filters['status']));
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(User $user, array $filters, ?string $categoryFilter = null, int $perPage = 20): LengthAwarePaginator
    {
        $filters = $this->normalizeFilters($user, $filters);
        $folderFilter = ($filters['scope'] ?? self::SCOPE_ALL) === self::SCOPE_FOLDER
            ? ($filters['folder_id'] ?? null)
            : null;

        $query = $this->authorizedQuery($user, $categoryFilter)
            ->with(['folder.parent.parent.parent', 'uploader.employee', 'searchIndex']);

        $this->applyFilters($query, $user, $filters, $categoryFilter, $folderFilter);

        $term = trim((string) ($filters['q'] ?? ''));
        $page = $query->latest('created_at')->paginate($perPage)->withQueryString();
        $page->getCollection()->transform(fn (Document $doc) => $this->decorateResult($doc, $term));

        return $page;
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @return list<string>
     */
    public function suggestTitles(User $user, ?string $categoryFilter, ?int $folderFilter, array $queryParams, string $term, int $limit = 8): array
    {
        $term = trim($term);
        if (mb_strlen($term) < self::MIN_TERM_LENGTH) {
            return [];
        }

        $filters = $this->normalizeFilters($user, $queryParams);
        $filters['q'] = $term;

        $query = $this->authorizedQuery($user, $categoryFilter);
        $this->applyFilters($query, $user, $filters, $categoryFilter, $folderFilter);

        return $query
            ->where('document_title', 'like', '%'.$term.'%')
            ->orderBy('document_title')
            ->limit($limit * 3)
            ->pluck('document_title')
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return list<array{title: string, subtitle: string, type: string, url: string, match?: string}>
     */
    public function globalDocumentHits(User $user, string $term, int $limit = 6): array
    {
        $term = trim($term);
        if (mb_strlen($term) < self::MIN_TERM_LENGTH) {
            return [];
        }

        $filters = ['q' => $term, 'scope' => self::SCOPE_ALL];
        $query = $this->authorizedQuery($user, null)
            ->with(['folder.parent.parent.parent', 'uploader.employee', 'searchIndex']);
        $this->applyFilters($query, $user, $filters, null, null);

        $route = match (true) {
            $user->isDeanOrSecretary() => 'dean.documents',
            $user->isProgramCoordinator() => 'coordinator.documents',
            default => 'faculty.documents',
        };

        return $query->latest()->limit($limit)->get()->map(function (Document $document) use ($route, $term) {
            $decorated = $this->decorateResult($document, $term);
            $tab = $this->tabSlugForFolder($document->folder);
            $params = array_filter([
                'tab' => $tab,
                'folder' => $document->folder_id,
                'search' => $term,
                'scope' => self::SCOPE_ALL,
            ]);

            return [
                'title' => $document->document_title,
                'subtitle' => $decorated->search_folder_path ?: 'Uncategorized',
                'type' => 'Document',
                'url' => route($route, $params),
                'match' => $decorated->search_match_label,
            ];
        })->all();
    }

    public function decorateResult(Document $document, string $term): Document
    {
        $document->search_folder_path = $this->folderBreadcrumb($document->folder);
        $document->search_match_label = $this->matchLabel($document, $term);
        $document->search_excerpt = $this->excerpt($document, $term);

        return $document;
    }

    public function excerpt(Document $document, string $term): string
    {
        $content = (string) $document->searchIndex?->content_text;
        if ($term === '' || $content === '') {
            return '';
        }
        $position = mb_stripos($content, $term);
        $start = $position === false ? 0 : max(0, $position - 90);

        return ($start > 0 ? '…' : '').Str::limit(mb_substr($content, $start, 240), 240);
    }

    public function matchLabel(Document $document, string $term): string
    {
        if ($term === '') {
            return 'Visible to you';
        }
        $lower = mb_strtolower($term);
        if (mb_stripos($document->document_title, $term) !== false) {
            return 'Title match';
        }
        if ($document->subject && mb_stripos($document->subject, $term) !== false) {
            return 'Subject match';
        }
        if ($document->tags && mb_stripos($document->tags, $term) !== false) {
            return 'Tag match';
        }
        if ($document->category && mb_stripos($document->category, $term) !== false) {
            return 'Category match';
        }
        if ($document->folder && mb_stripos($document->folder->folder_name, $term) !== false) {
            return 'Folder match';
        }
        if ($content = (string) $document->searchIndex?->content_text) {
            if (mb_stripos($content, $term) !== false) {
                return 'File content match';
            }
        }

        return 'Metadata match';
    }

    /**
     * @return list<User>
     */
    public function filterOptionsEmployees(User $user): Collection
    {
        $ids = $this->authorizedQuery($user)->distinct()->pluck('uploaded_by');

        return User::with('employee')->whereIn('id', $ids)->orderBy('username')->get();
    }

    /**
     * @return array{programs: Collection, courses: Collection, schoolYears: Collection}
     */
    public function filterOptions(User $user): array
    {
        $programs = CourseCatalog::programsForUser($user);
        $departments = $programs === null
            ? collect(Program::codes())
            : collect($programs)->filter(fn ($code) => in_array($code, Program::codes(), true))->values();

        $courses = Course::active()->ordered();
        if ($user->isProgramCoordinator() || $user->isFaculty()) {
            $courses->forDepartment($departments->all());
        }

        return [
            'programs' => $departments,
            'courses' => $courses->get(),
            'schoolYears' => SchoolYear::orderByDesc('start_year')->get(),
            'archivedSchoolYears' => SchoolYear::query()->whereNotNull('archived_at')->orderByDesc('start_year')->get(),
        ];
    }

    public function cacheScopeKey(User $user): string
    {
        $programs = $user->isProgramCoordinator() ? $user->handledPrograms() : [];

        return md5($user->id.'|'.$user->role_id.'|'.implode(',', $programs));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function revalidatePrograms(User $user, array $filters): array
    {
        if (empty($filters['program'])) {
            return $filters;
        }

        $allowed = CourseCatalog::programsForUser($user);
        if ($allowed === null) {
            if (!in_array($filters['program'], Program::codes(), true)) {
                unset($filters['program']);
            }

            return $filters;
        }

        if (!in_array($filters['program'], $allowed, true)) {
            unset($filters['program']);
        }

        return $filters;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function revalidateSchoolYear(User $user, array $filters): array
    {
        if (empty($filters['school_year_id'])) {
            return $filters;
        }

        $year = SchoolYear::find($filters['school_year_id']);
        if (!$year) {
            $filters['school_year_id'] = null;

            return $filters;
        }

        if (($filters['scope'] ?? '') === self::SCOPE_ARCHIVES && !$year->isArchived()) {
            $filters['school_year_id'] = null;
        }

        return $filters;
    }

    protected function normalizeScope(string $scope): string
    {
        return in_array($scope, [self::SCOPE_FOLDER, self::SCOPE_ALL, self::SCOPE_ARCHIVES], true)
            ? $scope
            : self::SCOPE_ALL;
    }

    protected function applyAcademicYearFolders(Builder $query, int $academicYearStart): void
    {
        $hierarchy = app(AcademicHierarchyService::class);
        $endYear = $academicYearStart + 1;
        $folderIds = array_merge(
            $hierarchy->folderIdsForSchoolYear('tg', $academicYearStart),
            $hierarchy->folderIdsForSchoolYear('eq', $academicYearStart),
        );
        $folderIds = array_merge(
            $folderIds,
            Folder::where('is_system', true)
                ->where(function ($q) use ($academicYearStart, $endYear) {
                    $q->where('slug', 'like', "%{$academicYearStart}-{$endYear}%")
                        ->orWhere('folder_name', 'like', "%{$academicYearStart}-{$endYear}%");
                })
                ->pluck('folder_id')
                ->all()
        );
        $folderIds = array_values(array_unique(array_filter($folderIds)));
        if ($folderIds !== []) {
            $query->whereIn('folder_id', $folderIds);
        }
    }

    protected function folderBreadcrumb(?Folder $folder): string
    {
        if (!$folder) {
            return '';
        }

        $names = array_map(fn (Folder $f) => $f->folder_name, $folder->getAncestors());
        $names[] = $folder->folder_name;

        return implode(' › ', $names);
    }

    protected function tabSlugForFolder(?Folder $folder): string
    {
        if (!$folder) {
            return 'accreditation-and-certifications';
        }

        $top = $folder;
        while ($top->parent_id !== null) {
            if (!$top->relationLoaded('parent')) {
                $top->load('parent');
            }
            $parent = $top->parent;
            if (!$parent) {
                break;
            }
            $top = $parent;
        }

        return Str::slug($top->folder_name);
    }
}
