<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ChecksHierarchicalLeadership;
use App\Services\RbacService;

/**
 * Object-level authorization for projects.
 *
 * Complements the slug-based Gate definitions registered in
 * AppServiceProvider: where `can:view_projects` answers "does this user
 * hold the permission anywhere", these policies answer "may this user
 * see THIS project" — resolved through the RBAC engine's project-scoped
 * sources (PM of record, assigned teams, direct project roles) plus
 * organization-wide grants.
 */
class ProjectPolicy
{
    use ChecksHierarchicalLeadership;

    /**
     * A user may view a project when they are a system administrator,
     * or when they are the project manager, or when they are a head of office
     * that manages the project's office(s), or when the project sits under
     * their office (primary or participating), or when they participate in
     * the project itself (assigned team, direct project role, etc.).
     */
    public function view(User $user, Project $project): bool
    {
        // System Administrators see everything.
        if ($user->canAccessGlobalScope()) {
            return true;
        }

        // Project Manager of record can view their projects.
        if ($project->project_manager_id && (int) $project->project_manager_id === (int) $user->user_id) {
            return true;
        }

        // Head of Office can view projects in their office.
        if ($user->isOfficeHead() && $this->projectBelongsToHeadOffices($user, $project)) {
            return true;
        }

        $officeIds = $user->officeScopeIds();
        $inUserOffice = $officeIds->isEmpty()
            ? (! $project->primary_office_id && ! $project->offices()->exists())
            : $officeIds->contains(fn (int $officeId) => $project->involvesOffice($officeId));

        if (! $inUserOffice) {
            return false;
        }

        if ($user->isTeamMember()) {
            return $project->participatesIn($user);
        }

        return $project->participatesIn($user)
            || app(RbacService::class)->can($user, 'view_projects', $project);
    }

    /**
     * Editing is allowed for: system administrators, project managers of record,
     * heads of office for projects in their office, team leaders with manage-level
     * team assignment, or those with organization-level edit_projects permission.
     */
    public function update(User $user, Project $project): bool
    {
        // System Administrators can edit everything.
        if ($user->canAccessGlobalScope()) {
            return true;
        }

        // Project Manager of record can edit their projects.
        if ($project->project_manager_id && (int) $project->project_manager_id === (int) $user->user_id) {
            return true;
        }

        // Head of Office can edit projects in their office.
        if ($user->isOfficeHead()) {
            return $this->projectBelongsToHeadOffices($user, $project);
        }

        if ($this->leadsOrOversees($user, $project)) {
            return true;
        }

        return $project->isManagedBy($user);
    }

    /** Only organization-level delete_projects holders may remove a project. */
    public function delete(User $user, Project $project): bool
    {
        return app(RbacService::class)->can($user, 'delete_projects', $project)
            && ($user->isOfficeHead()
                ? $this->projectBelongsToHeadOffices($user, $project)
                : $project->isManagedBy($user));
    }

    /** True when the project's primary or participating offices intersect the offices this user heads. */
    protected function projectBelongsToHeadOffices(User $user, Project $project): bool
    {
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
