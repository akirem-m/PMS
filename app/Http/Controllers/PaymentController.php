<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Phase;
use App\Models\PhaseBudget;
use App\Models\Project;
use App\Models\ProjectBudget;
use App\Models\Task;
use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Expenditure lifecycle:
 *
 *   Contributor logs an expense against their task/phase  -> Pending
 *   Team Lead / Project Manager approves                   -> Approved
 *   Head of Office / Finance confirms the disbursement     -> Completed
 *
 * Authorization for every transition lives in App\Policies\PaymentPolicy so
 * the UI and the server agree on who may do what. The listing is scoped:
 * budget/oversight roles see their projects' payments, while contributors see
 * only payments on tasks they are assigned to plus the expenses they logged.
 */
class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();

        // Anyone may open the page — a contributor needs it to review the
        // expenses they submitted. What they can see is scoped below.
        $canSeeAll = $user->canAccessGlobalScope()
            || $user->hasPermission('view_budgets')
            || $user->hasPermission('manage_budgets');

        $applyScope = function ($query) use ($user, $canSeeAll) {
            if (! $canSeeAll) {
                return $query->where(function ($q) use ($user) {
                    $q->where('created_by', $user->user_id)
                        ->orWhereHas('task.assignments', fn ($assignmentQuery) => $assignmentQuery->where('user_id', $user->user_id));
                });
            }

            if (! $user->canAccessGlobalScope()) {
                $query->whereHas('project', fn ($projectQuery) => $projectQuery->visibleTo($user));
            }

            return $query;
        };

        $baseQuery = Payment::query();
        $applyScope($baseQuery);

        if ($request->filled('project_id')) {
            $baseQuery->where('project_id', $request->input('project_id'));
        }
        if ($request->filled('status')) {
            $baseQuery->where('payment_status', $request->input('status'));
        }
        if ($request->filled('task_id')) {
            $baseQuery->where('task_id', $request->input('task_id'));
        }

        $payments = (clone $baseQuery)
            ->with(['project', 'phase', 'task', 'creator', 'approver', 'disburser'])
            ->orderByDesc('payment_date')
            ->paginate(20)
            ->withQueryString();

        // Aggregated in SQL — the previous implementation loaded every matching
        // row into memory to sum it in PHP.
        $totals = (clone $baseQuery)->selectRaw(
            "COALESCE(SUM(CASE WHEN payment_status = 'Completed' THEN amount ELSE 0 END), 0) as completed_sum,
             COALESCE(SUM(CASE WHEN payment_status = 'Pending' THEN amount ELSE 0 END), 0) as pending_sum,
             COALESCE(SUM(CASE WHEN payment_status = 'Approved' THEN amount ELSE 0 END), 0) as approved_sum,
             SUM(CASE WHEN payment_status = 'Completed' THEN 1 ELSE 0 END) as completed_count,
             SUM(CASE WHEN payment_status = 'Pending' THEN 1 ELSE 0 END) as pending_count,
             SUM(CASE WHEN payment_status = 'Approved' THEN 1 ELSE 0 END) as approved_count"
        )->first();

        $totalPayments = (float) ($totals->completed_sum ?? 0);
        $pendingPayments = (float) ($totals->pending_sum ?? 0);
        $approvedPayments = (float) ($totals->approved_sum ?? 0);
        $completedCount = (int) ($totals->completed_count ?? 0);
        $pendingCount = (int) ($totals->pending_count ?? 0);
        $approvedCount = (int) ($totals->approved_count ?? 0);

        $projectsQuery = Project::orderBy('project_name');
        if (! $user->canAccessGlobalScope()) {
            $projectsQuery->visibleTo($user);
        }
        $projects = $projectsQuery->get();

        // Tasks the current user may log an expenditure against.
        $assignableTasks = Task::with('project')
            ->where(function ($q) use ($user) {
                $q->where('assigned_to', $user->user_id)
                    ->orWhereHas('assignments', fn ($assignmentQuery) => $assignmentQuery->where('user_id', $user->user_id));
            })
            ->orderBy('task_name')
            ->get();

        return view('payments.index', compact(
            'payments',
            'projects',
            'assignableTasks',
            'totalPayments',
            'pendingPayments',
            'approvedPayments',
            'completedCount',
            'pendingCount',
            'approvedCount'
        ));
    }

    public function store(Request $request)
    {
        $user = Auth::user();

        $data = $request->validate([
            'project_id' => ['nullable', 'exists:projects,project_id'],
            'phase_id' => ['nullable', 'exists:phases,phase_id'],
            'task_id' => ['nullable', 'exists:tasks,task_id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_date' => ['required', 'date'],
            'recipient' => ['required', 'string', 'max:255'],
            'payment_status' => ['nullable', 'in:Completed,Pending,Approved,Cancelled'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sop_process' => ['nullable', 'string', 'max:150'],
        ]);

        $task = ! empty($data['task_id'])
            ? Task::with('phase.budget')->find($data['task_id'])
            : null;

        $project = $task?->project
            ?? (! empty($data['project_id']) ? Project::find($data['project_id']) : null);

        abort_unless($project, 422, 'Select the project or task this expenditure belongs to.');

        $this->authorize('create', [Payment::class, $project]);

        $phase = $task?->phase
            ?? (! empty($data['phase_id']) ? Phase::with('budget')->find($data['phase_id']) : null);

        $managesBudget = $user->hasPermission('manage_budgets') || $user->canAccessGlobalScope();

        if (! $managesBudget) {
            // Contributor submission: it must belong to work they are assigned,
            // and it always enters the approval queue as Pending.
            abort_unless($task, 422, 'Choose the task this expenditure belongs to.');

            $isAssigned = $task->assignments()->where('user_id', $user->user_id)->exists()
                || (int) $task->assigned_to === (int) $user->user_id;

            abort_unless($isAssigned, 403, 'You can only log expenditures against tasks assigned to you.');

            $data['payment_status'] = Payment::STATUS_PENDING;
        } elseif (empty($data['payment_status'])) {
            $data['payment_status'] = Payment::STATUS_COMPLETED;
        }

        // Phase-fund guard: pending, approved and completed payments all
        // reserve budget so a worker cannot over-commit while approval is
        // in flight.
        if ($phase && $phase->budget) {
            $remaining = $phase->remainingExpenseBudget();

            abort_if(
                (float) $data['amount'] > $remaining,
                422,
                'This expenditure exceeds the phase funds available (ETB '.number_format($remaining, 2).').'
            );
        }

        $data['project_id'] = $project->project_id;
        $data['phase_id'] = $phase?->phase_id;
        $data['created_by'] = $user->user_id;

        $payment = Payment::create($data);

        $this->syncBudgetRollups((int) $payment->project_id, $payment->phase_id ? (int) $payment->phase_id : null);

        Activity::log(
            'Recorded expenditure',
            'Payment',
            $payment->payment_id,
            'ETB '.number_format($payment->amount)." to {$payment->recipient} for project #{$payment->project_id} ({$payment->payment_status})"
        );

        if ($payment->isPending()) {
            $this->notifyApprovers($payment, $user->full_name.' logged an expenditure of ETB '.number_format($payment->amount).' awaiting your approval.');
        }

        return back()->with(
            'status',
            $payment->isPending()
                ? 'Expenditure submitted for approval.'
                : 'Payment of ETB '.number_format($payment->amount).' recorded successfully.'
        );
    }

    /** Team Lead / Project Manager signs off a submitted expenditure. */
    public function approve(Request $request, Payment $payment)
    {
        $user = Auth::user();
        $this->authorize('approve', $payment);

        abort_unless($payment->isPending(), 422, 'Only expenditures awaiting approval can be approved.');

        $payment->update([
            'payment_status' => Payment::STATUS_APPROVED,
            'approved_by' => $user->user_id,
            'approved_at' => now(),
            'rejection_reason' => null,
        ]);

        Activity::log(
            'Approved expenditure',
            'Payment',
            $payment->payment_id,
            'ETB '.number_format($payment->amount)." to {$payment->recipient} approved for disbursement"
        );

        if ($payment->created_by) {
            Activity::notify(
                (int) $payment->created_by,
                'Your expenditure of ETB '.number_format($payment->amount)." was approved by {$user->full_name} and now awaits disbursement confirmation.",
                'payment'
            );
        }

        return back()->with('status', 'Expenditure approved — it now awaits finance disbursement.');
    }

    /** Head of Office / Finance confirms the money actually left the account. */
    public function disburse(Request $request, Payment $payment)
    {
        $user = Auth::user();
        $this->authorize('disburse', $payment);

        abort_unless($payment->isApproved(), 422, 'Only approved expenditures can be disbursed.');

        $payment->update([
            'payment_status' => Payment::STATUS_COMPLETED,
            'disbursed_by' => $user->user_id,
            'disbursed_at' => now(),
        ]);

        $this->syncBudgetRollups((int) $payment->project_id, $payment->phase_id ? (int) $payment->phase_id : null);

        Activity::log(
            'Confirmed disbursement',
            'Payment',
            $payment->payment_id,
            'ETB '.number_format($payment->amount)." disbursed to {$payment->recipient}"
        );

        if ($payment->created_by) {
            Activity::notify(
                (int) $payment->created_by,
                'Disbursement confirmed: ETB '.number_format($payment->amount)." to {$payment->recipient}.",
                'payment'
            );
        }

        return back()->with('status', 'Disbursement confirmed and recorded against the budget.');
    }

    /** Decline a submitted expenditure with a reason. */
    public function reject(Request $request, Payment $payment)
    {
        $user = Auth::user();
        $this->authorize('approve', $payment);

        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ]);

        abort_if($payment->isCompleted(), 422, 'Disbursed expenditures cannot be rejected.');

        $payment->update([
            'payment_status' => Payment::STATUS_CANCELLED,
            'rejection_reason' => $data['rejection_reason'],
        ]);

        $this->syncBudgetRollups((int) $payment->project_id, $payment->phase_id ? (int) $payment->phase_id : null);

        Activity::log(
            'Rejected expenditure',
            'Payment',
            $payment->payment_id,
            'ETB '.number_format($payment->amount)." rejected: {$data['rejection_reason']}"
        );

        if ($payment->created_by) {
            Activity::notify(
                (int) $payment->created_by,
                'Your expenditure of ETB '.number_format($payment->amount)." was declined by {$user->full_name}: {$data['rejection_reason']}",
                'payment'
            );
        }

        return back()->with('status', 'Expenditure declined.');
    }

    public function update(Request $request, Payment $payment)
    {
        $this->authorize('update', $payment);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_date' => ['required', 'date'],
            'recipient' => ['required', 'string', 'max:255'],
            'payment_status' => ['required', 'in:Completed,Pending,Approved,Cancelled'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sop_process' => ['nullable', 'string', 'max:150'],
        ]);

        $previousProjectId = (int) $payment->project_id;
        $previousPhaseId = $payment->phase_id ? (int) $payment->phase_id : null;

        $payment->update($data);

        $this->syncBudgetRollups($previousProjectId, $previousPhaseId);
        if ((int) $payment->project_id !== $previousProjectId || (int) $payment->phase_id !== $previousPhaseId) {
            $this->syncBudgetRollups((int) $payment->project_id, $payment->phase_id ? (int) $payment->phase_id : null);
        }

        Activity::log(
            'Updated payment',
            'Payment',
            $payment->payment_id,
            "Updated payment #{$payment->payment_id} (ETB ".number_format($payment->amount)." to {$payment->recipient})"
        );

        return back()->with('status', 'Payment updated successfully.');
    }

    public function destroy(Request $request, Payment $payment)
    {
        $this->authorize('delete', $payment);

        $projectId = (int) $payment->project_id;
        $phaseId = $payment->phase_id ? (int) $payment->phase_id : null;
        $details = "Payment #{$payment->payment_id} of ETB ".number_format($payment->amount)." to {$payment->recipient}";

        $payment->delete();

        $this->syncBudgetRollups($projectId, $phaseId);

        Activity::log('Deleted payment', 'Payment', $payment->payment_id, $details);

        return back()->with('status', 'Payment deleted successfully.');
    }

    /** Alert the approvers (PM of record + team lead) that money awaits sign-off. */
    private function notifyApprovers(Payment $payment, string $message): void
    {
        $project = $payment->project;
        $recipients = collect([$project?->project_manager_id, $payment->task?->teamLead()?->user_id])
            ->filter()
            ->unique();

        foreach ($recipients as $recipientId) {
            Activity::notify((int) $recipientId, $message, 'payment');
        }
    }

    /**
     * Recompute the project/phase spent rollups from completed payments.
     * Always writes the value — including zero — so deleting or rejecting the
     * last payment no longer leaves a stale rollup behind.
     */
    private function syncBudgetRollups(?int $projectId, ?int $phaseId = null): void
    {
        if ($projectId) {
            $completedSum = (float) Payment::where('project_id', $projectId)
                ->where('payment_status', Payment::STATUS_COMPLETED)
                ->sum('amount');

            ProjectBudget::updateOrCreate(
                ['project_id' => $projectId],
                ['spent_amount' => $completedSum]
            );
        }

        if ($phaseId) {
            $phaseCompletedSum = (float) Payment::where('phase_id', $phaseId)
                ->where('payment_status', Payment::STATUS_COMPLETED)
                ->sum('amount');

            PhaseBudget::updateOrCreate(
                ['phase_id' => $phaseId],
                ['spent_amount' => $phaseCompletedSum]
            );
        }
    }
}
