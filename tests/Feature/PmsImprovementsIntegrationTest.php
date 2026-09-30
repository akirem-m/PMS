<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\Payment;
use App\Models\Phase;
use App\Models\PhaseBudget;
use App\Models\Project;
use App\Models\ProjectBudget;
use App\Models\Role;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PmsImprovementsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_office_parent_child_hierarchy_and_cycle_prevention(): void
    {
        $admin = User::where('email', 'admin@pms.test')->firstOrFail();

        $headOffice = Office::create(['office_name' => 'HQ Central Office', 'office_code' => 'HQ01', 'unit_type' => 'Organization']);
        $regionalOffice = Office::create(['office_name' => 'Regional North Office', 'office_code' => 'RNO01', 'unit_type' => 'Regional Office', 'parent_office_id' => $headOffice->office_id]);
        $branchOffice = Office::create(['office_name' => 'Sub Branch Office', 'office_code' => 'SBO01', 'unit_type' => 'Branch Office', 'parent_office_id' => $regionalOffice->office_id]);

        $this->assertEquals($headOffice->office_id, $regionalOffice->fresh()->parent_office_id);
        $this->assertEquals($regionalOffice->office_id, $branchOffice->fresh()->parent_office_id);
        $this->assertTrue($headOffice->children->contains('office_id', $regionalOffice->office_id));

        // Circular reference check
        $this->assertTrue($headOffice->wouldCauseCycle($branchOffice->office_id));
        $this->assertTrue($headOffice->wouldCauseCycle($regionalOffice->office_id));

        // Attempting to set child as parent via controller should be rejected
        $response = $this->actingAs($admin)->put(route('admin.offices.update', $headOffice), [
            'office_name' => $headOffice->office_name,
            'office_code' => $headOffice->office_code,
            'parent_office_id' => $branchOffice->office_id,
        ]);

        $response->assertSessionHasErrors(['parent_office_id']);
    }

    public function test_multiple_users_per_task_and_accept_reject_workflow(): void
    {
        $director = User::where('email', 'director@example.com')->firstOrFail();
        $abebe = User::where('email', 'abebe@example.com')->firstOrFail();
        $john = User::where('email', 'john@example.com')->firstOrFail();
        $chaltu = User::where('email', 'chaltu@example.com')->firstOrFail();

        $task = Task::firstOrFail();

        // Assign multiple users to the task
        $assignResponse = $this->actingAs($director)->postJson(route('tasks.assign-users', $task), [
            'users' => [
                ['user_id' => $abebe->user_id, 'role_label' => 'Backend Lead'],
                ['user_id' => $john->user_id, 'role_label' => 'Frontend Dev'],
                ['user_id' => $chaltu->user_id, 'role_label' => 'UI Designer'],
            ],
        ]);

        $assignResponse->assertOk();
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $task->task_id,
            'user_id' => $abebe->user_id,
            'role_label' => 'Backend Lead',
            'acceptance_status' => 'Pending Acceptance',
        ]);
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $task->task_id,
            'user_id' => $john->user_id,
            'role_label' => 'Frontend Dev',
            'acceptance_status' => 'Pending Acceptance',
        ]);

        // Abebe accepts assignment
        $acceptResponse = $this->actingAs($abebe)->postJson(route('tasks.accept', $task));
        $acceptResponse->assertOk();
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $task->task_id,
            'user_id' => $abebe->user_id,
            'acceptance_status' => 'Accepted',
        ]);

        // Task should now be locked
        $this->assertTrue($task->fresh()->isLocked());

        // John rejects assignment with reason
        $rejectResponse = $this->actingAs($john)->postJson(route('tasks.reject', $task), [
            'rejection_reason' => 'Schedule conflict with release sprint',
        ]);
        $rejectResponse->assertOk();
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $task->task_id,
            'user_id' => $john->user_id,
            'acceptance_status' => 'Rejected',
            'rejection_reason' => 'Schedule conflict with release sprint',
        ]);

        // Abebe's acceptance must not be overwritten by John's rejection
        $this->assertEquals('Accepted', $task->assignments()->where('user_id', $abebe->user_id)->value('acceptance_status'));

        // Removing an assignee
        $removeResponse = $this->actingAs($director)->deleteJson(route('tasks.assignees.remove', ['task' => $task, 'user' => $chaltu]));
        $removeResponse->assertOk();
        $this->assertDatabaseMissing('task_assignments', [
            'task_id' => $task->task_id,
            'user_id' => $chaltu->user_id,
        ]);
    }

    public function test_task_locking_prevents_unauthorized_terms_modification(): void
    {
        $director = User::where('email', 'director@example.com')->firstOrFail();
        $assignee = User::where('email', 'david@example.com')->firstOrFail();

        // Use a task where the assignee is NOT a project manager (David Kim on Create Product API)
        $task = Task::where('task_name', 'Create Product API')->firstOrFail();
        $this->assertEquals($assignee->user_id, $task->assigned_to);

        $task->lock($assignee->user_id, 'Locked upon user agreement');
        $this->assertTrue($task->fresh()->isLocked());

        // Normal assigned user without admin/manager rights tries to alter agreed budget & description
        $forbiddenResponse = $this->actingAs($assignee)->putJson(route('tasks.update', $task), [
            'task_name' => 'Modified Name After Lock',
            'budget' => 999999,
            'description' => 'Tampered terms',
        ]);

        // Modification should be blocked
        $forbiddenResponse->assertStatus(422);

        // Director / Admin can modify with audit log
        $adminResponse = $this->actingAs($director)->putJson(route('tasks.update', $task), [
            'task_name' => 'Corrected Name By Director',
        ]);

        $adminResponse->assertOk();
        $this->assertEquals('Corrected Name By Director', $task->fresh()->task_name);
    }

    public function test_payments_and_cost_management_workflow(): void
    {
        $director = User::where('email', 'director@example.com')->firstOrFail();
        $project = Project::firstOrFail();
        $task = Task::firstOrFail();

        // 1. Record completed payment for a project & task
        $response = $this->actingAs($director)->post(route('payments.store'), [
            'project_id' => $project->project_id,
            'task_id' => $task->task_id,
            'amount' => 45000,
            'payment_date' => now()->toDateString(),
            'recipient' => 'ABC Tech Consultants',
            'payment_status' => 'Completed',
            'reference_number' => 'REF-9921',
            'description' => 'Milestone 1 Deliverable payment',
            'sop_process' => 'Procurement SOP-02',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('payments', [
            'project_id' => $project->project_id,
            'task_id' => $task->task_id,
            'amount' => 45000,
            'recipient' => 'ABC Tech Consultants',
            'payment_status' => 'Completed',
        ]);

        // Task total cost rollup
        $this->assertEquals(45000, $task->fresh()->totalCost());

        // Project total cost rollup
        $this->assertGreaterThanOrEqual(45000, $project->fresh()->calculateTotalCost());
    }

    public function test_global_user_cross_office_assignment(): void
    {
        $director = User::where('email', 'director@example.com')->firstOrFail();
        $hrOffice = Office::where('office_code', 'HR')->firstOrFail();
        $ictOffice = Office::where('office_code', 'ICT')->firstOrFail();

        // Create a user in HR office but with is_global = true
        $auditor = User::create([
            'full_name' => 'Global Compliance Officer',
            'email' => 'compliance@example.com',
            'password_hash' => bcrypt('ChangeMe123!'),
            'status' => 'Active',
            'office_id' => $hrOffice->office_id,
            'is_global' => true,
        ]);

        // ICT Team
        $ictTeam = Team::where('office_id', $ictOffice->office_id)->firstOrFail();

        // Global user can be assigned to ICT project
        $project = Project::where('primary_office_id', $ictOffice->office_id)->firstOrFail();
        $this->assertTrue($project->canAssignUser($auditor));
    }

    public function test_admin_dashboard_shows_user_approval_metrics(): void
    {
        $admin = User::where('email', 'admin@pms.test')->firstOrFail();

        // Create pending and rejected users
        User::create(['full_name' => 'Pending Reg', 'email' => 'pending@test.com', 'password_hash' => bcrypt('test'), 'status' => 'Pending']);
        User::create(['full_name' => 'Rejected Reg', 'email' => 'rejected@test.com', 'password_hash' => bcrypt('test'), 'status' => 'Rejected']);

        $response = $this->actingAs($admin)->get(route('dashboard'));
        $response->assertOk();
        $response->assertSee('User Access &amp; Approvals', false);
        $response->assertSee('Pending Approval', false);
        $response->assertSee('Rejected Users', false);
    }

    public function test_subtask_hierarchy_with_cost_and_assignment(): void
    {
        $director = User::where('email', 'director@example.com')->firstOrFail();
        $abebe = User::where('email', 'abebe@example.com')->firstOrFail();
        $parentTask = Task::firstOrFail();

        // 1. Create a child subtask with its own budget and assignment
        $response = $this->actingAs($director)->postJson(route('tasks.subtasks.store', $parentTask), [
            'task_name' => 'Child API Implementation',
            'budget' => 12500,
            'assigned_to' => $abebe->user_id,
        ]);

        $response->assertStatus(201);
        $subtaskId = $response->json('id');
        $subtask = Task::findOrFail($subtaskId);

        $this->assertEquals($parentTask->task_id, $subtask->parent_task_id);
        $this->assertEquals($abebe->user_id, $subtask->assigned_to);
        $this->assertEquals(12500, (float) $subtask->budget);
        $this->assertTrue($parentTask->fresh()->subtasks->contains('task_id', $subtaskId));

        // 2. Attach a payment directly to the subtask
        Payment::create([
            'project_id' => $parentTask->project_id,
            'task_id' => $subtask->task_id,
            'amount' => 12000,
            'payment_date' => now()->toDateString(),
            'recipient' => 'Subtask Contractor',
            'payment_status' => 'Completed',
        ]);

        // 3. Subtask cost roll-up
        $this->assertEquals(12000, $subtask->fresh()->totalCost());
        // Parent task includes subtask cost in its totalCost()
        $this->assertGreaterThanOrEqual(12000, $parentTask->fresh()->totalCost());
    }

    public function test_complete_end_to_end_lifecycle_integration(): void
    {
        // 1. Organization / Office: Head Office & Child Department
        $admin = User::where('email', 'admin@pms.test')->firstOrFail();
        $headOffice = Office::create([
            'office_name' => 'Jimma University Main Org',
            'office_code' => 'JUMO',
            'unit_type' => 'Organization',
        ]);
        $departmentOffice = Office::create([
            'office_name' => 'Engineering Department',
            'office_code' => 'ENG',
            'unit_type' => 'Department',
            'parent_office_id' => $headOffice->office_id,
        ]);
        $this->assertEquals($headOffice->office_id, $departmentOffice->parent_office_id);

        // 2. User & Approval
        $staffUser = User::create([
            'full_name' => 'Dawit Engineer',
            'email' => 'dawit@test.com',
            'password_hash' => bcrypt('StrongPass123!'),
            'status' => 'Pending',
            'office_id' => $departmentOffice->office_id,
        ]);
        // Admin approves user
        $memberRole = Role::where('role_name', 'Team Member')->firstOrFail();
        $this->actingAs($admin)->post(route('admin.users.approve', $staffUser), [
            'role_id' => $memberRole->role_id,
            'office_id' => $departmentOffice->office_id,
        ]);
        $this->assertEquals('Active', $staffUser->fresh()->status);
        $this->assertEquals($departmentOffice->office_id, $staffUser->fresh()->office_id);

        // 3. Team & Sub-team
        $parentTeam = Team::create([
            'team_name' => 'Core Systems Team',
            'office_id' => $departmentOffice->office_id,
            'status' => 'Active',
        ]);
        $subTeam = Team::create([
            'team_name' => 'Backend Sub-team',
            'office_id' => $departmentOffice->office_id,
            'parent_team_id' => $parentTeam->team_id,
            'status' => 'Active',
        ]);
        $this->assertEquals($parentTeam->team_id, $subTeam->parent_team_id);
        $subTeam->users()->attach($staffUser->user_id);

        // 4. Project linked to office & team
        $director = User::where('email', 'director@example.com')->firstOrFail();
        $project = Project::create([
            'project_name' => 'Student Portal System',
            'project_type' => 'Software',
            'status' => 'Active',
            'primary_office_id' => $departmentOffice->office_id,
            'team_id' => $subTeam->team_id,
            'created_by' => $director->user_id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonths(3)->toDateString(),
        ]);
        ProjectBudget::create([
            'project_id' => $project->project_id,
            'allocated_amount' => 150000,
            'spent_amount' => 0,
        ]);
        $phase = Phase::create([
            'project_id' => $project->project_id,
            'phase_name' => 'Sprint 1',
            'status' => 'In Progress',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addWeeks(2)->toDateString(),
        ]);
        // Task and subtask budgets are capped by the owning phase's budget, so
        // a phase has to be funded before it can carry allocation.
        PhaseBudget::create([
            'phase_id' => $phase->phase_id,
            'allocated_amount' => 150000,
            'spent_amount' => 0,
        ]);

        // 5. Task & Multiple Assignees
        $task = Task::create([
            'project_id' => $project->project_id,
            'phase_id' => $phase->phase_id,
            'team_id' => $subTeam->team_id,
            'task_name' => 'Database Architecture & API',
            'status' => 'To Do',
            'priority' => 'High',
            'budget' => 40000,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
        ]);

        $secondStaff = User::create([
            'full_name' => 'Sara Reviewer',
            'email' => 'reviewer@test.com',
            'password_hash' => bcrypt('StrongPass123!'),
            'status' => 'Active',
            'office_id' => $departmentOffice->office_id,
        ]);
        $secondStaff->roles()->attach($memberRole->role_id);
        $this->actingAs($director)->postJson(route('tasks.assign-users', $task), [
            'users' => [
                ['user_id' => $staffUser->user_id, 'role_label' => 'Lead Architect'],
                ['user_id' => $secondStaff->user_id, 'role_label' => 'Code Reviewer'],
            ],
        ]);
        $this->assertCount(2, $task->fresh()->assignments);

        // 6. Subtask
        $subtaskResponse = $this->actingAs($director)->postJson(route('tasks.subtasks.store', $task), [
            'task_name' => 'Design Schema & Migrations',
            'budget' => 10000,
            'assigned_to' => $staffUser->user_id,
        ]);
        $subtaskResponse->assertStatus(201);
        $subtaskId = $subtaskResponse->json('id');
        $subtask = Task::findOrFail($subtaskId);
        $this->assertEquals($task->task_id, $subtask->parent_task_id);

        // 7. Accept assignment & Lock
        $staffUser->refresh();
        $this->actingAs($staffUser)->postJson(route('tasks.accept', $task))->assertOk();
        $this->assertTrue($task->fresh()->isLocked());

        // 8. Progress & Completion
        $this->actingAs($staffUser)->postJson(route('tasks.status', $task), ['status' => 'Completed'])->assertOk();
        $this->assertEquals('Completed', $task->fresh()->status);

        // 9. Payment for task & subtask
        $this->actingAs($director)->post(route('payments.store'), [
            'project_id' => $project->project_id,
            'task_id' => $task->task_id,
            'amount' => 30000,
            'payment_date' => now()->toDateString(),
            'recipient' => 'Dawit Engineer',
            'payment_status' => 'Completed',
            'reference_number' => 'REF-E2E-001',
        ])->assertSessionHasNoErrors();

        $this->actingAs($director)->post(route('payments.store'), [
            'project_id' => $project->project_id,
            'task_id' => $subtask->task_id,
            'amount' => 10000,
            'payment_date' => now()->toDateString(),
            'recipient' => 'Subcontractor Inc',
            'payment_status' => 'Completed',
            'reference_number' => 'REF-E2E-002',
        ])->assertSessionHasNoErrors();

        // 10. Cost Rollup & Report Verification
        $this->assertEquals(40000, $project->fresh()->calculateTotalCost());
        $this->assertGreaterThanOrEqual(40000, $task->fresh()->totalCost());

        // Report visibility is office-scoped, and this project belongs to the
        // newly created Engineering Department rather than the seeded
        // director's office, so the org-wide view is the administrator's.
        $reportRes = $this->actingAs($admin)->get(route('reports.index'));
        $reportRes->assertOk();
        $reportRes->assertSee('Student Portal System');
    }
}
