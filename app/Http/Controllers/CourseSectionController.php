<?php

namespace App\Http\Controllers;

use App\Models\{CourseSection, DashboardLog, Program};
use App\Support\CoordinatorDepartment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\{Rule, ValidationException};
use Illuminate\Database\UniqueConstraintViolationException;

class CourseSectionController extends Controller
{
    private function programs(Request $request): array
    {
        abort_unless($request->user()->hasOversightAccess() || $request->user()->isProgramCoordinator(), 403);
        return $request->user()->hasOversightAccess() ? Program::codes() : CoordinatorDepartment::requirePrograms($request->user());
    }

    public function index(Request $request)
    {
        $programs = $this->programs($request);
        $filters = $request->validate(['program' => ['nullable', Rule::in(['all', ...$programs])], 'search' => 'nullable|string|max:100', 'status' => ['nullable', Rule::in(['active', 'inactive', 'all'])]]);
        $sections = CourseSection::visibleTo($request->user())
            ->when(($filters['program'] ?? 'all') !== 'all', fn ($q) => $q->where('program', $filters['program']))
            ->when(($filters['status'] ?? 'active') !== 'all', fn ($q) => $q->where('is_active', ($filters['status'] ?? 'active') === 'active'))
            ->when(filled($filters['search'] ?? null), fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%'))
            ->orderBy('program')->orderBy('year_level')->orderBy('name')->paginate(30)->withQueryString();
        $routePrefix = $request->user()->hasOversightAccess() ? 'dean' : 'coordinator';
        return view('sections.index', compact('sections', 'programs', 'filters', 'routePrefix'));
    }

    public function store(Request $request)
    {
        $programs = $this->programs($request);
        $data = $request->validate(['program' => ['required', Rule::in($programs)], 'year_level' => 'required|integer|min:1|max:4', 'name' => 'required|string|max:40']);
        $data['name'] = $this->name($data['name']);
        try {
            DB::transaction(function () use ($request, $data) {
                $section = CourseSection::create($data + ['is_active' => true, 'lock_version' => 1]);
                $this->log($request, $section, 'created');
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'This section already exists in this program. Restore it if inactive, or use a different name.']);
        }
        return $this->redirect($request)->with('success', 'Section added to Teacher’s Load choices.');
    }

    public function update(Request $request, int $id)
    {
        $this->programs($request);
        $data = $request->validate(['name' => 'required|string|max:40', 'year_level' => 'required|integer|min:1|max:4', 'lock_version' => 'required|integer|min:1']);
        $data['name'] = $this->name($data['name']);
        try {
            DB::transaction(function () use ($request, $data, $id) {
                $section = CourseSection::visibleTo($request->user())->lockForUpdate()->findOrFail($id);
                if ($section->lock_version !== (int) $data['lock_version']) throw ValidationException::withMessages(['section' => 'This section changed in another session. Refresh the list before editing.']);
                $before = $section->name;
                $section->update(['name' => $data['name'], 'year_level' => $data['year_level'], 'lock_version' => $section->lock_version + 1]);
                $this->log($request, $section, 'renamed from '.$before);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'This section name already exists in this program.']);
        }
        return $this->redirect($request)->with('success', 'Section updated. Existing teaching-load snapshots keep their saved section names.');
    }

    public function status(Request $request, int $id)
    {
        $this->programs($request);
        $data = $request->validate(['is_active' => 'required|boolean', 'lock_version' => 'required|integer|min:1']);
        DB::transaction(function () use ($request, $data, $id) {
            $section = CourseSection::visibleTo($request->user())->lockForUpdate()->findOrFail($id);
            if ($section->lock_version !== (int) $data['lock_version']) throw ValidationException::withMessages(['section' => 'This section changed. Refresh the list before continuing.']);
            $section->update(['is_active' => (bool) $data['is_active'], 'lock_version' => $section->lock_version + 1]);
            $this->log($request, $section, $section->is_active ? 'restored' : 'removed from new choices');
        });
        return $this->redirect($request)->with('success', 'Section availability updated. Saved teaching loads are unchanged.');
    }

    private function name(string $value): string
    {
        $name = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $value)));
        if ($name === '') throw ValidationException::withMessages(['name' => 'Enter a section name.']);
        return $name;
    }

    private function redirect(Request $request)
    {
        return redirect()->route(($request->user()->hasOversightAccess() ? 'dean' : 'coordinator').'.courses', ['view' => 'sections']);
    }

    private function log(Request $request, CourseSection $section, string $action): void
    {
        DashboardLog::create(['user_id' => $request->user()->id, 'activity' => "Section {$section->name} ({$section->program}) $action", 'activity_type' => 'section_updated', 'visibility' => 'dean']);
    }
}
