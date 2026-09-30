<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\Project;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Services\OrgHierarchyService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression cover for the "/teams/{id}/edit returns You don't have access"
 * bug: TeamPolicy was never registered with the Gate, so `can:update,team`
 * denied every non-admin. Authorized leaders must get through.
 */
class TeamEditAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Office $office;

    private Team $team;

    private User $officeHead;

    private User $teamLead;

    private User $projectManager;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->office = Office::create([
            'office_name' => 'ICT Office',
            'office_code' => 'ICT',
            'status' => 'Active',
        ]);
        $otherOffice = Office::create([
            'office_name' => 'Finance Office',
            'office_code' => 'FIN',
            'status' => 'Active',
        ]);

        $this->officeHead = $this->userWithRole('Team Member', 'office-head@example.com', $this->office);
        $this->teamLead = $this->userWithRole('Team Lead', 'team-lead@example.com', $this->office);
        $this->projectManager = $this->userWithRole('Project Manager', 'pm@example.com', $this->office);
        $this->colleague = $this->userWithRole('Team Member', 'colleague@example.com', $this->office);
        $otherHead = $this->userWithRole('Team Member', 'other-head@example.com', $otherOffice);

        $hierarchy = app(OrgHierarchyService::class);
        $hierarchy->assignHead($this->officeHead, $this->office);
        $hierarchy->assignHead($otherHead, $otherOffice);

        $this->team = Team::create([
            'team_name' => 'Platform Team',
            'team_leader_id' => $this->teamLead->user_id,
            'office_id' => $this->office->office_id,
            'status' => 'Active',
        ]);
        $this->team->users()->attach([$this->teamLead->user_id, $this->colleague->user_id]);

        Project::create([
            'project_name' => 'Platform Project',
            'project_type' => 'Software',
            'project_manager_id' => $this->projectManager->user_id,
            'created_by' => $this->projectManager->user_id,
            'team_id' => $this->team->team_id,
            'primary_office_id' => $this->office->office_id,
            'status' => 'active',
        ]);
    }

    public function test_office_head_can_open_the_team_edit_page(): void
    {
        $this->actingAs($this->officeHead)
            ->get(route('teams.edit', $this->team))
            ->assertOk();
    }

    public function test_team_lead_can_open_the_team_edit_page(): void
    {
        $this->actingAs($this->teamLead)
            ->get(route('teams.edit', $this->team))
            ->assertOk();
    }

    public function test_project_manager_can_open_the_team_edit_page(): void
    {
        $this->actingAs($this->projectManager)
            ->get(route('teams.edit', $this->team))
            ->assertOk();
    }

    public function test_unrelated_colleague_is_denied_team_editing(): void
    {
        $this->actingAs($this->colleague)
            ->get(route('teams.edit', $this->team))
            ->assertForbidden();
    }

    public function test_head_of_another_office_is_denied_team_editing(): void
    {
        $otherHead = User::where('email', 'other-head@example.com')->firstOrFail();

        $this->actingAs($otherHead)
            ->get(route('teams.edit', $this->team))
            ->assertForbidden();
    }

    public function test_office_head_can_update_the_team(): void
    {
        $this->actingAs($this->officeHead)
            ->put(route('teams.update', $this->team), ['team_name' => 'Platform Team Renamed'])
            ->assertRedirect();

        $this->assertDatabaseHas('teams', [
            'team_id' => $this->team->team_id,
            'team_name' => 'Platform Team Renamed',
        ]);
    }

    private function userWithRole(string $roleName, string $email, Office $office): User
    {
        $user = User::create([
            'full_name' => str_replace(['.', '@example.com'], [' ', ''], $email),
            'email' => $email,
            'password_hash' => bcrypt('password'),
            'status' => 'Active',
            'office_id' => $office->office_id,
        ]);

        $user->roles()->attach(Role::where('role_name', $roleName)->value('role_id'));

        return $user;
    }
}
