<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SelfRegistrationOfficeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Office $ict;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@pms.test')->firstOrFail();
        $this->ict = Office::create(['office_name' => 'Reg ICT Office', 'office_code' => 'RICT', 'is_active' => true]);
    }

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Self Registered User',
            'email' => 'self-reg@example.com',
            'phone' => '0911000000',
            'password' => 'Str0ng!Pass',
            'password_confirmation' => 'Str0ng!Pass',
        ], $overrides);
    }

    public function test_self_registration_creates_pending_user_without_office(): void
    {
        $this->post(route('register'), $this->registrationPayload())
            ->assertRedirect(route('guest.pending'));

        $user = User::where('email', 'self-reg@example.com')->firstOrFail();

        $this->assertSame('Pending', $user->status);
        $this->assertNull($user->office_id);
        $this->assertSame('guest', $user->role);
        $this->assertFalse($user->roles()->exists());
        $this->assertFalse($user->hasPermission('view_projects'));
    }

    public function test_submitted_office_id_cannot_assign_an_office(): void
    {
        $this->post(route('register'), $this->registrationPayload([
            'office_id' => $this->ict->office_id,
        ]));

        // The forged office_id is rejected server-side, so no account exists.
        $this->assertDatabaseMissing('users', ['email' => 'self-reg@example.com']);
    }

    public function test_administrator_assigns_office_and_approves(): void
    {
        $this->post(route('register'), $this->registrationPayload());
        $user = User::where('email', 'self-reg@example.com')->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.users.approve', $user), [
                'role_id' => Role::where('role_name', 'Team Member')->firstOrFail()->role_id,
                'office_id' => $this->ict->office_id,
            ])
            ->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertSame('Active', $user->status);
        $this->assertSame($this->ict->office_id, $user->office_id);
        $this->assertTrue($user->hasPermission('view_projects'));
    }

    public function test_approved_user_without_office_has_no_office_scoped_access(): void
    {
        $this->post(route('register'), $this->registrationPayload());
        $user = User::where('email', 'self-reg@example.com')->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.users.approve', $user), [
                'role_id' => Role::where('role_name', 'Team Member')->firstOrFail()->role_id,
            ])
            ->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertSame('Active', $user->status);
        $this->assertNull($user->office_id);
        $this->assertTrue($user->hasPermission('view_projects'));
    }
}
