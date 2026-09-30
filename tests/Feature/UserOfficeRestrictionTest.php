<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserOfficeRestrictionTest extends TestCase
{
    use RefreshDatabase;

    private User $director;

    private Office $ict;

    private Office $finance;

    private Office $hr;

    private User $ictUser;

    private User $financeUser;

    private User $hrUser;

    private Team $ictTeam;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->director = User::where('email', 'director@example.com')->firstOrFail();

        $this->ict = Office::create(['office_name' => 'Test ICT Office', 'office_code' => 'TICT', 'is_active' => true]);
        $this->finance = Office::create(['office_name' => 'Test Finance Office', 'office_code' => 'TFIN', 'is_active' => true]);
        $this->hr = Office::create(['office_name' => 'Test HR Office', 'office_code' => 'THR', 'is_active' => true]);

        $this->ictUser = User::create(['full_name' => 'Test ICT User', 'email' => 'test-ict@example.com', 'password_hash' => bcrypt('ChangeMe123!'), 'status' => 'Active', 'office_id' => $this->ict->office_id]);
        $this->financeUser = User::create(['full_name' => 'Test Finance User', 'email' => 'test-finance@example.com', 'password_hash' => bcrypt('ChangeMe123!'), 'status' => 'Active', 'office_id' => $this->finance->office_id]);
        $this->hrUser = User::create(['full_name' => 'Test HR User', 'email' => 'test-hr@example.com', 'password_hash' => bcrypt('ChangeMe123!'), 'status' => 'Active', 'office_id' => $this->hr->office_id]);

        $this->ictTeam = Team::create(['team_name' => 'Test ICT Team', 'office_id' => $this->ict->office_id, 'status' => 'Active']);

        $this->project = Project::create([
            'project_name' => 'User Office Restriction Test Project',
            'project_type' => 'Software',
            'team_id' => $this->ictTeam->team_id,
            'primary_office_id' => $this->ict->office_id,
            'status' => 'planning',
            'created_by' => $this->director->user_id,
        ]);
        $this->project->offices()->attach($this->ict->office_id, ['participation_type' => 'primary']);
    }

    public function test_same_office_user_assignment_works(): void
    {
        $this->actingAs($this->director)
            ->post(route('projects.members.add', $this->project), ['user_id' => $this->ictUser->user_id])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('project_member_roles', [
            'project_id' => $this->project->project_id,
            'user_id' => $this->ictUser->user_id,
        ]);
    }

    public function test_participating_office_user_assignment_works(): void
    {
        $this->project->offices()->syncWithoutDetaching([
            $this->finance->office_id => ['participation_type' => 'participating'],
        ]);

        $this->actingAs($this->director)
            ->post(route('projects.members.add', $this->project), ['user_id' => $this->financeUser->user_id])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('project_member_roles', [
            'project_id' => $this->project->project_id,
            'user_id' => $this->financeUser->user_id,
        ]);
    }

    public function test_unrelated_office_user_assignment_is_rejected(): void
    {
        $this->actingAs($this->director)
            ->post(route('projects.members.add', $this->project), ['user_id' => $this->hrUser->user_id])
            ->assertSessionHasErrors(['user_id']);

        $this->assertDatabaseMissing('project_member_roles', [
            'project_id' => $this->project->project_id,
            'user_id' => $this->hrUser->user_id,
        ]);
    }

    public function test_manipulated_user_id_on_project_store_is_rejected(): void
    {
        $this->actingAs($this->director)
            ->post(route('projects.store'), [
                'project_name' => 'Should Not Exist',
                'project_type' => 'Software',
                'primary_office_id' => $this->ict->office_id,
                'team_id' => $this->ictTeam->team_id,
                'members' => [
                    ['user_id' => $this->hrUser->user_id, 'specialty' => 'QA'],
                ],
            ])
            ->assertSessionHasErrors();

        $this->assertDatabaseMissing('projects', ['project_name' => 'Should Not Exist']);
    }

    public function test_task_assignment_of_unrelated_office_user_is_rejected(): void
    {
        $this->actingAs($this->director)
            ->post(route('tasks.store'), [
                'project_id' => $this->project->project_id,
                'team_id' => $this->ictTeam->team_id,
                'task_name' => 'Unauthorized Assignee Task',
                'assigned_to' => $this->hrUser->user_id,
                'priority' => 'High',
                'status' => 'To Do',
            ]);

        $this->assertDatabaseMissing('tasks', ['task_name' => 'Unauthorized Assignee Task']);
    }

    public function test_system_administrator_can_still_assign_without_office(): void
    {
        // The seeded director is bound to their own office (unlike the
        // office-less System Administrator, who may freely pick one), so the
        // project, team and member all have to sit in that office for RBAC and
        // the office boundary to both be satisfied.
        $officeId = (int) $this->director->office_id;
        $team = Team::create([
            'team_name' => 'Director Office Team',
            'office_id' => $officeId,
            'status' => 'Active',
        ]);
        $member = User::create([
            'full_name' => 'Director Office Member',
            'email' => 'director-office-member@example.com',
            'password_hash' => bcrypt('ChangeMe123!'),
            'status' => 'Active',
            'office_id' => $officeId,
        ]);

        $this->actingAs($this->director)
            ->post(route('projects.store'), [
                'project_name' => 'Admin Assign Project',
                'project_type' => 'Software',
                'primary_office_id' => $officeId,
                'team_id' => $team->team_id,
                'members' => [
                    ['user_id' => $member->user_id, 'specialty' => 'Backend Developer'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $project = Project::where('project_name', 'Admin Assign Project')->first();
        $this->assertNotNull($project);
        $this->assertDatabaseHas('project_member_roles', [
            'project_id' => $project->project_id,
            'user_id' => $member->user_id,
        ]);

        Task::query()->where('project_id', $project->project_id)->delete();
    }
}
