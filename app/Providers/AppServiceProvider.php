<?php

namespace App\Providers;

use App\Models\ChangeRequest;
use App\Models\Department;
use App\Models\Office;
use App\Models\Payment;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Policies\OfficePolicy;
use App\Policies\PaymentPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\TaskPolicy;
use App\Policies\TeamPolicy;
use App\Services\RbacService;
use App\Support\Permissions;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // System Administrators bypass all permission checks. Note: we must
        // not call $user->can() here — that re-enters the Gate and causes
        // infinite recursion. Check the RBAC engine / role directly instead.
        $rbac = app(RbacService::class);

        Gate::before(function (User $user, string $ability): ?bool {
            // Only org-wide (unscoped, non-leadership) roles may bypass the
            // Gate. Checking the RBAC engine here would let scoped roles
            // like "Head of Office" (permission set: *) bypass every
            // object-level policy outside their subtree.
            $hasOrgWideAdminSlug = $user->roles()
                ->wherePivotNull('scope_type')
                ->whereNotIn('role_name', RbacService::LEADERSHIP_ROLE_NAMES)
                ->whereHas('permissions', fn ($q) => $q->where('permission_name', 'manage_system_settings'))
                ->exists();

            if (
                $hasOrgWideAdminSlug
                || (method_exists($user, 'hasRole') && ($user->hasRole('System Administrator') || $user->hasRole('Administrator') || $user->hasRole('Super Admin')))
            ) {
                return true;
            }

            return null;
        });

        // One Gate per permission slug. Gate callbacks receive optional
        // model arguments, so `can:edit_projects` on routes resolves the
        // project from the route binding when present and delegates to the
        // RBAC engine (org + project + team scopes, inheritance-aware).
        foreach (array_keys(Permissions::ALL) as $slug) {
            Gate::define($slug, function (User $user, $model = null) use ($rbac, $slug) {
                if ($model instanceof Department || $model instanceof Office || $model instanceof Team) {
                    return $rbac->canForScope($user, $slug, $model);
                }

                $project = $model instanceof Project
                    ? $model
                    : ($model instanceof Phase ? $model->project : null);

                if ($model instanceof Task || $model instanceof ChangeRequest) {
                    $project = $model->project ?? optional($model->phase)->project;
                }

                return $rbac->can($user, $slug, $project);
            });
        }

        // Explicit policy registrations for object-level authorization.
        // TeamPolicy and PaymentPolicy MUST be registered explicitly: the
        // `can:update,team` / `can:manageMembers,team` route middleware and
        // the `can('approve'|'disburse', $payment)` checks resolve through
        // these registrations, and without them every non-admin request to
        // /teams/{team}/edit and /payments was denied.
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(Team::class, TeamPolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(Office::class, OfficePolicy::class);

        // The app ships custom CSS only (no Tailwind), so Laravel's default
        // pagination markup renders unstyled — including full-screen chevron
        // SVGs. Use the project's own pagination view everywhere.
        Paginator::defaultView('pagination::custom');
        Paginator::defaultSimpleView('pagination::custom');
    }
}
