<?php

namespace Tests\Feature;

use App\Models\DashboardLog;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Folder;
use App\Models\Role;
use App\Models\TeachingGuide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminRoleTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(string $username = 'admin1'): User
    {
        $user = User::create([
            'role_id' => Role::idFor(Role::ADMIN), 'username' => $username, 'name' => 'Ada Admin',
            'password' => Hash::make('password123'), 'status' => 'Active', 'must_change_password' => false,
        ]);
        Employee::create(['user_id' => $user->id, 'employee_no' => 'SITE-GEN-ADMIN-'.$username, 'full_name' => 'Ada Admin', 'position' => 'Admin']);

        return $user->fresh();
    }

    private function fixture(string $username): User
    {
        return User::where('username', $username)->with('employee')->firstOrFail();
    }

    public function test_admin_role_exists_and_has_oversight_but_is_not_dean(): void
    {
        $admin = $this->makeAdmin();

        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($admin->hasOversightAccess());
        $this->assertTrue($admin->isDeanOrSecretary());
        $this->assertFalse($admin->isDean());
        $this->assertTrue($this->fixture('secretary')->hasOversightAccess());
        $this->assertFalse($this->fixture('secretary')->isDean());
    }

    public function test_admin_can_open_daily_oversight_pages(): void
    {
        $this->actingAs($this->makeAdmin());

        foreach (['dean.employees', 'dean.courses', 'dean.recycle-bin.index', 'dean.rejected-files.index', 'dean.audit-trail', 'password-reset-requests.index', 'teacher-loads.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
    }

    public function test_dean_only_actions_are_blocked_for_admin_on_direct_endpoints(): void
    {
        $this->actingAs($this->makeAdmin());

        $this->get(route('dean.backup'))->assertForbidden();
        $this->post(route('dean.store-coordinator'), [])->assertForbidden();
        $this->post(route('dean.store-admin'), [])->assertForbidden();
        $this->post(route('dean.archives.archive'), [])->assertForbidden();
        $this->post(route('dean.audit-trail.reset'))->assertForbidden();
        $this->post(route('dean.analytics.close-month'))->assertForbidden();
        $this->post(route('dean.analytics.reset-this-month'))->assertForbidden();
        $this->delete(route('dean.recycle-bin.force-delete', 1))->assertForbidden();
        $this->delete(route('dean.documents.force-delete', 1))->assertForbidden();
        $this->post(route('dean.destroy-employee', 1), [])->assertForbidden();
    }

    public function test_secretary_keeps_existing_dean_office_actions_but_not_admin_creation(): void
    {
        $this->actingAs($this->fixture('secretary'));

        $this->get(route('dean.backup'))->assertOk();
        $this->get(route('dean.rejected-files.index'))->assertOk();
        $this->post(route('dean.store-admin'), [])->assertForbidden();
    }

    public function test_admin_creates_and_manages_faculty_but_not_coordinators_dean_or_admins(): void
    {
        $admin = $this->makeAdmin();
        $otherAdmin = $this->makeAdmin('admin2');
        $this->actingAs($admin);

        $this->post(route('dean.store-faculty'), [
            'first_name' => 'Fiona', 'last_name' => 'Faculty', 'username' => 'fiona',
            'password' => 'password123', 'program' => 'BSIT', 'faculty_type' => 'full_time',
            'course_ids' => [\App\Models\Course::where('program', 'BSIT')->where('is_active', true)->value('id')],
        ])->assertSessionHasNoErrors();
        $this->assertTrue(User::where('username', 'fiona')->firstOrFail()->isFaculty());

        $faculty = $this->fixture('faculty');
        $this->get(route('dean.edit-employee', $faculty->employee->employee_id))->assertOk();

        foreach (['coordinator', 'dean'] as $username) {
            $id = $this->fixture($username)->employee->employee_id;
            $this->get(route('dean.edit-employee', $id))->assertForbidden();
            $this->post(route('dean.deactivate-employee', $id))->assertForbidden();
            $this->post(route('dean.temporary-password', $id))->assertForbidden();
        }
        $this->post(route('dean.deactivate-employee', $otherAdmin->employee->employee_id))->assertForbidden();
        $this->assertSame('Active', $otherAdmin->fresh()->status);
    }

    public function test_dean_creates_admin_account_that_must_rotate_password(): void
    {
        $this->actingAs($this->fixture('dean'));

        $this->post(route('dean.store-admin'), [
            '_form' => 'admin', 'first_name' => 'Alex', 'middle_name' => 'B', 'last_name' => 'Cruz',
            'username' => 'alexadmin', 'password' => 'password123',
        ])->assertRedirect(route('dean.employees'));

        $admin = User::where('username', 'alexadmin')->with('employee')->firstOrFail();
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($admin->must_change_password);
        $this->assertSame('Alex B Cruz', $admin->employee->full_name);
        $this->assertNull($admin->employee->program);
        $this->assertStringStartsWith('SITE-GEN-ADMIN', $admin->employee->employee_no);
        $this->assertTrue(DashboardLog::where('activity_type', 'account_created')->where('target_user_id', $admin->id)->exists());
    }

    public function test_admin_issues_one_time_temporary_password_for_faculty(): void
    {
        $faculty = $this->fixture('faculty');
        $oldHash = $faculty->password;
        $this->actingAs($this->makeAdmin());

        $response = $this->post(route('dean.temporary-password', $faculty->employee->employee_id))
            ->assertRedirect(route('dean.employee-profile', $faculty->employee->employee_id))
            ->assertSessionHas('temp_password');

        $faculty->refresh();
        $this->assertNotSame($oldHash, $faculty->password);
        $this->assertTrue($faculty->must_change_password);
        $this->assertTrue(Hash::check(session('temp_password'), $faculty->password));
        $response->assertSessionHas('temp_password_user', $faculty->employee->full_name);
    }

    public function test_admin_sees_all_program_documents_but_not_private_folders(): void
    {
        $admin = $this->makeAdmin();
        $faculty = $this->fixture('faculty');
        $public = Document::create(['uploaded_by' => $faculty->id, 'document_title' => 'Public file', 'file_path' => 'documents/a.pdf', 'document_type' => 'pdf', 'category' => 'Other']);
        $folder = Folder::create(['user_id' => $faculty->id, 'folder_name' => 'Private', 'is_system' => false, 'is_private' => true, 'privacy_owner_id' => $faculty->id, 'slug' => 'private-test']);
        $private = Document::create(['uploaded_by' => $faculty->id, 'folder_id' => $folder->folder_id, 'document_title' => 'Private file', 'file_path' => 'documents/b.pdf', 'document_type' => 'pdf', 'category' => 'Other']);

        $this->assertTrue($public->canView($admin));
        $this->assertFalse($private->canView($admin));
        $this->assertSame([$public->document_id], Document::visibleTo($admin)->pluck('document_id')->all());
    }

    public function test_rejected_files_page_lists_rejected_submissions_for_oversight(): void
    {
        $faculty = $this->fixture('faculty');
        $base = ['user_id' => $faculty->id, 'file_type' => 'pdf', 'semester' => '1st', 'academic_year' => '2026-2027'];
        TeachingGuide::create($base + ['title' => 'Rejected syllabus', 'subject' => 'IT101', 'file_path' => 'teaching-guides/x.pdf', 'status' => 'rejected', 'remarks' => 'Missing outcomes']);
        TeachingGuide::create($base + ['title' => 'Approved syllabus', 'subject' => 'IT102', 'file_path' => 'teaching-guides/y.pdf', 'status' => 'approved']);

        $this->actingAs($this->makeAdmin())->get(route('dean.rejected-files.index'))
            ->assertOk()->assertSee('Rejected syllabus')->assertSee('Missing outcomes')->assertDontSee('Approved syllabus');
        $this->actingAs($faculty)->get(route('dean.rejected-files.index'))->assertForbidden();
    }

    public function test_audit_trail_filters_actions_by_admin(): void
    {
        $admin = $this->makeAdmin();
        DashboardLog::create(['user_id' => $admin->id, 'activity' => 'Admin did something', 'activity_type' => 'test']);
        DashboardLog::create(['user_id' => $this->fixture('dean')->id, 'activity' => 'Dean did something', 'activity_type' => 'test']);

        $this->actingAs($this->fixture('dean'))->get(route('dean.audit-trail', ['actor_role' => 'Admin']))
            ->assertOk()->assertSee('Admin did something')->assertDontSee('Dean did something');
        $this->actingAs($admin)->get(route('dean.audit-trail'))
            ->assertOk()->assertSee('Dean did something')->assertDontSee('Reset Activity Log</button>', false);
    }
}
