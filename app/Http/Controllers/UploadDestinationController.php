<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Folder;
use App\Services\AcademicHierarchyService;
use App\Services\DocumentService;
use App\Services\FolderService;
use App\Support\IteSubjects;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UploadDestinationController extends Controller
{
    public function index(Request $request, FolderService $folders, AcademicHierarchyService $hierarchy)
    {
        $user = $request->user();
        abort_unless($user->isFaculty() || $user->isProgramCoordinator() || $user->hasOversightAccess(), 403);
        $request->validate(['folder' => 'nullable|integer']);
        return response()->json($this->describe($user, $request->filled('folder') ? (int) $request->folder : null, $folders, $hierarchy));
    }

    public function describe(\App\Models\User $user, ?int $folderId, FolderService $folders, AcademicHierarchyService $hierarchy): array
    {
        $roots = $folders->getSystemFolderTree($user);
        $roots = $roots->filter(fn ($root) => !$root->document_category_id || $root->managedCategory?->is_active);
        $folder = $folderId ? $folders->visibleFolderOrFail($folderId, $user) : null;
        if ($folder) {
            // Recheck every level, including course assignments and active school year.
            $available = $roots;
            foreach (collect($folder->getAncestors())->push($folder) as $ancestor) {
                abort_unless($available->contains('folder_id', $ancestor->folder_id), 404);
                $available = $folders->getDisplayFolders($ancestor, $user);
            }
        } else {
            $available = $roots;
        }
        $category = $folder ? app(DocumentService::class)->resolveCategoryForFolder($folder->folder_id) : null;
        $academic = in_array($category, Document::SHAREABLE_CATEGORIES, true);
        $semester = $folder && ($hierarchy->isTgSemesterFolder($folder) || $hierarchy->isEqSemesterFolder($folder));
        $uploadable = $folder && ($academic
            ? ($hierarchy->isTgUploadLeafFolder($folder) || $hierarchy->isEqUploadLeafFolder($folder))
            : ($folder->parent_id && !$folder->isCustomFoldersCategory() && $available->isEmpty()));
        $serialize = fn (Folder $item) => ['id' => $item->folder_id, 'name' => $item->folder_name];
        return [
            'folder' => $folder ? $serialize($folder) : null,
            'breadcrumbs' => $folder ? collect($folder->getAncestors())->push($folder)->map($serialize)->values() : [],
            'folders' => $available->map($serialize)->values(),
            'uploadable' => (bool) $uploadable,
            'academic' => $academic,
            'subjects' => $semester ? IteSubjects::labelsForUser($user) : [],
            'url' => $folder ? route(($user->hasOversightAccess() ? 'dean' : ($user->isProgramCoordinator() ? 'coordinator' : 'faculty')).'.documents', [
                'folder' => $folder->folder_id,
                'tab' => (collect($folder->getAncestors())->first() ?? $folder)->tabKey(),
            ]) : null,
        ];
    }

    public function subject(Request $request, FolderService $folders, AcademicHierarchyService $hierarchy)
    {
        $request->validate(['folder' => 'required|integer', 'subject' => ['required', Rule::in(IteSubjects::labelsForUser($request->user()))]]);
        // Apply the same role, hierarchy and assignment checks before creating folders.
        $this->index($request, $folders, $hierarchy);
        $semester = $folders->visibleFolderOrFail((int) $request->folder, $request->user());
        abort_unless($hierarchy->isTgSemesterFolder($semester) || $hierarchy->isEqSemesterFolder($semester), 422);
        $folder = $hierarchy->isTgSemesterFolder($semester)
            ? $hierarchy->ensureSubjectWithTgLb($semester, $request->subject)
            : $hierarchy->ensureSubjectWithEqStructure($semester, $request->subject);
        return response()->json(['id' => $folder->folder_id]);
    }
}
