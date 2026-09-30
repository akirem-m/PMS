<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;
use App\Policies\Concerns\ChecksHierarchicalLeadership;

/**
 * Object-level authorization for teams: the Team Lead of the team, or any
 * leadership role on a parent node (Project Manager, Office Head,
 * Department Head) may manage the team. Members and office staff can view.
 */
class TeamPolicy
{
    use ChecksHierarchicalLeadership;

    public function view(User $user, Team $team): bool
    {
        if ($user->canAccessGlobalScope()) {
            return true;
        }

        if ($this->leadsOrOversees($user, $team) || $this->headsTeamOffice($user, $team)) {
            return true;
        }

        // Members of the team, and staff of the owning office/department.
        return $team->members->contains('user_id', $user->user_id)
            || $user->officeIds()->contains((int) $team->office_id);
    }

    /**
     * Managing a team follows the leadership chain: Team Lead (or Sub-Team
     * Lead) of the team, the Project Manager above it, the Head of Office
     * owning it, and the Department Head above that.
     *
     * Deliberately NOT a bare `manage_team` permission check — that would let
     * a Team Lead edit teams they do not lead anywhere in the organization.
     */
    public function update(User $user, Team $team): bool
    {
        if ($user->canAccessGlobalScope()) {
            return true;
        }

        return $this->headsTeamOffice($user, $team)
            || $this->leadsOrOversees($user, $team);
    }

    public function delete(User $user, Team $team): bool
    {
        if ($user->canAccessGlobalScope()) {
            return true;
        }

        if (! ($this->headsTeamOffice($user, $team) || $this->leadsOrOversees($user, $team))) {
            return false;
        }

        return $user->hasPermission('manage_team')
            || $user->hasPermission('edit_projects')
            || $user->isDirectorOrAdmin();
    }

    /** Only leaders up the chain may add/remove team members. */
    public function manageMembers(User $user, Team $team): bool
    {
        return $this->update($user, $team);
    }

    /** Creating a sub-team under this team: Team Lead or above. */
    public function createSubTeam(User $user, Team $team): bool
    {
        return $this->update($user, $team);
    }

    /**
     * Office scope: a Head of Office manages every team belonging to their own
     * office (`$team->office_id === $user->office_id`), plus any office their
     * Head-of-Office role is explicitly scoped to. A head never reaches a
     * team in another office.
     */
    protected function headsTeamOffice(User $user, Team $team): bool
    {
        if (! $team->office_id || ! $user->isOfficeHead()) {
            return false;
        }

        if ($user->office_id && (int) $team->office_id === (int) $user->office_id) {
            return true;
        }

        return $user->headOfficeIds()->contains((int) $team->office_id);
    }
}
