<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\Project;
use App\Models\User;
use App\Services\RbacService;

/**
 * Object-level authorization for expenditures.
 *
 * The workflow is deliberately split so no single person both records and
 * releases money:
 *
 *   create()   — any project participant (assignee/contributor) may log an
 *                expenditure against their task/phase budget as `Pending`.
 *   approve()  — the project manager of record, a manage-level project
 *                authority, or an org-wide budget holder signs it off.
 *   disburse() — the Head of Office owning the project (or org-wide Finance,
 *                i.e. `manage_budgets` holders) confirms the actual payment.
 */
class PaymentPolicy
{
    /** Payment listing is scoped in the controller, not denied here. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Payment $payment): bool
    {
        if ($user->canAccessGlobalScope()) {
            return true;
        }

        if ((int) $payment->created_by === (int) $user->user_id) {
            return true;
        }

        if ($payment->task_id
            && $payment->task()->whereHas('assignments', fn ($q) => $q->where('user_id', $user->user_id))->exists()) {
            return true;
        }

        $project = $payment->project;

        if (! $project) {
            return false;
        }

        return $project->isManagedBy($user)
            || app(RbacService::class)->can($user, 'view_budgets', $project);
    }

    /** Logging an expenditure requires a stake in the project. */
    public function create(User $user, Project $project): bool
    {
        if ($user->canAccessGlobalScope()) {
            return true;
        }

        return $project->isManagedBy($user)
            || $project->participatesIn($user);
    }

    /** Team Lead / Project Manager approval of a submitted expenditure. */
    public function approve(User $user, Payment $payment): bool
    {
        if ($user->canAccessGlobalScope()) {
            return true;
        }

        $project = $payment->project;

        if (! $project) {
            return false;
        }

        return $project->isManagedBy($user)
            || app(RbacService::class)->can($user, 'manage_budgets', $project);
    }

    /** Head of Office / Finance confirmation of the actual disbursement. */
    public function disburse(User $user, Payment $payment): bool
    {
        if ($user->canAccessGlobalScope()) {
            return true;
        }

        $project = $payment->project;

        if (! $project) {
            return false;
        }

        if ($user->isOfficeHead() && $this->officeHeadsProject($user, $project)) {
            return true;
        }

        // Organization-wide Finance holders. Evaluated *with the project in
        // scope* so a Head of Office whose role carries the wildcard grant
        // still cannot release funds belonging to another office.
        return $user->hasPermission('manage_budgets', $project);
    }

    public function update(User $user, Payment $payment): bool
    {
        return $user->canAccessGlobalScope() || $user->hasPermission('manage_budgets');
    }

    public function delete(User $user, Payment $payment): bool
    {
        return $user->canAccessGlobalScope() || $user->hasPermission('manage_budgets');
    }

    /**
     * True when the user heads an office that owns or participates in the
     * project. Mirrors ProjectPolicy's office-scope rule so a Head of Office
     * only releases disbursements inside their own office.
     */
    protected function officeHeadsProject(User $user, Project $project): bool
    {
        if ($user->office_id && $project->primary_office_id
            && (int) $user->office_id === (int) $project->primary_office_id) {
            return true;
        }

        $headOfficeIds = $user->headOfficeIds();

        if ($headOfficeIds->isEmpty()) {
            return false;
        }

        if ($project->primary_office_id && $headOfficeIds->contains((int) $project->primary_office_id)) {
            return true;
        }

        return $project->offices()
            ->pluck('offices.office_id')
            ->contains(fn ($id) => $headOfficeIds->contains((int) $id));
    }
}
