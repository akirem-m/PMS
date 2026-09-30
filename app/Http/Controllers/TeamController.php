<?php

namespace App\Http\Controllers;

use App\Models\Office;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\UserResolver;
use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TeamController extends Controller
{
    public function index()
    {
        abort_unless(Auth::user()->can('view_projects'), 403);

        $authUser = Auth::user();
        $canViewAllOffices = $authUser->isAdmin();
        $myOfficeIds = $authUser->isOfficeHead()
            ? $authUser->officeScopeIds()
            : $authUser->officeIds();

        $teams = Team::with(['leader', 'members', 'projects', 'office'])
            ->when(! $canViewAllOffices && $myOfficeIds->isNotEmpty(), fn ($q) => $q->whereIn('office_id', $myOfficeIds->all()))
            ->when(! $canViewAllOffices && $authUser->isTeamMember(), fn ($q) => $q->whereHas('members', fn ($memberQuery) => $memberQuery->where('team_members.user_id', $authUser->user_id)))
            ->get();

        return view('teams.index', compact('teams'));
    }

    public function create()
    {
        // Standing up a brand-new team is an org-structure change — reserved
        // for whoever holds manage_team AND the Administrator role specifically.
        // A Team Lead has manage_team too, but only to run the team(s) they
        // already lead, not to create new ones.
        abort_unless($this->canCreateTeams(), 403);

        $actor = Auth::user();
        $users = User::availableTo($actor)->orderBy('full_name')->get();
        $offices = Office::active()
            ->when(! $actor->canAccessGlobalScope(), fn ($q) => $q->where('office_id', $actor->office_id))
            ->orderBy('office_name')->get();
        // dev's parent-team picker, with the office scope and cycle guard applied.
        $parentTeams = Team::orderBy('team_name')
            ->when($actor->office_id && ! $actor->isGlobal(), fn ($q) => $q->where('office_id', $actor->office_id))
            ->get();

        return view('teams.create', compact('users', 'offices', 'parentTeams'));
    }

    public function store(Request $request)
    {
        abort_unless($this->canCreateTeams(), 403);

        $actor = Auth::user();

        $data = $request->validate([
            'team_name' => ['required', 'string', 'max:100'],
            'parent_team_id' => ['nullable', 'exists:teams,team_id'],
            'team_leader_id' => ['nullable', 'exists:users,user_id'],
            'description' => ['nullable', 'string', 'max:1000'],
            'office_id' => ['nullable', 'exists:offices,office_id'],
            'parent_team_id' => ['nullable', 'exists:teams,team_id'],
        ]);

        if (! $actor->canAccessGlobalScope() && $data['office_id'] && (int) $data['office_id'] !== (int) $actor->office_id) {
            return back()->withErrors(['office_id' => 'Teams must belong to your office.'])->withInput();
        }

        $team = Team::create($data);

        // Server-side office restriction for the team leader.
        if ($team->team_leader_id && $team->office_id) {
            $leader = User::find($team->team_leader_id);
            if ($leader && $leader->office_id && ! $leader->isGlobal() && (int) $leader->office_id !== (int) $team->office_id) {
                $team->delete();

                return back()->withErrors([
                    'team_leader_id' => "{$leader->full_name} belongs to an office that is not associated with this team.",
                ])->withInput();
            }
        }

        if ($team->team_leader_id) {
            TeamMember::create(['team_id' => $team->team_id, 'user_id' => $team->team_leader_id, 'joined_date' => now()]);
        }

        Activity::log('Created team', 'Team', $team->team_id, $team->team_name.($team->office_id ? ' → '.optional($team->office)->office_name : ''));

        return redirect()->route('teams.show', $team)->with('status', 'Team created.');
    }

    public function show(Team $team)
    {
        $user = Auth::user();
        abort_unless($user->can('view_projects') && $this->canViewTeam($user, $team), 403);

        $team->load([
            'leader', 'parentTeam', 'childTeams', 'members.user.assignedTasks',
            'projects.budget', 'projects.phases.tasks',
            'assignedProjects.budget', 'assignedProjects.phases.tasks',
            'tasks.project', 'tasks.assignee', 'tasks.comments', 'tasks.attachments',
        ]);
        $canManage = $this->canManageTeam($user, $team);

        $allProjects = $team->allProjects()
            ->filter(fn ($project) => $user->can('view', $project))
            ->values();
        $taskStats = $team->taskStats();
        $teamTasks = $team->tasks;

        // If tasks are attached directly or via projects
        if ($teamTasks->isEmpty()) {
            $teamTasks = $allProjects->flatMap(fn ($p) => $p->allTasks()->filter(fn ($t) => (int) $t->team_id === (int) $team->team_id));
        }

        $memberIds = $team->members->pluck('user_id');
        $teamOfficeId = $team->office_id ? (int) $team->office_id : null;

        $availableUsers = $canManage
            ? User::whereNotIn('user_id', $memberIds)->where('status', 'Active')
                ->forOffice($teamOfficeId)->orderBy('full_name')->get()
            : collect();

        $leaderCandidates = $canManage
            ? User::where('status', 'Active')->forOffice($teamOfficeId)->orderBy('full_name')->get()
            : collect();

        return view('teams.show', compact('team', 'canManage', 'availableUsers', 'leaderCandidates', 'allProjects', 'taskStats', 'teamTasks'));
    }

    public function edit(Team $team)
    {
        abort_unless($this->canManageTeam(Auth::user(), $team), 403);

        $offices = Office::active()->orderBy('office_name')->get();
        $users = User::where('status', 'Active')->orderBy('full_name')->get();
        $parentTeams = Team::where('team_id', '!=', $team->team_id)
            ->whereNotIn('team_id', $team->allDescendantIds())
            ->orderBy('team_name')
            ->get();

        return view('teams.edit', compact('team', 'offices', 'users', 'parentTeams'));
    }

    public function update(Request $request, Team $team)
    {
        abort_unless($this->canManageTeam(Auth::user(), $team), 403);

        if ($request->filled('parent_team_id') && $team->wouldCauseCycle((int) $request->parent_team_id)) {
            return back()->withErrors(['parent_team_id' => 'Cannot set a subteam as parent (circular reference).'])->withInput();
        }

        $data = $request->validate([
            'team_name' => ['required', 'string', 'max:100'],
            'parent_team_id' => ['nullable', 'exists:teams,team_id'],
            'description' => ['nullable', 'string', 'max:1000'],
            'office_id' => ['nullable', 'exists:offices,office_id'],
        ]);

        $team->update($data);

        Activity::log('Updated team', 'Team', $team->team_id, $team->team_name);

        return redirect()->route('teams.show', $team)->with('status', 'Team updated.');
    }

    public function destroy(Team $team)
    {
        $user = Auth::user();
        abort_unless($user->can('delete', $team), 403);

        $teamName = $team->team_name;
        $team->delete();

        Activity::log('Deleted team', 'Team', $team->team_id, $teamName);

        return redirect()->route('teams.index')->with('status', "Team \"{$teamName}\" deleted.");
    }

    public function addMember(Request $request, Team $team)
    {
        $user = Auth::user();
        abort_unless($this->canManageTeam($user, $team), 403);

        $input = $request->input('user_id') ?? $request->input('user_name') ?? $request->input('name');
        $resolvedUserId = $this->resolveUserId($input, $team->team_id);

        if (! $resolvedUserId) {
            return back()->withErrors(['user_id' => 'Please provide a valid user name or select a member.']);
        }

        $added = User::find($resolvedUserId);
        if ($added && ! $this->canAssignUserToTeam($team, $added)) {
            return back()->withErrors([
                'user_id' => "{$added->full_name} belongs to an office that is not associated with this team.",
            ])->withInput();
        }

        if (! $team->members()->where('user_id', $resolvedUserId)->exists()) {
            TeamMember::create(['team_id' => $team->team_id, 'user_id' => $resolvedUserId, 'joined_date' => now()]);
            $added = User::find($resolvedUserId);
            Activity::log('Added team member', 'Team', $team->team_id, "{$added->full_name} → {$team->team_name}");
            Activity::notify((int) $resolvedUserId, "You were added to the {$team->team_name} team", 'general');
        }

        $added = User::find($resolvedUserId);

        return back()->with('status', "Member \"{$added->full_name}\" added to {$team->team_name}.");
    }

    public function removeMember(Team $team, TeamMember $member)
    {
        $user = Auth::user();
        abort_unless($this->canManageTeam($user, $team), 403);
        abort_unless($member->team_id === $team->team_id, 404);

        $removedName = optional($member->user)->full_name ?? 'A member';
        $member->delete();

        Activity::log('Removed team member', 'Team', $team->team_id, "{$removedName} left {$team->team_name}");

        return back()->with('status', 'Member removed.');
    }

    public function updateLeader(Request $request, Team $team)
    {
        $user = Auth::user();
        abort_unless($this->canManageTeam($user, $team), 403);

        $input = $request->input('team_leader_id') ?? $request->input('team_leader_name') ?? $request->input('leader_name');
        $resolvedUserId = $this->resolveUserId($input, $team->team_id);

        if (! $resolvedUserId) {
            return back()->withErrors(['team_leader_id' => 'Please provide a valid leader name or select a member.']);
        }

        $oldLeader = optional($team->leader)->full_name ?? 'None';
        $newLeader = User::find($resolvedUserId);

        if ($newLeader && ! $this->canAssignUserToTeam($team, $newLeader)) {
            return back()->withErrors([
                'team_leader_id' => "{$newLeader->full_name} belongs to an office that is not associated with this team.",
            ])->withInput();
        }

        // A leader must be on the team — add them if they aren't already.
        if (! $team->members()->where('user_id', $resolvedUserId)->exists()) {
            TeamMember::create(['team_id' => $team->team_id, 'user_id' => $resolvedUserId, 'joined_date' => now()]);
        }

        $team->update(['team_leader_id' => $resolvedUserId]);

        Activity::log('Changed Team Lead', 'Team', $team->team_id, "{$oldLeader} → {$newLeader->full_name} ({$team->team_name})");
        Activity::notify((int) $resolvedUserId, "You are now the leader of the {$team->team_name} team", 'general');

        return back()->with('status', "Team Lead changed to {$newLeader->full_name}.");
    }

    /**
     * Server-side office restriction for team membership: a user with an
     * office must belong to the team's office.
     */
    private function canAssignUserToTeam(Team $team, User $candidate): bool
    {
        return $candidate->isActive()
            && (empty($team->office_id) || empty($candidate->office_id)
                || $candidate->isGlobal()
                || (int) $team->office_id === (int) $candidate->office_id);
    }

    /**
     * Canonical team authorization lives in TeamPolicy; delegating here keeps
     * the route middleware (`can:view_projects` / `can:update,team`) and the
     * controller-side checks from drifting apart and denying authorized roles.
     */
    private function canViewTeam(User $user, Team $team): bool
    {
        return $user->can('view', $team);
    }

    /**
     * Resolution (and, for a typed name, creation) of team members and leads
     * lives in UserResolver so every form agrees on what a name means.
     */
    private function resolveUserId($input, ?int $teamId = null): ?int
    {
        return app(UserResolver::class)->resolve($input, $teamId);
    }

    private function canCreateTeams(): bool
    {
        $user = Auth::user();

        return $user->isAdmin() || $user->isDirectorOrAdmin() || $user->can('manage_team');
    }

    /**
     * Standardized on TeamPolicy hierarchical leadership: creating a team is
     * an org-structure change, so it stays restricted to org-wide managers,
     * but team management accepts any leadership role up the chain
     * (Team Lead -> Project Manager -> Office Head -> Department Head)
     * instead of the raw manage_team gate alone.
     */
    private function canManageTeam(User $user, Team $team): bool
    {
        return $user->can('update', $team) || $user->can('manageMembers', $team);
    }
}
