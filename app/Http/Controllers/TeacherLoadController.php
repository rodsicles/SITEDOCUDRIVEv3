<?php

namespace App\Http\Controllers;

use App\Models\{Program, SchoolYear, TeacherLoad, TeacherLoadItem};
use App\Services\TeacherLoadService;
use App\Support\SchoolTerm;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Validation\{Rule, ValidationException};

class TeacherLoadController extends Controller
{
    public function __construct(private TeacherLoadService $service) {}

    public function index(Request $request)
    {
        $viewer = $request->user();
        $manager = $viewer->hasOversightAccess() || $viewer->isProgramCoordinator();
        $filters = $request->validate([
            'program' => ['nullable', Rule::in(Program::codes())],
            'school_year_id' => ['nullable', 'integer', 'exists:school_years,id'],
            'semester' => ['nullable', Rule::in(SchoolTerm::options())],
            'employee_id' => ['nullable', 'integer'],
            'view' => ['nullable', Rule::in(['all', 'drafts'])],
        ]);
        abort_if(!$manager && ($filters['view'] ?? '') === 'drafts', 403);
        $loads = TeacherLoad::visibleTo($viewer)->with('schoolYear');
        foreach (['program', 'school_year_id', 'semester', 'employee_id'] as $key) {
            if (!empty($filters[$key])) $loads->where($key, $filters[$key]);
        }
        $draftCount = (clone $loads)->where(fn ($q) => $q->where('status', 'draft')->orWhereNotNull('revision_draft'))->count();
        if (($filters['view'] ?? '') === 'drafts') $loads->where(fn ($q) => $q->where('status', 'draft')->orWhereNotNull('revision_draft'));
        $loads = $loads->orderByDesc('created_at')->paginate(20)->withQueryString();
        $faculty = $manager ? $this->service->selectableFaculty($viewer) : collect();
        $years = SchoolYear::orderByDesc('start_year')->get();
        $assignmentTeacher = $manager
            ? (filled($filters['employee_id'] ?? null)
                ? $this->service->facultyQuery($viewer)->with('user')->findOrFail($filters['employee_id'])?->user
                : null)
            : $viewer;
        $showCurrentAssignments = empty($filters['school_year_id'])
            || (int) $filters['school_year_id'] === (int) SchoolYear::activeId();
        $teachingAssignments = $assignmentTeacher && $showCurrentAssignments
            ? app(\App\Services\TeachingAssignmentService::class)->current($assignmentTeacher, $filters['semester'] ?? null)
            : null;
        $manageSubjectsUrl = $manager && $assignmentTeacher && ($viewer->isDean() || $assignmentTeacher->isFaculty())
            ? route($viewer->hasOversightAccess() ? 'dean.edit-employee' : 'coordinator.edit-faculty', $assignmentTeacher->employee->employee_id)
            : null;
        return view('teacher-loads.index', compact('loads', 'faculty', 'years', 'manager', 'filters', 'teachingAssignments', 'manageSubjectsUrl', 'draftCount'));
    }

    private function manager(Request $request): void
    {
        abort_unless($request->user()->hasOversightAccess() || $request->user()->isProgramCoordinator(), 403);
    }

    private function find(Request $request, int $id): TeacherLoad
    {
        return TeacherLoad::visibleTo($request->user())->with('items')->findOrFail($id);
    }

