<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Notification;
use App\Models\PasswordResetRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordResetRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $user = User::create([
            'role_id' => Role::idFor(Role::ADMIN), 'username' => 'admin1', 'name' => 'Ada Admin',
            'password' => Hash::make('password123'), 'status' => 'Active', 'must_change_password' => false,
        ]);
        Employee::create(['user_id' => $user->id, 'employee_no' => 'SITE-GEN-ADMIN001', 'full_name' => 'Ada Admin', 'position' => 'Admin']);

        return $user->fresh();
    }

    private function user(string $username): User
    {
        return User::where('username', $username)->firstOrFail();
    }

    private function requestReset(string $username)
    {
        return $this->post(route('password.forgot.submit'), ['username' => $username]);
    }

    public function test_dean_cannot_request_reset_when_no_admin_exists(): void
    {
        $this->requestReset('dean')->assertSessionHasErrors('username');
        $this->assertSame(0, PasswordResetRequest::count());
    }

    public function test_dean_request_goes_only_to_admin_and_requires_a_reason(): void
    {
        $admin = $this->makeAdmin();
        $dean = $this->user('dean');

        $this->requestReset('dean')->assertSessionHas('success');
        $request = PasswordResetRequest::where('user_id', $dean->id)->firstOrFail();
        $this->assertTrue(Notification::where('user_id', $admin->id)->exists());
        $this->assertFalse(Notification::where('user_id', $this->user('secretary')->id)->exists());

        // The Dean cannot see or approve their own request; the Secretary cannot handle it either.
        $this->actingAs($dean)->get(route('password-reset-requests.index'))->assertOk()->assertDontSee('>dean<', false);
        $this->actingAs($dean)->post(route('password-reset-requests.approve', $request->password_reset_request_id))->assertForbidden();
        $this->actingAs($this->user('secretary'))->post(route('password-reset-requests.approve', $request->password_reset_request_id))->assertForbidden();

        $this->actingAs($admin)->post(route('password-reset-requests.approve', $request->password_reset_request_id))
            ->assertSessionHasErrors('error');
        $this->assertSame(PasswordResetRequest::STATUS_PENDING, $request->fresh()->status);

        $this->actingAs($admin)->post(route('password-reset-requests.approve', $request->password_reset_request_id), ['reason' => 'Dean asked in person'])
            ->assertSessionHas('temp_password');
        $request->refresh();
        $this->assertSame(PasswordResetRequest::STATUS_APPROVED, $request->status);
        $this->assertSame('Dean asked in person', $request->reason);
        $this->assertTrue($dean->fresh()->must_change_password);
        $this->assertTrue(Hash::check(session('temp_password'), $dean->fresh()->password));
    }

    public function test_admin_request_is_handled_only_by_the_dean(): void
    {
        $admin = $this->makeAdmin();
        $this->requestReset('admin1')->assertSessionHas('success');
        $request = PasswordResetRequest::where('user_id', $admin->id)->firstOrFail();

        $this->actingAs($admin)->post(route('password-reset-requests.approve', $request->password_reset_request_id))->assertForbidden();
        $this->actingAs($this->user('secretary'))->post(route('password-reset-requests.deny', $request->password_reset_request_id))->assertForbidden();
        $this->actingAs($this->user('dean'))->post(route('password-reset-requests.approve', $request->password_reset_request_id))
            ->assertSessionHas('temp_password');
        $this->assertTrue($admin->fresh()->must_change_password);
    }

    public function test_faculty_request_can_be_approved_by_admin_and_lists_for_dean(): void
    {
        $admin = $this->makeAdmin();
        $faculty = $this->user('faculty');
        $this->requestReset('faculty')->assertSessionHas('success');
        $request = PasswordResetRequest::where('user_id', $faculty->id)->firstOrFail();

        foreach ([$admin, $this->user('dean'), $this->user('secretary')] as $handler) {
            $this->assertTrue(Notification::where('user_id', $handler->id)->exists());
        }
        $this->actingAs($this->user('dean'))->get(route('password-reset-requests.index'))->assertOk()->assertSee('faculty');

        $this->actingAs($admin)->post(route('password-reset-requests.approve', $request->password_reset_request_id))
            ->assertSessionHas('temp_password');
        $this->assertTrue($faculty->fresh()->must_change_password);
        $this->assertStringContainsString('Admin', Notification::where('user_id', $faculty->id)->latest('id')->value('message'));
    }
}
