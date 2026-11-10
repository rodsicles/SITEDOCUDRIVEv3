<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecretaryRoleTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $username): User
    {
        return User::where('username', $username)->with('employee')->firstOrFail();
    }

    private function otherFaculty(): User
    {
        $user = User::create([
            'role_id' => Role::idFor(Role::FACULTY), 'username' => 'faculty2', 'name' => 'Other Faculty',
            'password' => Hash::make('password123'), 'status' => 'Active', 'must_change_password' => false,
        ]);
        Employee::create(['user_id' => $user->id, 'employee_no' => 'FAC-TEST-2', 'full_name' => 'Other Faculty', 'program' => 'BSIT', 'position' => 'Faculty Employee']);

        return $user->fresh();
    }

    public function test_secretary_has_dean_level_daily_access(): void
    {
        $secretary = $this->user('secretary');
        $this->assertTrue($secretary->hasOversightAccess());
        $this->actingAs($secretary);

        foreach (['teacher-loads.index', 'dean.rejected-files.index', 'dean.courses', 'dean.audit-trail', 'document-requests.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
        // The create route opens the feed composer instead of a separate page.
        $this->get(route('announcements.create'))->assertRedirect(route('announcements.index', ['compose' => 1]));
        $this->get(route('document-requests.index'))->assertSee('New request');
    }

    public function test_four_dean_only_powers_stay_with_the_dean(): void
    {
        $this->actingAs($this->user('secretary'));

        $this->post(route('dean.analytics.close-month'))->assertForbidden();
        $this->post(route('dean.analytics.reset-this-month'))->assertForbidden();
        $this->post(route('dean.audit-trail.reset'))->assertForbidden();
        $this->delete(route('dean.recycle-bin.force-delete', 1))->assertForbidden();
        $this->post(route('dean.store-admin'), [])->assertForbidden();
        $this->assertSame([\App\Models\Role::ADMIN], app(\App\Services\PasswordResetRequestService::class)->handlerRolesFor($this->user('dean')));
    }

    public function test_faculty_can_request_only_from_the_secretary_with_general_category(): void
    {
        $faculty = $this->user('faculty');
        $secretary = $this->user('secretary');
        $this->actingAs($faculty);

        $this->get(route('document-requests.index'))->assertOk()->assertSee('Request a document from the Secretary');
        $people = $this->getJson(route('document-requests.recipients'))->assertOk()->json('people');
        $this->assertSame([$secretary->id], array_column($people, 'id'));

        $this->post(route('document-requests.store'), [
            'title' => 'Certificate of employment', 'request_category' => 'general', 'document_type' => 'pdf',
            'recipient_ids' => [$this->otherFaculty()->id],
        ])->assertSessionHasErrors('recipient_ids');

        $this->post(route('document-requests.store'), [
            'title' => 'Teaching guide please', 'request_category' => 'teaching_guide', 'document_type' => 'pdf',
            'recipient_ids' => [$secretary->id],
        ])->assertSessionHasErrors();

        $this->post(route('document-requests.store'), [
            'title' => 'Certificate of employment', 'request_category' => 'general', 'document_type' => 'pdf',
            'recipient_ids' => [$secretary->id],
        ])->assertSessionHasNoErrors()->assertRedirect(route('document-requests.index', ['tab' => 'sent']));

        $item = DocumentRequest::where('title', 'Certificate of employment')->firstOrFail();
        $this->assertSame((int) $faculty->id, (int) $item->requested_by);
        $this->assertSame([(int) $secretary->id], $item->recipients()->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        $this->assertTrue(Notification::where('user_id', $secretary->id)->where('message', 'like', '%Certificate of employment%')->exists());
    }

    public function test_coordinator_can_pick_the_secretary(): void
    {
        $coordinator = $this->user('coordinator');
        $secretary = $this->user('secretary');
        $ids = app(\App\Services\DocumentRequestManagementService::class)->eligiblePeople($coordinator)->pluck('id')->all();
        $this->assertContains($secretary->id, $ids);
    }

    public function test_secretary_uploads_requested_file_and_only_requester_side_can_open_it(): void
    {
        Storage::fake('local'); Queue::fake(); config(['filesystems.upload_disk' => 'local']);
        $faculty = $this->user('faculty');
        $secretary = $this->user('secretary');
        $other = $this->otherFaculty();

        $this->actingAs($faculty)->post(route('document-requests.store'), [
            'title' => 'Service record', 'request_category' => 'general', 'document_type' => 'pdf', 'recipient_ids' => [$secretary->id],
        ])->assertSessionHasNoErrors();
        $recipient = DocumentRequest::where('title', 'Service record')->firstOrFail()->recipients()->firstOrFail();

        $this->actingAs($secretary)->post(route('document-requests.submit', $recipient), [
            'file' => UploadedFile::fake()->create('service-record.pdf', 10, 'application/pdf'),
        ])->assertRedirect(route('document-requests.index', ['tab' => 'received']));

        $document = Document::findOrFail($recipient->fresh()->submitted_document_id);
        $this->assertSame((int) $secretary->id, (int) $document->uploaded_by);
        $this->assertTrue($document->canView($faculty));
        $this->assertFalse($document->canView($other));
        $this->assertTrue(Notification::where('user_id', $faculty->id)->where('message', 'like', '%Service record%')->exists());

        $this->actingAs($faculty)->post(route('document-requests.review', $recipient), ['decision' => 'approved'])->assertRedirect();
        $this->assertSame('approved', $recipient->fresh()->status);
        $this->assertSame('completed', $recipient->request->fresh()->status);
    }
}