    public function options(Request $request)
    {
        $this->manager($request);
        $data = $request->validate(['employee_id' => 'required|integer', 'semester' => ['required', Rule::in(SchoolTerm::options())]]);
        $faculty = $this->service->facultyQuery($request->user())->with('user.role')->findOrFail($data['employee_id']);
        $courses = $this->service->assignedCourses($faculty, $data['semester']);
        $roleName = $faculty->user?->role?->role_name ?? 'Faculty Employee';

        return response()->json([
            'courses' => $courses,
            'sections' => \App\Models\CourseSection::where('program', $faculty->program)->where('is_active', true)->orderBy('year_level')->orderBy('name')->get(['id', 'name', 'year_level']),
            'program' => $faculty->program,
            'department' => 'SITE',
            'employment_status' => $faculty->facultyTypeLabel(),
            'role_label' => $roleName === 'Program Coordinator' ? 'Program Coordinator' : 'Faculty',
            'has_assigned_courses' => $courses->isNotEmpty() || $faculty->user->assignedCourses()->exists(),
            'assigned_courses_count' => $courses->count(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->editingData($this->find($request, $id), $request))->header('Cache-Control', 'private, no-store');
    }

    private function editingData(TeacherLoad $load, Request $request): array
    {
        $data = $load->toArray();
        if (($request->user()->hasOversightAccess() || $request->user()->isProgramCoordinator()) && $load->revision_draft !== null) {
            $data = array_replace($data, $load->revision_draft['header'], ['items' => $load->revision_draft['items'],
                'id' => $load->id, 'lock_version' => $load->lock_version, 'editing_revision' => true]);
        }
        return $data;
    }

    public function change(Request $request, int $id)
    {
        $this->manager($request);
        $data = $request->validate(['action' => ['required', Rule::in(['revise', 'discard', 'void', 'delete-draft'])],
            'lock_version' => 'required|integer|min:1', 'reason' => 'required|string|min:5|max:1000']);
        $this->service->change($request->user(), $id, $data['action'], $data['lock_version'], trim($data['reason']));
        return redirect()->route('teacher-loads.index', $request->only(['employee_id', 'semester', 'school_year_id']))->with('success', 'Teaching load updated. Published history has been preserved.');
    }

    public function history(Request $request, int $id)
    {
        $load = $this->find($request, $id)->load('revisions.actor.employee');
        return view('teacher-loads.history', compact('load'));
    }

    public function revisionPdf(Request $request, int $id, int $revision)
    {
        $load = $this->find($request, $id);
        $record = $load->revisions()->findOrFail($revision);
        $snapshot = $record->snapshot;
        $historical = new TeacherLoad(\Illuminate\Support\Arr::except($snapshot, ['items', 'revisions']));
        $historical->setRelation('items', collect($snapshot['items'])->map(fn ($item) => new TeacherLoadItem($item)));
        return $this->pdf($historical, $request->boolean('inline'));
    }

    public function store(Request $request)
    {
        $this->manager($request);
        try {
            $load = $this->service->save($request->user(), $request->all());
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['semester' => 'A load already exists for this faculty and term. Refresh the list to open it.']);
        }
        return response()->json($load, 201);
    }

    public function update(Request $request, int $id)
    {
        $this->manager($request);
        return response()->json($this->editingData($this->service->save($request->user(), $request->all(), $this->find($request, $id)), $request));
    }

    public function finalize(Request $request, int $id)
    {
        $this->manager($request);
        $data = $request->validate(['lock_version' => 'required|integer|min:1']);
        return response()->json($this->service->finalize($request->user(), $this->find($request, $id), $data['lock_version']));
    }

    public function preview(Request $request)
    {
        $this->manager($request);
        $request->validate(['id' => 'nullable|integer']);
        $existing = $request->filled('id') ? $this->find($request, (int) $request->input('id')) : null;
        abort_if($existing && $existing->status !== 'draft' && $existing->revision_draft === null, 409, 'Start a revision before previewing changes to a finalized record.');
        $data = $this->service->validate($request->user(), $request->all(), $existing);
        $load = new TeacherLoad($data['header'] + ['status' => 'draft']);
        $load->setRelation('items', collect($data['items'])->map(fn ($row) => new TeacherLoadItem($row)));
        return $this->pdf($load, true);
    }

    public function export(Request $request, int $id)
    {
        return $this->pdf($this->find($request, $id), $request->boolean('inline'));
    }

    private function pdf(TeacherLoad $load, bool $inline)
    {
        $logo = 'data:image/png;base64,'.base64_encode(file_get_contents(public_path('images/SPUP-final-logo.png')));
        $pdf = Pdf::loadView('teacher-loads.pdf', compact('load', 'logo'))->setPaper('a4', 'portrait')
            ->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Serif']);
        $pdf->render();
        $pdf->getDomPDF()->getCanvas()->page_text(478, 814, 'Page {PAGE_NUM} of {PAGE_COUNT}', $pdf->getDomPDF()->getFontMetrics()->getFont('DejaVu Serif'), 8);
        $filename = $this->pdfFilename($load);
        $response = $inline ? $pdf->stream($filename) : $pdf->download($filename);
        return $response->header('Cache-Control', 'private, no-store');
    }

    private function pdfFilename(TeacherLoad $load): string
    {
        $slug = static function (?string $value): string {
            $clean = preg_replace('/[^\p{L}\p{N}]+/u', '_', trim((string) $value)) ?: '';
            return trim($clean, '_') ?: 'Teacher';
        };

        $parts = array_filter([
            $slug($load->faculty_name),
            $slug($load->program),
            $slug($load->semester),
            $slug(str_replace(['/', '–', '—'], '-', (string) $load->academic_year)),
            'Teachers_Load',
            'Revision_'.($load->revision_number ?? 1),
        ]);

        return implode('_', $parts).'.pdf';
    }
}
