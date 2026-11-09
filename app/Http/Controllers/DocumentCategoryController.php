<?php

namespace App\Http\Controllers;

use App\Models\DashboardLog;
use App\Models\DocumentCategory;
use App\Models\Folder;
use App\Services\DocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DocumentCategoryController extends Controller
{
    public function store(Request $request)
    {
        abort_unless($request->user()->hasOversightAccess() || $request->user()->isFaculty() || $request->user()->isProgramCoordinator(), 403);
        $data = $request->validate(['category_name' => 'required|string|max:80', 'description' => 'nullable|string|max:500', 'scope' => ['required', Rule::in(['personal', 'system'])]]);
        abort_if($data['scope'] === 'system' && ! $request->user()->hasOversightAccess(), 403);
        $owner = $data['scope'] === 'personal' ? $request->user()->id : null;
        $scope = $owner ? 'user-'.$owner : 'system';
        $name = $this->validateName($data['category_name'], $scope);
        try {
            DB::transaction(function () use ($data, $owner, $scope, $name, $request) {
                $category = DocumentCategory::create(['category_name' => $name, 'description' => $data['description'] ?? null, 'created_by' => $request->user()->id, 'owner_id' => $owner, 'scope_key' => $scope, 'name_key' => Str::lower($name), 'color' => '#0d5c3b']);
                $root = Folder::create(['folder_name' => $name, 'user_id' => $request->user()->id, 'is_system' => true, 'is_private' => (bool) $owner, 'privacy_owner_id' => $owner, 'slug' => 'managed-category-'.$category->category_id, 'level' => 0, 'sort_order' => (int) Folder::max('sort_order') + 1, 'document_category_id' => $category->category_id]);
                Folder::create(['folder_name' => 'Documents', 'parent_id' => $root->folder_id, 'user_id' => $request->user()->id, 'is_system' => true, 'is_private' => (bool) $owner, 'privacy_owner_id' => $owner, 'slug' => 'managed-category-'.$category->category_id.'-documents', 'level' => 1, 'document_category_id' => $category->category_id]);
                $this->log($request, 'Created', $category);
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['category_name' => 'A category with this name already exists.']);
        }

        return back()->with('success', 'Category created. It is ready in Documents and Upload Document.');
    }

    public function update(Request $request, DocumentCategory $category)
    {
        abort_unless($category->canManage($request->user()), 403);
        $data = $request->validate(['category_name' => 'required|string|max:80', 'description' => 'nullable|string|max:500', 'is_active' => 'required|boolean']);
        $name = $this->validateName($data['category_name'], $category->scope_key, $category->category_id);
        try {
            DB::transaction(function () use ($category, $data, $name, $request) {
                $category->update(['category_name' => $name, 'name_key' => Str::lower($name), 'description' => $data['description'] ?? null, 'is_active' => $data['is_active']]);
                $category->folders()->whereNull('parent_id')->update(['folder_name' => $name]);
                $this->log($request, 'Updated', $category);
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['category_name' => 'A category with this name already exists.']);
        }

        return back()->with('success', 'Category updated. Existing documents have been preserved.');
    }

    private function validateName(string $input, string $scope, ?int $ignore = null): string
    {
        $name = Str::squish($input);
        $key = Str::lower($name);
        $reserved = array_map(fn ($name) => Str::lower($name), array_merge(DocumentService::allowedCategories(), ['Custom Folders', 'Uncategorized Files']));
        if ($name === '' || in_array($key, $reserved, true) || DocumentCategory::where('scope_key', $scope)->where('name_key', $key)->when($ignore, fn ($q) => $q->where('category_id', '!=', $ignore))->exists()) {
            throw ValidationException::withMessages(['category_name' => 'Choose a unique name that is not an existing built-in category.']);
        }

        return $name;
    }

    private function log(Request $request, string $action, DocumentCategory $category): void
    {
        DashboardLog::create(['user_id' => $request->user()->id, 'activity' => $action.' category: '.$category->category_name, 'activity_type' => 'category_updated', 'visibility' => 'own']);
    }
}
