<?php

namespace App\Http\Controllers;

use App\Models\Folder;
use App\Services\AcademicHierarchyService;
use App\Support\IteSubjects;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EqSubjectFolderController extends Controller
{
    public function store(Request $request, AcademicHierarchyService $hierarchy)
    {
        $user = auth()->user();
        $semester = Folder::findOrFail($request->input('folder_id'));

        if (!$hierarchy->isEqSemesterFolder($semester)) {
            abort(404);
        }

        $validated = $request->validate([
            'folder_id' => 'required|exists:folders,folder_id',
            'subject' => ['required', 'string', Rule::in(IteSubjects::labelsForUser($user))],
            'tab' => 'nullable|string',
        ]);

        $subjectFolder = $hierarchy->ensureSubjectWithEqStructure($semester, $validated['subject']);

        $routeName = match (true) {
            $user->isDeanOrSecretary() => 'dean.documents',
            $user->isProgramCoordinator() => 'coordinator.documents',
            default => 'faculty.documents',
        };

        return redirect()->route($routeName, [
            'tab' => $validated['tab'] ?? 'exam-questionnaires',
            'folder' => $subjectFolder->folder_id,
        ])->with('success', 'Subject folder ready. Open Prelims, Midterms, or Finals, then TOS or TOQ to upload.');
    }
}
