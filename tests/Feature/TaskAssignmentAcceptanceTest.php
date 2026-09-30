<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Office;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the task status/acceptance contract:
 *
 *  - the task_user pivot decision gates the status control,
 *  - accepted members can drive To Do -> In Progress -> In Review -> Completed
 *    even after the agreed terms are locked,
 *  - rejecting notifies the assigner and records the reason,
 *  - subtask mutations require participation on the task.
 */
class TaskAssignmentAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $assignee;

    private User $outsider;

    private Project $project;

    private Team $team;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $office = Office::create([
            'office_name' => 'ICT Office',
            'office_code' => 'ICT',
            'status' => 'Active',
        ]);

        $this->manager = $this->userWithRole('Project Manager', 'manager@example.com', $office);
        $this->assignee = $this->userWithRole('Team Member', 'assignee@example.com', $office);
        $this->outsider = $this->userWithRole('Team Member', 'outsider@example.com', $office);

        $this->team = Team::create([
            'team_name' => 'Delivery Team',
            'team_leader_id' => $this->manager->user_id,
            'office_id' => $office->office_id,
            'status' => 'Active',
        ]);
        $this->team->users()->attach([$this->assignee->user_id, $this->manager->user_id]);
        $this->team->users()->attach($this->outsider->user_id);

        $this->project = Project::create([
            'project_name' => 'Acceptance Project',
            'project_type' => 'Software',
            'project_manager_id' => $this->manager->user_id,
            'created_by' => $this->manager->user_id,
            'team_id' => $this->team->team_id,
            'primary_office_id' => $office->office_id,
            'status' => 'active',
        ]);

        $phase = Phase::create([
            'project_id' => $this->project->project_id,
            'phase_name' => 'Execution',
            'status' => 'In Progress',
            'sequence_order' => 1,
        ]);

        $this->task = Task::create([
            'project_id' => $this->project->project_id,
            'phase_id' => $phase->phase_id,
            'team_id' => $this->team->team_id,
            'task_name' => 'Build the acceptance flow',
            'assigned_to' => $this->assignee->user_id,
            'status' => 'To Do',
            'priority' => 'Medium',
            'budget' => 0,
        ]);

        TaskAssignment::create([
            'task_id' => $this->task->task_id,
            'user_id' => $this->assignee->user_id,
            'role_label' => 'Primary Assignee',
            'acceptance_status' => TaskAssignment::STATUS_PENDING,
            'assigned_by' => $this->manager->user_id,
            'assigned_at' => now(),
        ]);
    }

    public function test_pending_assignment_blocks_status_changes(): void
    {
        $response = $this->actingAs($this->assignee)->postJson(route('tasks.status', $this->task), [
            'status' => 'In Progress',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Accept or reject your task assignment before changing its status.');

        $this->assertDatabaseHas('tasks', [
            'task_id' => $this->task->task_id,
            'status' => 'To Do',
        ]);
    }

    public function test_drawer_payload_gates_the_banner_on_the_pivot_status(): void
    {
        $pending = $this->actingAs($this->assignee)->getJson(route('tasks.show', $this->task))->json();

        $this->assertSame('pending', $pending['my_assignment']['status']);
        $this->assertSame('Pending Acceptance', $pending['assignees'][0]['acceptance_status']);
        $this->assertSame('pending', $pending['assignees'][0]['status_slug']);
        $this->assertTrue($pending['acceptance_blocked']);
        $this->assertFalse($pending['can_update_status']);

        $this->actingAs($this->assignee)
            ->postJson(route('tasks.accept', $this->task))
            ->assertOk()
            ->assertJsonPath('my_assignment.status', 'accepted')
            ->assertJsonPath('assignees.0.acceptance_status', 'Accepted');

        $accepted = $this->actingAs($this->assignee)->getJson(route('tasks.show', $this->task))->json();

        $this->assertSame('accepted', $accepted['my_assignment']['status']);
        $this->assertFalse($accepted['my_assignment']['can_respond']);
        $this->assertFalse($accepted['acceptance_blocked']);
        $this->assertTrue($accepted['can_update_status']);
    }

    public function test_accepted_member_can_move_through_the_four_delivery_statuses_while_locked(): void
    {
        $this->actingAs($this->assignee)->postJson(route('tasks.accept', $this->task))->assertOk();

        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $this->task->task_id,
            'user_id' => $this->assignee->user_id,
            'acceptance_status' => TaskAssignment::STATUS_ACCEPTED,
        ]);

        foreach (['In Progress', 'In Review', 'Completed', 'To Do'] as $status) {
            $this->actingAs($this->assignee)
                ->postJson(route('tasks.status', $this->task), ['status' => $status])
                ->assertOk()
                ->assertJsonPath('status', $status);

            $this->assertDatabaseHas('tasks', [
                'task_id' => $this->task->task_id,
                'status' => $status,
            ]);
        }
    }

    public function test_assigned_member_cannot_park_the_task_as_blocked(): void
    {
        $this->actingAs($this->assignee)->postJson(route('tasks.accept', $this->task))->assertOk();

        $this->actingAs($this->assignee)
            ->postJson(route('tasks.status', $this->task), ['status' => 'Blocked'])
            ->assertStatus(422);
    }

    public function test_rejection_requires_a_reason_and_notifies_the_assigner(): void
    {
        $this->actingAs($this->assignee)
            ->postJson(route('tasks.reject', $this->task), ['rejection_reason' => ''])
            ->assertStatus(422);

        $this->actingAs($this->assignee)
            ->postJson(route('tasks.reject', $this->task), [
                'rejection_reason' => 'Schedule conflict with the audit deadline.',
            ])
            ->assertOk()
            ->assertJsonPath('my_assignment.status', 'rejected')
            ->assertJsonPath('assignees.0.status_slug', 'rejected');

        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $this->task->task_id,
            'user_id' => $this->assignee->user_id,
            'acceptance_status' => TaskAssignment::STATUS_REJECTED,
            'rejection_reason' => 'Schedule conflict with the audit deadline.',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->manager->user_id,
        ]);

        $notification = Notification::where('user_id', $this->manager->user_id)->first();
        $this->assertStringContainsString('Schedule conflict with the audit deadline.', $notification->message);

        // Nobody is left engaged, so the task is flagged blocked for follow-up.
        $this->assertDatabaseHas('tasks', [
            'task_id' => $this->task->task_id,
            'status' => 'Blocked',
        ]);
    }

    public function test_subtask_mutations_require_task_participation(): void
    {
        $this->actingAs($this->outsider)
            ->postJson(route('tasks.subtasks.store', $this->task), ['task_name' => 'Sneaky subtask'])
            ->assertForbidden();

        $this->actingAs($this->manager)
            ->postJson(route('tasks.subtasks.store', $this->task), ['task_name' => 'Checklist item'])
            ->assertCreated();

        $subtask = Task::where('task_name', 'Checklist item')->firstOrFail();

        $this->actingAs($this->outsider)
            ->postJson(route('tasks.subtasks.toggle', $subtask))
            ->assertForbidden();

        $this->actingAs($this->outsider)
            ->deleteJson(route('tasks.subtasks.destroy', $subtask))
            ->assertForbidden();

        $this->actingAs($this->manager)
            ->postJson(route('tasks.subtasks.toggle', $subtask))
            ->assertOk()
            ->assertJsonPath('is_completed', true);

        $this->actingAs($this->manager)
            ->deleteJson(route('tasks.subtasks.destroy', $subtask))
            ->assertOk();

        $this->assertDatabaseMissing('tasks', ['task_id' => $subtask->task_id]);
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
