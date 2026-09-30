<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectManagementFullWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_sample_ecommerce_project_and_teams_seeded_correctly(): void
    {
        $this->seed(DatabaseSeeder::class);

        $ecom = Project::where('project_name', 'E-Commerce Website')->first();
        $this->assertNotNull($ecom);
        $this->assertEquals('John Smith', $ecom->projectManager->full_name);
        $this->assertEquals('Global Retail Corporation', $ecom->client);

        // Verify all 3 teams exist and are assigned
        $uiux = Team::where('team_name', 'UI/UX Team')->first();
        $frontend = Team::where('team_name', 'Frontend Team')->first();
        $backend = Team::where('team_name', 'Backend Team')->first();

        $this->assertNotNull($uiux);
        $this->assertNotNull($frontend);
        $this->assertNotNull($backend);

        $assignedTeamIds = $ecom->allTeams()->pluck('team_id')->toArray();
        $this->assertContains($uiux->team_id, $assignedTeamIds);
        $this->assertContains($frontend->team_id, $assignedTeamIds);
        $this->assertContains($backend->team_id, $assignedTeamIds);

        // Verify tasks per team
        $uiuxTasks = Task::where('project_id', $ecom->project_id)->where('team_id', $uiux->team_id)->get();
        $this->assertTrue($uiuxTasks->contains('task_name', 'Homepage Wireframe'));
        $this->assertTrue($uiuxTasks->contains('task_name', 'Product Page Design'));
        $this->assertTrue($uiuxTasks->contains('task_name', 'Checkout Design'));

        $feTasks = Task::where('project_id', $ecom->project_id)->where('team_id', $frontend->team_id)->get();
        $this->assertTrue($feTasks->contains('task_name', 'Build Homepage'));
        $this->assertTrue($feTasks->contains('task_name', 'Build Product Listing'));
        $this->assertTrue($feTasks->contains('task_name', 'Build Shopping Cart'));

        $beTasks = Task::where('project_id', $ecom->project_id)->where('team_id', $backend->team_id)->get();
        $this->assertTrue($beTasks->contains('task_name', 'Create Authentication API'));
        $this->assertTrue($beTasks->contains('task_name', 'Create Product API'));
        $this->assertTrue($beTasks->contains('task_name', 'Create Order API'));
        $this->assertTrue($beTasks->contains('task_name', 'Create Payment API'));
    }

    public function test_multi_step_project_creation_workflow_with_teams_and_tasks(): void
    {
        $this->seed(DatabaseSeeder::class);

        $pm = User::where('email', 'john.smith@example.com')->first();
        $uiux = Team::where('team_name', 'UI/UX Team')->first();
        $frontend = Team::where('team_name', 'Frontend Team')->first();
        $sarah = User::where('email', 'sarah@example.com')->first();

        $response = $this->actingAs($pm)->post(route('projects.store'), [
            'project_name' => 'Mobile Banking App',
            'description' => 'Native mobile banking application',
            'client' => 'Commercial Bank',
            'priority' => 'High',
            'project_type' => 'Software',
            'primary_office_id' => $pm->office_id,
            'project_manager_id' => $pm->user_id,
            'teams' => [$uiux->team_id, $frontend->team_id],
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonths(4)->toDateString(),
            'allocated_amount' => 450000,
            'tasks' => [
                [
                    'task_name' => 'Design Login Screens',
                    'team_id' => $uiux->team_id,
                    'priority' => 'High',
                    'status' => 'To Do',
                ],
                [
                    'task_name' => 'Implement Biometric Auth',
                    'team_id' => $frontend->team_id,
                    'assigned_to' => $sarah->user_id,
                    'priority' => 'High',
                    'status' => 'To Do',
                ],
            ],
        ]);

        $project = Project::where('project_name', 'Mobile Banking App')->first();
        $this->assertNotNull($project);
        $response->assertRedirect(route('projects.show', $project));

        $this->assertCount(2, $project->allTeams());
        $this->assertCount(2, $project->allTasks());
    }

    public function test_project_wizard_saves_each_step_incrementally(): void
    {
        $this->seed(DatabaseSeeder::class);

        $pm = User::where('email', 'john.smith@example.com')->first();
        $team = Team::where('team_name', 'Frontend Team')->first();

        $stepOne = $this->actingAs($pm)->postJson(route('projects.wizard.save'), [
            'step' => 1,
            'project_name' => 'Incremental Wizard Project',
            'project_type' => 'Software',
            'primary_office_id' => $pm->office_id,
            'project_manager_id' => $pm->user_id,
            'priority' => 'High',
            'allocated_amount' => 100000,
        ]);
        $stepOne->assertOk()->assertJsonStructure(['project_id']);
        $projectId = $stepOne->json('project_id');

        $this->assertDatabaseHas('projects', ['project_id' => $projectId, 'project_name' => 'Incremental Wizard Project']);

        $this->actingAs($pm)->postJson(route('projects.wizard.save'), [
            'step' => 2,
            'project_id' => $projectId,
            'teams' => [$team->team_id],
        ])->assertOk();

        $this->assertDatabaseHas('project_teams', ['project_id' => $projectId, 'team_id' => $team->team_id]);

        $this->actingAs($pm)->postJson(route('projects.wizard.save'), [
            'step' => 3,
            'project_id' => $projectId,
            'tasks' => [['task_name' => 'Build dashboard', 'team_id' => $team->team_id, 'priority' => 'High']],
        ])->assertOk();

        $this->assertDatabaseHas('tasks', ['project_id' => $projectId, 'task_name' => 'Build dashboard']);
    }

    public function test_task_status_change_automatically_recalculates_project_progress(): void
    {
        $this->seed(DatabaseSeeder::class);

        $pm = User::where('email', 'john.smith@example.com')->first();
        $team = Team::first();

        $project = Project::create([
            'project_name' => 'Progress Auto Calculation Test',
            'project_type' => 'Software',
            'team_id' => $team->team_id,
            'project_manager_id' => $pm->user_id,
            'created_by' => $pm->user_id,
            'status' => 'active',
            'progress' => 0,
        ]);

        $phase = $project->phases()->create([
            'phase_name' => 'Execution',
            'status' => 'In Progress',
            'sequence_order' => 1,
        ]);

        $task1 = Task::create([
            'project_id' => $project->project_id,
            'phase_id' => $phase->phase_id,
            'team_id' => $team->team_id,
            'task_name' => 'Task One',
            'priority' => 'High',
            'status' => 'To Do',
        ]);

        $task2 = Task::create([
            'project_id' => $project->project_id,
            'phase_id' => $phase->phase_id,
            'team_id' => $team->team_id,
            'task_name' => 'Task Two',
            'priority' => 'Medium',
            'status' => 'To Do',
        ]);

        $this->assertEquals(0, $project->fresh()->progressPercentage());

        // Update task1 to Completed
        $this->actingAs($pm)->postJson(route('tasks.status', $task1), [
            'status' => 'Completed',
        ]);

        $this->assertEquals(50, $project->fresh()->progress);

        // Update task2 to Completed
        $this->actingAs($pm)->postJson(route('tasks.status', $task2), [
            'status' => 'Completed',
        ]);

        $this->assertEquals(100, $project->fresh()->progress);
    }

    public function test_can_create_subtask_and_toggle_status(): void
    {
        $this->seed(DatabaseSeeder::class);

        $pm = User::where('email', 'john.smith@example.com')->first();
        $task = Task::first();

        // 1. Create subtask
        $createResponse = $this->actingAs($pm)->postJson(route('tasks.subtasks.store', $task), [
            'task_name' => 'Subtask 1: Database Migration',
        ]);
        $createResponse->assertStatus(201);
        $subtaskId = $createResponse->json('id');
        $this->assertNotNull($subtaskId);

        $subtask = Task::find($subtaskId);
        $this->assertEquals($task->task_id, $subtask->parent_task_id);
        $this->assertEquals('To Do', $subtask->status);

        // 2. Toggle subtask to Completed
        $toggleResponse = $this->actingAs($pm)->postJson(route('tasks.subtasks.toggle', $subtask));
        $toggleResponse->assertOk();
        $this->assertEquals('Completed', $toggleResponse->json('status'));
        $this->assertTrue($toggleResponse->json('is_completed'));
    }

    public function test_can_upload_and_download_task_attachment(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('public');

        $pm = User::where('email', 'john.smith@example.com')->first();
        $task = Task::first();

        $file = UploadedFile::fake()->create('project-specs.pdf', 1024, 'application/pdf');

        $uploadResponse = $this->actingAs($pm)->post(route('tasks.attachments.upload', $task), [
            'file' => $file,
        ]);
        $uploadResponse->assertStatus(201);
        $attachmentId = $uploadResponse->json('attachment.id');
        $this->assertNotNull($attachmentId);

        $attachment = Attachment::find($attachmentId);
        $this->assertNotNull($attachment);
        Storage::disk('public')->assertExists($attachment->file_path);

        $downloadResponse = $this->actingAs($pm)->get(route('attachments.download', $attachment));
        $downloadResponse->assertOk();
    }

    public function test_reports_and_calendar_views_accessible(): void
    {
        $this->seed(DatabaseSeeder::class);

        $pm = User::where('email', 'john.smith@example.com')->first();

        // 1. Reports
        $reportResponse = $this->actingAs($pm)->get(route('reports.index'));
        $reportResponse->assertOk();
        $reportResponse->assertSee('Reports');
        $reportResponse->assertSee('E-Commerce Website');

        // 2. Calendar
        $calendarResponse = $this->actingAs($pm)->get(route('calendar.index'));
        $calendarResponse->assertOk();
        $calendarResponse->assertSee('Project &amp; Task Calendar', false);
    }

    public function test_can_dynamically_assign_and_remove_teams_on_project(): void
    {
        $this->seed(DatabaseSeeder::class);

        $pm = User::where('email', 'john.smith@example.com')->first();
        $project = Project::where('project_name', 'E-Commerce Website')->first();
        $infraTeam = Team::where('team_name', 'Network & Infrastructure')->first();

        // Assign team
        $assignResponse = $this->actingAs($pm)->post(route('projects.teams.assign', $project), [
            'team_id' => $infraTeam->team_id,
        ]);
        $assignResponse->assertSessionHasNoErrors();
        $this->assertTrue($project->fresh()->allTeams()->contains('team_id', $infraTeam->team_id));

        // Remove team
        $removeResponse = $this->actingAs($pm)->delete(route('projects.teams.remove', [$project, $infraTeam]));
        $removeResponse->assertSessionHasNoErrors();
        $this->assertFalse($project->fresh()->teams()->where('teams.team_id', $infraTeam->team_id)->exists());
    }

    public function test_project_create_wizard_page_renders_without_errors(): void
    {
        $this->seed(DatabaseSeeder::class);

        $pm = User::where('email', 'john.smith@example.com')->first();
        $response = $this->actingAs($pm)->get(route('projects.create'));

        $response->assertOk();
        $response->assertSee('Create New Project');
        $response->assertSee('Step 1 — Project Information');
        $response->assertSee('Step 2 — Assign Teams');
        $response->assertSee('Step 3 — Create Tasks');
        $response->assertSee('Step 4 — Review');
    }

    public function test_task_budget_and_assignee_can_be_created_and_updated(): void
    {
        $this->seed(DatabaseSeeder::class);

        $pm = User::where('email', 'john.smith@example.com')->first();
        $project = Project::where('project_name', 'E-Commerce Website')->first();
        $frontend = Team::where('team_name', 'Frontend Team')->first();
        $david = User::where('email', 'david@example.com')->first();
        $sarah = User::where('email', 'sarah@example.com')->first();

        // 1. Create task with budget and assignee
        $createResponse = $this->actingAs($pm)->post(route('tasks.store'), [
            'project_id' => $project->project_id,
            'team_id' => $frontend->team_id,
            'task_name' => 'SEO Optimization & Core Web Vitals',
            'assigned_to' => $david->user_id,
            'priority' => 'High',
            'status' => 'To Do',
            'budget' => 35000,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(20)->toDateString(),
        ]);

        $createResponse->assertSessionHasNoErrors();

        $task = Task::where('task_name', 'SEO Optimization & Core Web Vitals')->first();
        $this->assertNotNull($task);
        $this->assertEquals(35000, (float) $task->budget);
        $this->assertEquals($david->user_id, $task->assigned_to);

        // 2. Update task budget and reassign to Sarah
        $updateResponse = $this->actingAs($pm)->put(route('tasks.update', $task), [
            'task_name' => 'SEO Optimization & Core Web Vitals (Updated)',
            'assigned_to' => $sarah->user_id,
            'budget' => 42000,
            'priority' => 'Urgent',
            'status' => 'In Progress',
        ]);

        $updateResponse->assertSessionHasNoErrors();

        $freshTask = $task->fresh();
        $this->assertEquals(42000, (float) $freshTask->budget);
        $this->assertEquals($sarah->user_id, $freshTask->assigned_to);
        $this->assertEquals('In Progress', $freshTask->status);
        $this->assertEquals('Urgent', $freshTask->priority);

        // 3. Verify task show JSON response contains budget and assignee
        $jsonResponse = $this->actingAs($pm)->getJson(route('tasks.show', $freshTask));
        $jsonResponse->assertOk();
        $jsonResponse->assertJson([
            'id' => $freshTask->task_id,
            'name' => 'SEO Optimization & Core Web Vitals (Updated)',
            'budget' => 42000,
            'assignee_id' => $sarah->user_id,
            'assignee' => 'Sarah Connor',
        ]);
    }

    public function test_can_add_custom_new_member_not_in_selection_to_team_and_project(): void
    {
        $this->seed(DatabaseSeeder::class);

        $pm = User::where('email', 'john.smith@example.com')->first();
        $uiux = Team::where('team_name', 'UI/UX Team')->first();
        $project = Project::where('project_name', 'E-Commerce Website')->first();

        // 1. Add completely new person "Elena Rostova" to Team by typing name
        $teamAddResponse = $this->actingAs($pm)->post(route('teams.members.add', $uiux), [
            'user_name' => 'Elena Rostova',
        ]);
        $teamAddResponse->assertSessionHasNoErrors();

        $elena = User::where('full_name', 'Elena Rostova')->first();
        $this->assertNotNull($elena);
        $this->assertTrue($uiux->fresh()->members->contains('user_id', $elena->user_id));

        // 2. Add new person "Marcus Vance" to Project Roster with custom specialty
        $projectAddResponse = $this->actingAs($pm)->post(route('projects.members.add', $project), [
            'user_name' => 'Marcus Vance',
            'specialty' => 'Security Specialist',
        ]);
        $projectAddResponse->assertSessionHasNoErrors();

        $marcus = User::where('full_name', 'Marcus Vance')->first();
        $this->assertNotNull($marcus);
        $this->assertTrue($project->fresh()->memberRoles->contains('user_id', $marcus->user_id));

        // 3. Assign task directly to typed new name "Sophia Chen"
        $taskCreateResponse = $this->actingAs($pm)->post(route('tasks.store'), [
            'project_id' => $project->project_id,
            'team_id' => $uiux->team_id,
            'task_name' => 'Design System Tokens',
            'assigned_to' => 'Sophia Chen',
            'priority' => 'High',
            'status' => 'To Do',
            'budget' => 15000,
        ]);
        $taskCreateResponse->assertSessionHasNoErrors();

        $sophia = User::where('full_name', 'Sophia Chen')->first();
        $this->assertNotNull($sophia);
        $task = Task::where('task_name', 'Design System Tokens')->first();
        $this->assertNotNull($task);
        $this->assertEquals($sophia->user_id, $task->assigned_to);
    }

    public function test_can_reassign_task_with_reason_and_audit_remarks(): void
    {
        $this->seed(DatabaseSeeder::class);

        $pm = User::where('email', 'john.smith@example.com')->first();
        $task = Task::where('task_name', 'Create Authentication API')->first();
        $daniel = User::where('email', 'daniel@example.com')->first();

        $response = $this->actingAs($pm)->postJson(route('tasks.assign', $task), [
            'assigned_to' => $daniel->user_id,
            'reason' => 'Daniel taking over OAuth2 scope',
        ]);

        $response->assertOk();
        $this->assertEquals($daniel->user_id, $task->fresh()->assigned_to);

        $latestLog = $task->fresh()->progressLogs()->first();
        $this->assertNotNull($latestLog);
        $this->assertStringContainsString('Daniel taking over OAuth2 scope', $latestLog->remarks);
    }

    public function test_can_report_and_resolve_blocker_on_task(): void
    {
        $this->seed(DatabaseSeeder::class);

        $pm = User::where('email', 'john.smith@example.com')->first();
        $task = Task::where('task_name', 'Create Product API')->first();

        // 1. Report Blocker
        $blockResponse = $this->actingAs($pm)->postJson(route('tasks.status', $task), [
            'status' => 'Blocked',
            'blocker_reason' => 'External inventory service endpoint is down',
        ]);

        $blockResponse->assertOk();
        $this->assertEquals('Blocked', $task->fresh()->status);
        $this->assertEquals('External inventory service endpoint is down', $task->fresh()->blocker_reason);

        // 2. Resolve Blocker
        $resolveResponse = $this->actingAs($pm)->postJson(route('tasks.status', $task), [
            'status' => 'In Progress',
        ]);

        $resolveResponse->assertOk();
        $this->assertEquals('In Progress', $task->fresh()->status);
        $this->assertNull($task->fresh()->blocker_reason);
    }

    public function test_can_manage_scope_deliverables(): void
    {
        $this->seed(DatabaseSeeder::class);

        $pm = User::where('email', 'john.smith@example.com')->first();
        $project = Project::where('project_name', 'E-Commerce Website')->first();

        // 1. Add Deliverable
        $addResponse = $this->actingAs($pm)->post(route('projects.deliverables.store', $project), [
            'deliverable_name' => 'Payment Gateway UAT Sign-off',
            'description' => 'Formal acceptance testing document for payment gateways',
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $addResponse->assertSessionHasNoErrors();

        $deliverable = $project->fresh()->deliverables()->where('deliverable_name', 'Payment Gateway UAT Sign-off')->first();
        $this->assertNotNull($deliverable);
        $this->assertEquals('Pending', $deliverable->status);

        // 2. Toggle status to Delivered
        $toggleResponse = $this->actingAs($pm)->post(route('projects.deliverables.toggle', [$project, $deliverable]));
        $toggleResponse->assertSessionHasNoErrors();
        $this->assertEquals('Delivered', $deliverable->fresh()->status);

        // 3. Delete deliverable
        $deleteResponse = $this->actingAs($pm)->delete(route('projects.deliverables.destroy', [$project, $deliverable]));
        $deleteResponse->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('project_deliverables', ['deliverable_id' => $deliverable->deliverable_id]);
    }

    public function test_can_create_task_directly_under_project_without_phase(): void
    {
        $this->seed(DatabaseSeeder::class);

        $pm = User::where('email', 'john.smith@example.com')->first();
        $project = Project::where('project_name', 'E-Commerce Website')->first();
        $backend = Team::where('team_name', 'Backend Team')->first();

        $response = $this->actingAs($pm)->post(route('tasks.store'), [
            'project_id' => $project->project_id,
            'team_id' => $backend->team_id,
            'task_name' => 'Direct Project Task Without Phase',
            'priority' => 'Medium',
            'status' => 'To Do',
            'budget' => 20000,
        ]);

        $response->assertSessionHasNoErrors();

        $task = Task::where('task_name', 'Direct Project Task Without Phase')->first();
        $this->assertNotNull($task);
        $this->assertEquals($project->project_id, $task->project_id);
        $this->assertEquals(20000, (float) $task->budget);
    }
}
