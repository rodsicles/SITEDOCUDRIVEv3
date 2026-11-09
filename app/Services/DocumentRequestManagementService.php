<?php

namespace App\Services;

use App\Models\{DashboardLog, DocumentRequest, Notification, User};
use App\Support\CoordinatorDepartment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DocumentRequestManagementService
{
    public function authorize(User $actor, DocumentRequest $item): void
    {
        abort_unless(($actor->hasOversightAccess() || $actor->isProgramCoordinator()) && (int) $item->requested_by === (int) $actor->id, 403);
    }

    public function eligiblePeople(User $actor)
    {
        return User::where('status', 'Active')->whereKeyNot($actor->id)->with(['employee', 'role'])
            ->when($actor->isProgramCoordinator(), fn ($q) => $q->whereHas('employee', fn ($e) => $e->whereIn('program', CoordinatorDepartment::requirePrograms($actor))));
    }

    public function apply(User $actor, DocumentRequest $item, array $input): DocumentRequest
    {
        $this->authorize($actor, $item);
        $data = validator($input, [
            'action' => ['required', Rule::in(['update', 'recipients', 'exempt', 'cancel', 'reopen'])],
            'lock_version' => 'required|integer|min:1', 'reason' => 'required|string|min:5|max:1000',
            'title' => 'sometimes|required|string|max:150', 'instructions' => 'sometimes|nullable|string|max:2000',
            'due_at' => 'sometimes|nullable|date', 'allow_late_submission' => 'sometimes|boolean',
            'recipient_ids' => 'sometimes|required|array|min:1|max:200', 'recipient_ids.*' => 'integer|distinct',
            'recipient_id' => 'sometimes|required|integer',
        ])->validate();
        return DB::transaction(function () use ($actor, $item, $data) {
            $item = DocumentRequest::lockForUpdate()->findOrFail($item->id);
            $this->authorize($actor, $item);
            abort_if($item->lock_version !== (int) $data['lock_version'], 409, 'This request changed. Reload before saving.');
            $item->load('recipients');
            $before = $this->snapshot($item);
            $affected = $item->recipients->where('status', '!=', 'exempt')->pluck('user_id');
            $action = $data['action'];
            abort_if($item->status !== 'open' && $action !== 'reopen', 422, 'Reopen this request before making changes.');
            if ($action === 'update' || $action === 'reopen') {
                if (array_key_exists('due_at', $data) && $data['due_at']) {
                    $due = \Carbon\Carbon::parse($data['due_at']);
                    if ($due->isPast() && (!$item->due_at || !$due->equalTo($item->due_at))) {
                        throw ValidationException::withMessages(['due_at' => 'Choose a future deadline.']);
                    }
                }
                $item->fill(\Illuminate\Support\Arr::only($data, ['title', 'instructions', 'due_at', 'allow_late_submission']));
                if ($action === 'reopen') {
                    abort_unless(in_array($item->status, ['cancelled', 'completed'], true), 422, 'Only a cancelled or completed request can be reopened.');
                    abort_if($item->due_at?->isPast() && !$item->allow_late_submission, 422, 'Set a future deadline or allow late submissions when reopening.');
                    $item->status = 'open';
                }
            } elseif ($action === 'recipients') {
                $ids = $data['recipient_ids'] ?? [];
                if (!$ids) throw ValidationException::withMessages(['recipient_ids' => 'Choose at least one recipient.']);
                $people = $this->eligiblePeople($actor)->whereIn('id', $ids)->get();
                abort_unless($people->count() === count($ids), 422, 'Choose active employees within your current scope.');
                if ($item->destinationFolder) {
                    abort_unless($item->destinationFolder->canBeViewedBy($actor), 404);
                    abort_if($item->destinationFolder->managedCategory && !$item->destinationFolder->managedCategory->is_active, 422, 'The request category is inactive.');
                }
                if ($item->course_id) {
                    abort_unless($item->course?->is_active, 422, 'This request course is inactive.');
                    foreach ($people as $person) {
                        abort_unless($person->employee?->program === $item->course->program && $person->assignedCourses()->where('courses.id', $item->course_id)->exists(), 422, 'Each recipient must have this course assigned in the matching program.');
                    }
                }
                $affected = collect();
                foreach ($people as $person) {
                    $recipient = $item->recipients()->firstOrCreate(['user_id' => $person->id]);
                    if ($recipient->wasRecentlyCreated) {
                        $affected->push($person->id);
                    } elseif ($recipient->status === 'exempt') {
                        $recipient->update(['status' => $recipient->previous_status ?: 'pending', 'previous_status' => null, 'exemption_reason' => null]);
                        $affected->push($person->id);
                    }
                }
                abort_if($affected->isEmpty(), 422, 'All selected employees are already active recipients.');
            } elseif ($action === 'exempt') {
                $recipient = $item->recipients()->findOrFail($data['recipient_id'] ?? 0);
                abort_unless(in_array($recipient->status, ['pending', 'submitted', 'changes_requested'], true), 422, 'Approved submissions stay approved. Only outstanding recipients can be exempted.');
                $recipient->update(['previous_status' => $recipient->status, 'status' => 'exempt', 'exemption_reason' => $data['reason']]);
                $affected = collect([$recipient->user_id]);
                if (!$item->recipients()->whereNotIn('status', ['approved', 'exempt'])->exists()) $item->status = 'completed';
            } elseif ($action === 'cancel') {
                $item->status = 'cancelled';
            }
            $item->lock_version++;
            $item->save();
            $item->load('recipients');
            $item->changes()->create(['actor_id' => $actor->id, 'action' => $action, 'reason' => trim($data['reason']), 'before' => $before, 'after' => $this->snapshot($item)]);
            foreach ($affected->unique() as $userId) {
                Notification::create(['user_id' => $userId, 'message' => Str::limit('Document request "'.$item->title.'": '.$action.'. '.$data['reason'].($item->due_at ? ' Deadline: '.$item->due_at->format('M d, Y g:i A').'.' : ' No deadline.'), 450),
                    'action_url' => route('document-requests.index', ['tab' => 'received'])]);
            }
            DashboardLog::create(['user_id' => $actor->id, 'activity' => Str::limit('Document request #'.$item->id.' '.$action.': '.$data['reason'], 250), 'activity_type' => 'document_request_'.$action, 'visibility' => 'own']);
            return $item;
        });
    }

    private function snapshot(DocumentRequest $item): array
    {
        return $item->only(['title', 'instructions', 'due_at', 'allow_late_submission', 'status']) + [
            'recipients' => $item->recipients->map(fn ($r) => $r->only(['id', 'user_id', 'status', 'submitted_document_id', 'exemption_reason']))->all(),
        ];
    }
}
