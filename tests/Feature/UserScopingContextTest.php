<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\Project;
use App\Models\Role;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserScopingContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_manager_member_selection_is_limited_to_project_office_and_shared_users(): void
    {
        $this->seed(RbacSeeder::class);

        [$officeA, $officeB] = $this->offices();
        $manager = $this->user('manager@example.com', $officeA);
        $officeMember = $this->user('office-a@example.com', $officeA);
        $otherOfficeMember = $this->user('office-b@example.com', $officeB);
        $sharedUser = $this->user('shared@example.com');
        $manager->roles()->attach(Role::where('role_name', 'Project Manager')->value('role_id'));

        $team = Team::create(['team_name' => 'Office A Team', 'office_id' => $officeA->office_id, 'status' => 'Active']);
        TeamMember::create(['team_id' => $team->team_id, 'user_id' => $officeMember->user_id, 'joined_date' => now()]);
        $project = Project::create([
            'project_name' => 'Office A Project',
            'project_type' => 'Software',
            'team_id' => $team->team_id,
            'project_manager_id' => $manager->user_id,
            'primary_office_id' => $officeA->office_id,
            'created_by' => $manager->user_id,
            'status' => 'planning',
        ]);

        $assignable = collect($project->getAssignableUsersWithRoles($manager))->pluck('raw_name');

        $this->assertTrue($assignable->contains($manager->full_name));
        $this->assertTrue($assignable->contains($officeMember->full_name));
        $this->assertTrue($assignable->contains($sharedUser->full_name));
        $this->assertFalse($assignable->contains($otherOfficeMember->full_name));

        $this->actingAs($manager)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee($officeMember->full_name)
            ->assertSee($sharedUser->full_name)
            ->assertDontSee($otherOfficeMember->full_name);
    }

    public function test_pending_approval_count_is_filtered_by_selected_office(): void
    {
        $this->seed(RbacSeeder::class);

        [$officeA, $officeB] = $this->offices();
        $admin = $this->user('admin@example.com');
        $admin->roles()->attach(Role::where('role_name', 'Administrator')->value('role_id'));
        User::create(['full_name' => 'Pending A 1', 'email' => 'pending-a1@example.com', 'password_hash' => bcrypt('x'), 'status' => 'Pending', 'office_id' => $officeA->office_id]);
        User::create(['full_name' => 'Pending A 2', 'email' => 'pending-a2@example.com', 'password_hash' => bcrypt('x'), 'status' => 'Pending', 'office_id' => $officeA->office_id]);
        User::create(['full_name' => 'Pending B', 'email' => 'pending-b@example.com', 'password_hash' => bcrypt('x'), 'status' => 'Pending', 'office_id' => $officeB->office_id]);

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['office_id' => $officeA->office_id]))
            ->assertOk()
            ->assertSee('2 pending approvals');

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['office_id' => $officeB->office_id]))
            ->assertOk()
            ->assertSee('1 pending approval');
    }

    public function test_non_admin_context_headers_follow_project_and_team_resources(): void
    {
        $this->seed(RbacSeeder::class);

        [$office] = $this->offices();
        $manager = $this->user('context-manager@example.com', $office);
        $manager->roles()->attach(Role::where('role_name', 'Project Manager')->value('role_id'));
        $team = Team::create(['team_name' => 'Context Team', 'office_id' => $office->office_id, 'status' => 'Active']);
        $project = Project::create([
            'project_name' => 'Context Project',
            'project_type' => 'Software',
            'team_id' => $team->team_id,
            'project_manager_id' => $manager->user_id,
            'primary_office_id' => $office->office_id,
            'created_by' => $manager->user_id,
            'status' => 'planning',
        ]);
        $project->teams()->attach($team->team_id);

        $this->actingAs($manager)
            ->get(route('projects.show', $project))
            ->assertSee('context-header')
            ->assertSee($office->office_name)
            ->assertSee($project->project_name);

        $this->actingAs($manager)
            ->get(route('teams.show', $team))
            ->assertSee('context-header')
            ->assertSee($office->office_name)
            ->assertSee($project->project_name)
            ->assertSee($team->team_name);

        $administrator = $this->user('context-admin@example.com');
        $administrator->roles()->attach(Role::where('role_name', 'Administrator')->value('role_id'));

        $this->actingAs($administrator)
            ->get(route('admin.users.index'))
            ->assertDontSee('context-header');
    }

    private function offices(): array
    {
        return [
            Office::create(['office_name' => 'Office A', 'office_code' => 'OFF-A', 'status' => 'Active']),
            Office::create(['office_name' => 'Office B', 'office_code' => 'OFF-B', 'status' => 'Active']),
        ];
    }

    private function user(string $email, ?Office $office = null): User
    {
        return User::create([
            'full_name' => explode('@', $email)[0],
            'email' => $email,
            'password_hash' => bcrypt('password'),
            'status' => 'Active',
            'office_id' => $office?->office_id,
        ]);
    }
}
