<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\AnnouncementReaction;
use App\Models\Notification;
use App\Services\AnnouncementService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnnouncementController extends Controller
{
    public function __construct(
        protected AnnouncementService $announcementService
    ) {}

    /** Allowed reaction emojis (server-side whitelist). */
    public const ALLOWED_REACTIONS = ['👍'];

    public function index()
    {
        $user = auth()->user();

        $announcements = Announcement::with(['author.employee', 'reads', 'reactions'])
            ->active()
            ->visibleTo($user)
            ->ordered()
            ->paginate(8)
            ->withQueryString();

        $rolePrefix = $this->getRolePrefix();
        $sidebar = $this->getSidebarData();

        return view('announcements.index', compact('announcements', 'rolePrefix', 'sidebar'));
    }

    public function create()
    {
        return redirect()->route('announcements.index', ['compose' => 1]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string|max:5000',
            'audience' => 'required|in:everyone,faculty,coordinators,dean,BLIS,BSEnSE,BSIT,BSCpE',
            'is_pinned' => 'boolean',
            'expires_at' => 'nullable|date|after:now',
        ]);

        $validated = array_merge($validated, $this->mapAudience($validated['audience']));
        unset($validated['audience']);
        $validated['is_pinned'] = $request->boolean('is_pinned');

        $this->announcementService->createAnnouncement($validated, auth()->user());

        return redirect()->route('announcements.index')
            ->with('success', 'Announcement posted successfully');
    }

    public function edit($id)
    {
        $announcement = Announcement::where('announcement_id', $id)
            ->where('author_id', auth()->id())
            ->firstOrFail();

        $rolePrefix = $this->getRolePrefix();
        $sidebar = $this->getSidebarData();
        $audienceOptions = self::audienceOptions();
        $selectedAudience = old('audience', $this->audienceFromFilters(
            $announcement->visibility,
            $announcement->program
        ));

        return view('announcements.edit', compact(
            'announcement',
            'rolePrefix',
            'sidebar',
            'audienceOptions',
            'selectedAudience'
        ));
    }

    public function update(Request $request, $id)
    {
        $announcement = Announcement::where('announcement_id', $id)
            ->where('author_id', auth()->id())
            ->firstOrFail();

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string|max:5000',
            'audience' => 'required|in:everyone,faculty,coordinators,dean,BLIS,BSEnSE,BSIT,BSCpE',
            'is_pinned' => 'boolean',
            'expires_at' => 'nullable|date|after:now',
        ]);

        $validated = array_merge($validated, $this->mapAudience($validated['audience']));
        unset($validated['audience']);
        $validated['is_pinned'] = $request->boolean('is_pinned');

        $this->announcementService->updateAnnouncement($announcement, $validated, auth()->id());

        return redirect()->route('announcements.index')
            ->with('success', 'Announcement updated successfully');
    }

    public function destroy($id)
    {
        $user = auth()->user();
        $announcement = Announcement::where('announcement_id', $id)->firstOrFail();

        if ($announcement->author_id !== $user->id && !$user->hasOversightAccess()) {
            abort(403);
        }

        $this->announcementService->deleteAnnouncement($announcement, auth()->id());

        return redirect()->route('announcements.index')
            ->with('success', 'Announcement deleted successfully');
    }

    public function markAsRead($id)
    {
        $this->announcementService->markAsRead($id, auth()->id());

        return response()->json(['success' => true]);
    }

    /**
     * Return read/unread breakdown of the announcement's target audience.
     * Restricted to the author or a Dean for accountability/follow-up.
     */
    public function receipts($id)
    {
        $user = auth()->user();
        $announcement = Announcement::where('announcement_id', $id)->firstOrFail();

        if ($announcement->author_id !== $user->id && !$user->hasOversightAccess()) {
            abort(403);
        }

        $audience = $announcement->targetAudienceQuery()->get();
        $reads = $announcement->reads()->get()->keyBy('user_id');

        $read = [];
        $unread = [];
        foreach ($audience as $member) {
            $row = [
                'id'         => $member->id,
                'name'       => $member->employee->full_name ?? $member->name,
                'role'       => $member->role->role_name ?? '',
                'program' => $member->employee->program ?? '',
            ];
            if (isset($reads[$member->id])) {
                $row['read_at'] = optional($reads[$member->id]->read_at)->toIso8601String();
                $read[] = $row;
            } else {
                $unread[] = $row;
            }
        }

        return response()->json([
            'announcement_id' => $announcement->announcement_id,
            'title'           => $announcement->title,
            'total_audience'  => count($audience),
            'total_read'      => count($read),
            'total_unread'    => count($unread),
            'read'            => $read,
            'unread'          => $unread,
        ]);
    }

    /**
     * Toggle a reaction emoji on an announcement for the authenticated user.
     */
    public function toggleReaction(Request $request, $id)
    {
        $validated = $request->validate([
            'emoji' => ['required', 'string', Rule::in(self::ALLOWED_REACTIONS)],
        ]);

        $user = auth()->user();

        // Ensure the announcement exists and is visible to the user.
        $announcement = Announcement::visibleTo($user)
            ->where('announcement_id', $id)
            ->firstOrFail();

        $existing = AnnouncementReaction::where('announcement_id', $announcement->announcement_id)
            ->where('user_id', $user->id)
            ->where('emoji', $validated['emoji'])
            ->first();

        if ($existing) {
            $existing->delete();
            $reacted = false;
        } else {
            AnnouncementReaction::create([
                'announcement_id' => $announcement->announcement_id,
                'user_id'         => $user->id,
                'emoji'           => $validated['emoji'],
            ]);
            $reacted = true;
        }

        // Build counts for the whitelisted set so the UI gets a stable shape.
        $countsRaw = AnnouncementReaction::where('announcement_id', $announcement->announcement_id)
            ->selectRaw('emoji, COUNT(*) as total')
            ->groupBy('emoji')
            ->pluck('total', 'emoji');

        $counts = [];
        foreach (self::ALLOWED_REACTIONS as $emoji) {
            $counts[$emoji] = (int) ($countsRaw[$emoji] ?? 0);
        }

        $userReactions = AnnouncementReaction::where('announcement_id', $announcement->announcement_id)
            ->where('user_id', $user->id)
            ->pluck('emoji')
            ->all();

        return response()->json([
            'success'        => true,
            'emoji'          => $validated['emoji'],
            'reacted'        => $reacted,
            'counts'         => $counts,
            'user_reactions' => $userReactions,
        ]);
    }

    private function getRolePrefix(): string
    {
        $user = auth()->user();
        if ($user->isDeanOrSecretary()) return 'dean';
        if ($user->isProgramCoordinator()) return 'coordinator';
        return 'faculty';
    }

    private function getSidebarData(): array
    {
        $user = auth()->user();
        $unreadNotifications = 0;

        if ($user->isFaculty()) {
            $unreadNotifications = Notification::where('user_id', $user->id)
                ->where('is_read', false)
                ->count();
        }

        return [
            'rolePrefix' => $this->getRolePrefix(),
            'unreadNotifications' => $unreadNotifications,
        ];
    }

    /**
     * Single audience control labels (maps to visibility + department).
     *
     * @return array<string, string>
     */
    public static function audienceOptions(): array
    {
        return [
            'everyone' => 'Everyone',
            'faculty' => 'Faculty only',
            'coordinators' => 'Coordinators only',
            'dean' => 'Dean only',
            ...\App\Models\Program::labels(),
        ];
    }

    /**
     * @return array{visibility: string, department: string}
     */
    private function mapAudience(string $audience): array
    {
        if (in_array($audience, \App\Models\Program::codes(), true)) return ['visibility' => 'All', 'program' => $audience];
        return match ($audience) {
            'faculty' => ['visibility' => 'Faculty Employee', 'program' => 'All'],
            'coordinators' => ['visibility' => 'Program Coordinator', 'program' => 'All'],
            'dean' => ['visibility' => 'Dean', 'program' => 'All'],
            default => ['visibility' => 'All', 'program' => 'All'],
        };
    }

    private function audienceFromFilters(?string $visibility, ?string $department): string
    {
        $visibility = $visibility ?: 'All';
        $department = $department ?: 'All';

        if (in_array($department, \App\Models\Program::codes(), true) && $visibility === 'All') return $department;
        if ($visibility === 'Faculty Employee') {
            return 'faculty';
        }
        if ($visibility === 'Program Coordinator') {
            return 'coordinators';
        }
        if ($visibility === 'Dean') {
            return 'dean';
        }

        return 'everyone';
    }
}
