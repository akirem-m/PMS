<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Office;
use App\Models\Payment;
use App\Models\Phase;
use App\Models\PhaseBudget;
use App\Models\Project;
use App\Models\ProjectBudget;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\Team;
use App\Models\User;
use App\Services\OrgHierarchyService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Expenditure lifecycle: contributor submits -> Team Lead / PM approves ->
 * Head of Office / Finance confirms disbursement, with the phase funds guard
 * and the scoped /payments listing.
 */
class PaymentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $contributor;

    private User $officeHead;

    private User $otherOfficeHead;

    private Project $project;

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
        $otherOffice = Office::create([
            'office_name' => 'Finance Office',
            'office_code' => 'FIN',
            'status' => 'Active',
        ]);

        $this->manager = $this->userWithRole('Project Manager', 'pm@example.com', $office);
        $this->contributor = $this->userWithRole('Team Member', 'worker@example.com', $office);
        $this->officeHead = $this->userWithRole('Team Member', 'head@example.com', $office);
        $this->otherOfficeHead = $this->userWithRole('Team Member', 'other-head@example.com', $otherOffice);

        $hierarchy = app(OrgHierarchyService::class);
        $hierarchy->assignHead($this->officeHead, $office);
        $hierarchy->assignHead($this->otherOfficeHead, $otherOffice);

        $team = Team::create([
            'team_name' => 'Expense Team',
            'team_leader_id' => $this->manager->user_id,
            'office_id' => $office->office_id,
            'status' => 'Active',
        ]);
        $team->users()->attach([$this->contributor->user_id, $this->manager->user_id]);

        $this->project = Project::create([
            'project_name' => 'Funded Project',
            'project_type' => 'Software',
            'project_manager_id' => $this->manager->user_id,
            'created_by' => $this->manager->user_id,
            'team_id' => $team->team_id,
            'primary_office_id' => $office->office_id,
            'status' => 'active',
        ]);

        $phase = Phase::create([
            'project_id' => $this->project->project_id,
            'phase_name' => 'Execution',
            'status' => 'In Progress',
            'sequence_order' => 1,
        ]);

        PhaseBudget::create([
            'phase_id' => $phase->phase_id,
            'allocated_amount' => 50000,
            'spent_amount' => 0,
        ]);

        $this->task = Task::create([
            'project_id' => $this->project->project_id,
            'phase_id' => $phase->phase_id,
            'team_id' => $team->team_id,
            'task_name' => 'Field installation',
            'assigned_to' => $this->contributor->user_id,
            'status' => 'In Progress',
            'priority' => 'Medium',
            'budget' => 0,
        ]);

        TaskAssignment::create([
            'task_id' => $this->task->task_id,
            'user_id' => $this->contributor->user_id,
            'role_label' => 'Primary Assignee',
            'acceptance_status' => TaskAssignment::STATUS_ACCEPTED,
            'assigned_by' => $this->manager->user_id,
            'assigned_at' => now(),
            'responded_at' => now(),
        ]);
    }

    public function test_contributor_submission_enters_the_pending_approval_queue(): void
    {
        $this->actingAs($this->contributor)
            ->post(route('payments.store'), [
                'task_id' => $this->task->task_id,
                'amount' => 1200,
                'payment_date' => now()->toDateString(),
                'recipient' => 'Cable vendor',
                'description' => 'Network cabling materials',
            ])
            ->assertRedirect();

        $payment = Payment::firstOrFail();

        $this->assertSame(Payment::STATUS_PENDING, $payment->payment_status);
        $this->assertSame($this->contributor->user_id, (int) $payment->created_by);
        $this->assertSame($this->task->task_id, (int) $payment->task_id);
        $this->assertSame($this->project->project_id, (int) $payment->project_id);

        // A contributor may not record money as already paid out.
        $this->assertDatabaseHas('notifications', ['user_id' => $this->manager->user_id]);
    }

    public function test_contributor_cannot_self_approve_or_disburse(): void
    {
        $payment = $this->submitExpense(1200);

        $this->actingAs($this->contributor)
            ->post(route('payments.approve', $payment))
            ->assertForbidden();

        $this->actingAs($this->contributor)
            ->post(route('payments.disburse', $payment))
            ->assertForbidden();
    }

    public function test_manager_approves_then_office_head_disburses(): void
    {
        $payment = $this->submitExpense(1200);

        $this->actingAs($this->manager)
            ->post(route('payments.approve', $payment))
            ->assertRedirect();

        $payment->refresh();
        $this->assertSame(Payment::STATUS_APPROVED, $payment->payment_status);
        $this->assertSame($this->manager->user_id, (int) $payment->approved_by);
        $this->assertNotNull($payment->approved_at);

        // Disbursement is reserved for the owning office's head / Finance.
        $this->actingAs($this->otherOfficeHead)
            ->post(route('payments.disburse', $payment))
            ->assertForbidden();

        $this->actingAs($this->officeHead)
            ->post(route('payments.disburse', $payment))
            ->assertRedirect();

        $payment->refresh();
        $this->assertSame(Payment::STATUS_COMPLETED, $payment->payment_status);
        $this->assertSame($this->officeHead->user_id, (int) $payment->disbursed_by);
        $this->assertNotNull($payment->disbursed_at);

        // Budget rollups now reflect the completed spend.
        $this->assertSame(1200.0, (float) PhaseBudget::where('phase_id', $payment->phase_id)->value('spent_amount'));
        $this->assertSame(1200.0, (float) ProjectBudget::where('project_id', $payment->project_id)->value('spent_amount'));
    }

    public function test_expenditure_beyond_the_phase_funds_is_rejected(): void
    {
        $this->actingAs($this->contributor)
            ->post(route('payments.store'), [
                'task_id' => $this->task->task_id,
                'amount' => 75000,
                'payment_date' => now()->toDateString(),
                'recipient' => 'Overpriced vendor',
            ])
            ->assertStatus(422);

        $this->assertSame(0, Payment::count());
    }

    public function test_contributor_cannot_log_against_a_task_they_are_not_assigned(): void
    {
        $stranger = $this->userWithRole('Team Member', 'stranger@example.com', Office::first());

        $this->actingAs($stranger)
            ->post(route('payments.store'), [
                'task_id' => $this->task->task_id,
                'amount' => 100,
                'payment_date' => now()->toDateString(),
                'recipient' => 'Somebody',
            ])
            ->assertForbidden();
    }

    public function test_payments_page_is_reachable_for_contributors_and_scoped_to_their_work(): void
    {
        $mine = $this->submitExpense(900, 'My cabling expense');

        $mine->update(['payment_status' => Payment::STATUS_COMPLETED]);

        $otherProjectPayment = Payment::create([
            'project_id' => $this->project->project_id,
            'amount' => 4321,
            'payment_date' => now()->toDateString(),
            'recipient' => 'Someone else vendor',
            'payment_status' => Payment::STATUS_COMPLETED,
            'created_by' => $this->manager->user_id,
        ]);

        $this->actingAs($this->contributor)
            ->get(route('payments.index'))
            ->assertOk()
            ->assertSee('My cabling expense')
            ->assertDontSee('Someone else vendor');

        $this->actingAs($this->manager)
            ->get(route('payments.index'))
            ->assertOk()
            ->assertSee('Someone else vendor');

        // Sanity: the hidden row really exists.
        $this->assertDatabaseHas('payments', ['payment_id' => $otherProjectPayment->payment_id]);
    }

    public function test_declined_expenditure_reason_is_returned_to_the_submitter(): void
    {
        $payment = $this->submitExpense(500);

        $this->actingAs($this->manager)
            ->post(route('payments.reject', $payment), ['rejection_reason' => 'Missing receipt'])
            ->assertRedirect();

        $payment->refresh();
        $this->assertSame(Payment::STATUS_CANCELLED, $payment->payment_status);
        $this->assertSame('Missing receipt', $payment->rejection_reason);

        $this->assertTrue(
            Notification::where('user_id', $this->contributor->user_id)
                ->where('message', 'like', '%Missing receipt%')
                ->exists()
        );
    }

    public function test_application_timezone_matches_addis_ababa(): void
    {
        $this->assertSame('Africa/Addis_Ababa', config('app.timezone'));
        $this->assertSame('Africa/Addis_Ababa', now()->getTimezone()->getName());
    }

    private function submitExpense(float $amount, string $recipient = 'Vendor'): Payment
    {
        $this->actingAs($this->contributor)->post(route('payments.store'), [
            'task_id' => $this->task->task_id,
            'amount' => $amount,
            'payment_date' => now()->toDateString(),
            'recipient' => $recipient,
        ])->assertRedirect();

        return Payment::latest('payment_id')->firstOrFail();
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
