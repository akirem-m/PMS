<?php

namespace Tests\Feature;

use App\Models\Phase;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskProgressLog;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectAndTaskTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The seeder creates one demo project per project type, so the sample
     * project these tests describe has to be looked up by name rather than
     * assumed to be the first row.
     */
    private function sampleProject(): Project
    {
        return Project::where('project_name', 'Sample PMS')->firstOrFail();
    }

    /** A team that belongs to the given user's own office. */
    private function officeTeam(User $user): Team
    {
        return Team::where('office_id', $user->office_id)->firstOrFail();
    }

    public function test_seeder_creates_and_updates_project_and_task(): void
    {
        $this->seed(DatabaseSeeder::class);

        $project = $this->sampleProject();
        $this->assertEquals('active', $project->status);

        // Look the task up by name: the seeder also creates one demo project
        // per project type, so Task::first() is not this sample's task.
        $task = Task::where('project_id', $project->project_id)
            ->where('task_name', 'Requirements Gathering')
            ->firstOrFail();
        $this->assertEquals('In Progress', $task->status);
    }

    public function test_director_can_create_and_update_project(): void
    {
        $this->seed(DatabaseSeeder::class);

        $director = User::where('email', 'director@example.com')->first();
        $team = $this->officeTeam($director);

        $response = $this->actingAs($director)->post(route('projects.store'), [
            'project_name' => 'New Client Portal',
            'description' => 'A new portal for staff',
            'project_type' => 'Software',
            'primary_office_id' => $director->office_id,
            'team_id' => $team->team_id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonths(3)->toDateString(),
            'allocated_amount' => 200000,
        ]);

        $project = Project::where('project_name', 'New Client Portal')->first();
        $this->assertNotNull($project);
        $response->assertRedirect(route('projects.show', $project));

        $updateResponse = $this->actingAs($director)->put(route('projects.update', $project), [
            'project_name' => 'New Client Portal Updated',
            'description' => 'Updated portal description',
            'project_type' => 'Software',
            'team_id' => $team->team_id,
            'status' => 'active',
            'allocated_amount' => 250000,
        ]);

        $updateResponse->assertRedirect(route('projects.show', $project));
        $this->assertDatabaseHas('projects', [
            'project_id' => $project->project_id,
            'project_name' => 'New Client Portal Updated',
            'status' => 'active',
        ]);
    }

    public function test_can_create_and_update_task(): void
    {
        $this->seed(DatabaseSeeder::class);

        $director = User::where('email', 'director@example.com')->first();
        $project = $this->sampleProject();
        $phase = $project->phases()->firstOrFail();

        $startDate = now()->toDateString();
        $endDate = now()->addDays(10)->toDateString();

        $response = $this->actingAs($director)->post(route('tasks.store'), [
            'phase_id' => $phase->phase_id,
            'task_name' => 'Architecture Design',
            'priority' => 'High',
            'status' => 'In Progress',
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        $response->assertSessionHasNoErrors();
        $task = Task::where('task_name', 'Architecture Design')->first();
        $this->assertNotNull($task);
        $this->assertEquals('In Progress', $task->status);
        $this->assertEquals($startDate, $task->start_date->toDateString());
        $this->assertEquals($endDate, $task->end_date->toDateString());

        $updateResponse = $this->actingAs($director)->put(route('tasks.update', $task), [
            'task_name' => 'Architecture Design (Final)',
            'priority' => 'Medium',
            'status' => 'Done',
        ]);

        $updateResponse->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tasks', [
            'task_id' => $task->task_id,
            'task_name' => 'Architecture Design (Final)',
            'status' => 'Done',
        ]);
    }

    public function test_can_delete_task(): void
    {
        $this->seed(DatabaseSeeder::class);

        $director = User::where('email', 'director@example.com')->first();
        $task = Task::first();
        $this->assertNotNull($task);

        // Add a comment and progress log to ensure cascade deletion
        TaskComment::create([
            'task_id' => $task->task_id,
            'user_id' => $director->user_id,
            'comment_text' => 'Test comment before delete',
        ]);

        TaskProgressLog::create([
            'task_id' => $task->task_id,
            'user_id' => $director->user_id,
            'previous_status' => 'Pending',
            'new_status' => 'In Progress',
        ]);

        $response = $this->actingAs($director)->deleteJson(route('tasks.destroy', $task));
        $response->assertOk();
        $response->assertJson(['message' => 'Task deleted successfully']);

        $this->assertDatabaseMissing('tasks', [
            'task_id' => $task->task_id,
        ]);
        $this->assertDatabaseMissing('task_comments', [
            'task_id' => $task->task_id,
        ]);
        $this->assertDatabaseMissing('task_progress_logs', [
            'task_id' => $task->task_id,
        ]);
    }

    public function test_task_is_rendered_in_kanban_and_list_view_after_creation(): void
    {
        $this->seed(DatabaseSeeder::class);

        $director = User::where('email', 'director@example.com')->first();
        $project = $this->sampleProject();
        $phase = $project->phases()->firstOrFail();

        $createResponse = $this->actingAs($director)->post(route('tasks.store'), [
            'phase_id' => $phase->phase_id,
            'task_name' => 'Verify Kanban and List View Task',
            'priority' => 'High',
            'status' => 'Pending',
            'assigned_to' => $director->user_id,
        ]);

        $createResponse->assertSessionHas('status', 'Task added successfully.');

        $showResponse = $this->actingAs($director)->get(route('projects.show', $project));
        $showResponse->assertOk();
        $showResponse->assertSee('Verify Kanban and List View Task');

        $tasksResponse = $this->actingAs($director)->get(route('tasks.index'));
        $tasksResponse->assertOk();
        $tasksResponse->assertSee('Verify Kanban and List View Task');
    }

    public function test_flash_messages_render_with_close_button_after_project_create_and_delete(): void
    {
        $this->seed(DatabaseSeeder::class);

        $director = User::where('email', 'director@example.com')->first();
        $team = $this->officeTeam($director);

        // 1. Create project
        $createResponse = $this->actingAs($director)->post(route('projects.store'), [
            'project_name' => 'Flash Test Project',
            'description' => 'Test project for flash alerts',
            'project_type' => 'Software',
            'primary_office_id' => $director->office_id,
            'team_id' => $team->team_id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonths(2)->toDateString(),
            'allocated_amount' => 100000,
        ]);

        $project = Project::where('project_name', 'Flash Test Project')->first();
        $this->assertNotNull($project);
        $createResponse->assertRedirect(route('projects.show', $project));

        $followCreate = $this->followRedirects($createResponse);
        $followCreate->assertOk();
        $followCreate->assertSee('Project created.');
        $followCreate->assertSee('alert-close');

        // 2. Delete project
        $deleteResponse = $this->actingAs($director)->delete(route('projects.destroy', $project));
        $deleteResponse->assertRedirect(route('projects.index'));

        $followDelete = $this->followRedirects($deleteResponse);
        $followDelete->assertOk();
        $followDelete->assertSee('was deleted.');
        $followDelete->assertSee('alert-close');
    }

    public function test_can_update_phase_status_and_add_phase(): void
    {
        $this->seed(DatabaseSeeder::class);

        $director = User::where('email', 'director@example.com')->first();
        $project = $this->sampleProject();
        $phase = $project->phases()->firstOrFail();

        // Update phase status
        $statusResponse = $this->actingAs($director)->post(route('phases.status', $phase), [
            'status' => 'Done',
        ]);
        $statusResponse->assertSessionHasNoErrors();
        $this->assertEquals('Done', $phase->fresh()->status);

        // Add a new phase
        $addResponse = $this->actingAs($director)->post(route('phases.store', $project), [
            'phase_name' => 'Deployment & UAT',
            'status' => 'Not started',
        ]);
        $addResponse->assertSessionHasNoErrors();
        $this->assertDatabaseHas('phases', [
            'project_id' => $project->project_id,
            'phase_name' => 'Deployment & UAT',
            'status' => 'Not started',
        ]);
    }

    public function test_can_update_task_phase(): void
    {
        $this->seed(DatabaseSeeder::class);

        $director = User::where('email', 'director@example.com')->first();
        $project = $this->sampleProject();
        $phase1 = $project->phases()->orderBy('sequence_order')->firstOrFail();
        $phase2 = $project->phases()->where('phase_id', '!=', $phase1->phase_id)->firstOrFail();

        $task = Task::where('project_id', $project->project_id)->firstOrFail();
        $task->update(['phase_id' => $phase1->phase_id]);

        $updateResponse = $this->actingAs($director)->put(route('tasks.update', $task), [
            'phase_id' => $phase2->phase_id,
        ]);
        $updateResponse->assertSessionHasNoErrors();
        $this->assertEquals($phase2->phase_id, $task->fresh()->phase_id);
    }

    public function test_can_update_project_and_phase_budgets_and_view_integrated_teams_and_budgets(): void
    {
        $this->seed(DatabaseSeeder::class);

        $director = User::where('email', 'director@example.com')->first();
        $project = $this->sampleProject();
        $team = $project->team;
        $phase = $project->phases()->firstOrFail();

        // 1. Update Project Budget
        $projectBudgetResponse = $this->actingAs($director)->post(route('budgets.projects.update', $project), [
            'allocated_amount' => 500000,
            'spent_amount' => 150000,
        ]);
        $projectBudgetResponse->assertSessionHasNoErrors();
        $this->assertEquals(500000, $project->fresh()->budget->allocated_amount);
        $this->assertEquals(150000, $project->fresh()->budget->spent_amount);

        // 2. Update Phase Budget
        $phaseBudgetResponse = $this->actingAs($director)->post(route('budgets.phases.update', $phase), [
            'allocated_amount' => 100000,
            'spent_amount' => 30000,
        ]);
        $phaseBudgetResponse->assertSessionHasNoErrors();
        $this->assertEquals(100000, $phase->fresh()->budget->allocated_amount);
        $this->assertEquals(30000, $phase->fresh()->budget->spent_amount);

        // 3. View Budgets page
        $budgetPageResponse = $this->actingAs($director)->get(route('budgets.index'));
        $budgetPageResponse->assertOk();
        $budgetPageResponse->assertSee($project->project_name);
        $budgetPageResponse->assertSee($team->team_name);

        // 4. View Teams page
        $teamPageResponse = $this->actingAs($director)->get(route('teams.show', $team));
        $teamPageResponse->assertOk();
        $teamPageResponse->assertSee($project->project_name);
        $teamPageResponse->assertSee('Team Tasks Progress');
    }

    public function test_add_comment_returns_comment_id_and_details(): void
    {
        $this->seed(DatabaseSeeder::class);

        $director = User::where('email', 'director@example.com')->first();
        $task = Task::first();

        $response = $this->actingAs($director)->postJson(route('tasks.comments', $task), [
            'comment_text' => 'New test comment text',
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'id',
            'user',
            'text',
            'at',
        ]);
        $this->assertEquals('New test comment text', $response->json('text'));
    }

    public function test_update_task_via_json_with_empty_assignee_and_dates(): void
    {
        $this->seed(DatabaseSeeder::class);

        $director = User::where('email', 'director@example.com')->first();
        $task = Task::first();

        $response = $this->actingAs($director)->putJson(route('tasks.update', $task), [
            'task_name' => 'Updated via JSON Panel',
            'status' => 'In Progress',
            'priority' => 'High',
            'phase_id' => $task->phase_id,
            'assigned_to' => null,
            'start_date' => null,
            'end_date' => null,
            'description' => 'Updated description',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('tasks', [
            'task_id' => $task->task_id,
            'task_name' => 'Updated via JSON Panel',
            'assigned_to' => null,
        ]);
    }

    public function test_can_create_project_with_pm_team_leader_and_flexible_member_roles(): void
    {
        $this->seed(DatabaseSeeder::class);

        $director = User::where('email', 'director@example.com')->first();
        $abebe = User::where('email', 'abebe@example.com')->first();
        $chaltu = User::where('email', 'chaltu@example.com')->first();
        $caala = User::where('email', 'caala@example.com')->first();
        $john = User::where('email', 'john@example.com')->first();
        $team = Team::where('team_name', 'Software Engineering')->firstOrFail();

        $response = $this->actingAs($director)->post(route('projects.store'), [
            'project_name' => 'Enterprise Resource Planning System',
            'description' => 'Comprehensive company ERP system',
            'project_type' => 'Software',
            'primary_office_id' => $director->office_id,
            'team_id' => $team->team_id,
            'project_manager_id' => $abebe->user_id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonths(12)->toDateString(),
            'allocated_amount' => 750000,
            'members' => [
                ['user_id' => $chaltu->user_id, 'specialty' => 'UI/UX Designer'],
                ['user_id' => $caala->user_id, 'specialty' => 'Backend Developer'],
                ['user_id' => $john->user_id, 'specialty' => 'QA / Test Engineer'],
            ],
        ]);

        $project = Project::where('project_name', 'Enterprise Resource Planning System')->first();
        $this->assertNotNull($project);
        $this->assertEquals($abebe->user_id, $project->project_manager_id);
        $response->assertRedirect(route('projects.show', $project));

        $this->assertDatabaseHas('project_member_roles', [
            'project_id' => $project->project_id,
            'user_id' => $chaltu->user_id,
            'specialty' => 'UI/UX Designer',
        ]);
        $this->assertDatabaseHas('project_member_roles', [
            'project_id' => $project->project_id,
            'user_id' => $caala->user_id,
            'specialty' => 'Backend Developer',
        ]);
        $this->assertDatabaseHas('project_member_roles', [
            'project_id' => $project->project_id,
            'user_id' => $john->user_id,
            'specialty' => 'QA / Test Engineer',
        ]);

        $roster = $project->getProjectRoster();
        $this->assertTrue($roster->contains('full_name', 'Abebe Bikila'));
        $this->assertTrue($roster->contains('specialty', 'UI/UX Designer'));
        $this->assertTrue($roster->contains('specialty', 'Backend Developer'));
        $this->assertTrue($roster->contains('specialty', 'QA / Test Engineer'));
    }

    public function test_project_manager_can_manage_project(): void
    {
        $this->seed(DatabaseSeeder::class);

        // Sample PMS is managed by its seeded project manager of record.
        $pm = User::where('email', 'john.smith@example.com')->firstOrFail();
        $project = $this->sampleProject();

        $this->assertTrue($project->isManagedBy($pm));

        $phase = $project->phases()->firstOrFail();
        $response = $this->actingAs($pm)->post(route('tasks.store'), [
            'phase_id' => $phase->phase_id,
            'task_name' => 'PM Created Task',
            'priority' => 'High',
            'status' => 'Pending',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tasks', [
            'task_name' => 'PM Created Task',
            'phase_id' => $phase->phase_id,
        ]);
    }

    public function test_can_dynamically_add_update_and_remove_project_member_roles(): void
    {
        $this->seed(DatabaseSeeder::class);

        $director = User::where('email', 'director@example.com')->first();
        $project = Project::where('project_name', 'Sample PMS')->first();

        // 1. Add new member
        $addResponse = $this->actingAs($director)->post(route('projects.members.add', $project), [
            'user_id' => $director->user_id,
            'specialty' => 'Technical Advisor',
        ]);
        $addResponse->assertSessionHasNoErrors();

        $memberRole = $project->memberRoles()->where('user_id', $director->user_id)->first();
        $this->assertNotNull($memberRole);
        $this->assertEquals('Technical Advisor', $memberRole->specialty);

        // 2. Update member role
        $updateResponse = $this->actingAs($director)->put(route('projects.members.update', [$project, $memberRole]), [
            'specialty' => 'Lead Strategic Advisor',
        ]);
        $updateResponse->assertSessionHasNoErrors();
        $this->assertEquals('Lead Strategic Advisor', $memberRole->fresh()->specialty);

        // 3. Remove member role
        $removeResponse = $this->actingAs($director)->delete(route('projects.members.remove', [$project, $memberRole]));
        $removeResponse->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('project_member_roles', [
            'id' => $memberRole->id,
        ]);
    }

    public function test_can_assign_task_by_typing_any_custom_member_name_not_in_selection(): void
    {
        $this->seed(DatabaseSeeder::class);

        $director = User::where('email', 'director@example.com')->first();
        $project = $this->sampleProject();
        $phase = $project->phases()->firstOrFail();

        // Assign to a new person "Dawit Bekele" who was not originally in the database
        $response = $this->actingAs($director)->post(route('tasks.store'), [
            'phase_id' => $phase->phase_id,
            'task_name' => 'Custom Assignee Task',
            'assigned_to' => 'Dawit Bekele',
            'priority' => 'High',
            'status' => 'Pending',
        ]);

        $response->assertSessionHasNoErrors();

        // The user Dawit Bekele should be automatically created and assigned
        $createdUser = User::where('full_name', 'Dawit Bekele')->first();
        $this->assertNotNull($createdUser);
        $this->assertEquals('Active', $createdUser->status);

        $task = Task::where('task_name', 'Custom Assignee Task')->first();
        $this->assertNotNull($task);
        $this->assertEquals($createdUser->user_id, $task->assigned_to);
    }

    public function test_can_assign_project_manager_by_typing_any_custom_name(): void
    {
        $this->seed(DatabaseSeeder::class);

        $director = User::where('email', 'director@example.com')->first();
        $team = $this->officeTeam($director);

        // Create project typing new PM name "Hana Girma"
        $response = $this->actingAs($director)->post(route('projects.store'), [
            'project_name' => 'Custom PM Project',
            'description' => 'Project with custom PM name',
            'project_type' => 'Software',
            'primary_office_id' => $director->office_id,
            'team_id' => $team->team_id,
            'project_manager_id' => 'Hana Girma',
        ]);

        $response->assertSessionHasNoErrors();

        $createdPm = User::where('full_name', 'Hana Girma')->first();
        $this->assertNotNull($createdPm);

        $project = Project::where('project_name', 'Custom PM Project')->first();
        $this->assertNotNull($project);
        $this->assertEquals($createdPm->user_id, $project->project_manager_id);
    }

    public function test_can_reassign_task_using_typed_member_name_via_api(): void
    {
        $this->seed(DatabaseSeeder::class);

        $director = User::where('email', 'director@example.com')->first();
        $task = Task::first();

        $response = $this->actingAs($director)->postJson(route('tasks.assign', $task), [
            'assigned_to' => 'Tariku Mengistu',
        ]);

        $response->assertOk();

        $tariku = User::where('full_name', 'Tariku Mengistu')->first();
        $this->assertNotNull($tariku);
        $this->assertEquals($tariku->user_id, $task->fresh()->assigned_to);
    }
}
