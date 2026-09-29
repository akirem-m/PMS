<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\ChangeRequest;
use App\Models\Office;
use App\Models\Phase;
use App\Models\PhaseBudget;
use App\Models\Project;
use App\Models\ProjectBudget;
use App\Models\ProjectDeliverable;
use App\Models\ProjectMemberRole;
use App\Models\ProjectType;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\Team;
use App\Models\User;
use App\Services\ProjectWizardService;
use App\Services\RosterService;
use App\Services\TaskBudgetAllocationService;
use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function __construct(
        private ProjectWizardService $projectWizardService,
        private RosterService $rosterService,
    ) {}

    private const PHASES = ['Initiation', 'Planning', 'Execution', 'Monitoring', 'Closure'];

    private const STATUSES = ['planning', 'active', 'risk', 'closed'];

    private const PRIORITIES = ['Low', 'Medium', 'High', 'Urgent'];

    public const SPECIALTIES = [
        'UI/UX Designer',
        'Frontend Developer',
        'Backend Developer',
        'Full Stack Developer',
        'Mobile App Developer',
        'QA / Test Engineer',
        'DevOps Engineer',
        'Database Administrator',
        'System Analyst',
        'Security Specialist',
        'Technical Writer',
    ];

    public function index(Request $request)
    {
        Gate::authorize('view_projects');

        /** @var User $authUser */
        $authUser = Auth::user();
        $isAdmin = $authUser->canAccessGlobalScope();

        $query = Project::with(['team.leader', 'teams.leader', 'projectManager', 'budget', 'tasks', 'phases.tasks', 'memberRoles.user']);

        $projectTypes = ProjectType::where('is_active', true)->orderBy('name')->get();

        /*
         * Only organization-wide viewers may switch offices; everyone else is
         * pinned to the offices their own scope covers.
         */
        $offices = $isAdmin
            ? Office::orderBy('office_name')->get()
            : Office::whereIn('office_id', $authUser->officeScopeIds()->all())->orderBy('office_name')->get();

        /*
         * The active office drives the Project Type tabs: when an office is
         * selected (either explicitly by an admin or implicitly by the viewer's
         * scope) only that office's types plus global types are offered.
         */
        $officeFilter = $isAdmin ? $request->get('office') : $offices->first()?->office_id;

        if ($officeFilter) {
            $projectTypes = $projectTypes->filter(
                fn ($t) => empty($t->office_id) || $t->office_id == $officeFilter
            )->values();
        }

        if ($type = $request->get('type')) {
            $typeModel = $projectTypes->firstWhere('name', $type)
                ?? ProjectType::where('name', $type)->first();

            $query->where(function ($q) use ($type, $typeModel) {
                $q->where('project_type', $type);

                if ($typeModel) {
                    $q->orWhere('project_type_id', $typeModel->project_type_id);
                }
            });
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($priority = $request->get('priority')) {
            $query->where('priority', $priority);
        }

        if ($search = $request->get('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('project_name', 'like', "%{$search}%")
                    ->orWhere('client', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        /*
         * Participation & office scoping. System Administrators see
         * everything and may filter with the office dropdown; every other
         * role (including Project Managers) only sees projects under their
         * office(s) or ones they participate in directly.
         */
        if ($isAdmin) {
            if ($officeFilter) {
                $query->where(function ($q) use ($officeFilter) {
                    $q->where('primary_office_id', $officeFilter)
                        ->orWhereHas('offices', fn ($oq) => $oq->where('offices.office_id', $officeFilter));
                });
            }
        } else {
            $query->visibleTo($authUser);
        }

        $projects = $query->orderByDesc('project_id')->paginate(15)->withQueryString();

        return view('projects.index', compact('projects', 'projectTypes', 'offices', 'isAdmin', 'officeFilter'));
    }

    public function show(Project $project)
    {
        $this->authorize('view', $project);

        $project->load([
            'team.leader',
            'team.members.user',
            'teams.leader',
            'teams.members.user',
            'projectManager',
            'office',
            'budget',
            'phases.budget',
            'phases.tasks.assignee',
            'phases.tasks.team',
            'phases.tasks.phase',
            'deliverables',
            'changeRequests.requester',
            'memberRoles.user',
            'memberRoles.role',
        ]);

        $tasks = $project->allTasks();
        $assignableUsers = $project->getAssignableUsersWithRoles(Auth::user());
        $projectRoster = $this->rosterService->getFormattedRoster($project);
        $taskStats = $project->taskStats();
        // Only teams from the project's primary or participating offices may be assigned.
        $allowedOfficeIds = $this->authorizedOfficeIds($project);
        $assignableTeams = Team::where('status', 'Active')->with('leader')
            ->when($allowedOfficeIds->isNotEmpty(), fn ($q) => $q->whereIn('office_id', $allowedOfficeIds))
            ->orderBy('team_name')->get();

        return view('projects.show', compact('project', 'tasks', 'assignableUsers', 'projectRoster', 'taskStats', 'assignableTeams'));
    }

    public function create()
    {
        Gate::authorize('create_projects');

        /** @var User $user */
        $user = Auth::user();

        // Admins can access global scope and create projects for any office.
        // Other users must have an office assignment.
        if (! $user->canAccessGlobalScope() && ! $user->office_id) {
            abort(403, 'Your account must belong to an office before creating a project.');
        }

        // For non-admin users, show only their office teams.
        // For admins, show all teams across all offices.
        $teamsQuery = Team::with(['leader', 'members.user', 'office']);
        if (! $user->canAccessGlobalScope()) {
            $teamsQuery = $teamsQuery->where('office_id', $user->office_id);
        }
        $teams = $teamsQuery->orderBy('team_name')->get();

        // For non-admin users, show only their office project managers.
        // For admins, show all active users.
        $projectManagersQuery = User::where('status', 'Active');
        if (! $user->canAccessGlobalScope()) {
            $projectManagersQuery = $projectManagersQuery->where('office_id', $user->office_id);
        }
        $projectManagers = $projectManagersQuery->orderBy('full_name')->get();

        $projectTypes = ProjectType::where('is_active', true)->orderBy('name')->get();

        // For non-admin users, show only their office. For admins, show all active offices.
        $officesQuery = Office::active();
        if (! $user->canAccessGlobalScope()) {
            $officesQuery = $officesQuery->where('office_id', $user->office_id);
        }
        $offices = $officesQuery->orderBy('office_name')->get();

        $teamsData = $teams->map(function ($t) {
            return [
                'id' => $t->team_id,
                'name' => $t->team_name,
                'office_id' => $t->office_id,
                'leader_name' => optional($t->leader)->full_name ?? 'Unassigned',
                'members' => $t->members->map(function ($m) use ($t) {
                    if ($m->user && $t->office_id && $m->user->office_id
                        && (int) $m->user->office_id !== (int) $t->office_id) {
                        return null;
                    }

                    return [
                        'id' => $m->user ? $m->user->user_id : null,
                        'name' => $m->user ? $m->user->full_name : 'Member',
                    ];
                })->filter(fn ($m) => is_array($m) && ! is_null($m['id']))->values()->all(),
            ];
        })->values()->all();

        return view('projects.create', [
            'teams' => $teams,
            'teamsData' => $teamsData,
            'projectTypes' => $projectTypes,
            'priorities' => self::PRIORITIES,
            'projectManagers' => $projectManagers,
            'offices' => $offices,
            'isAdmin' => $user->canAccessGlobalScope(),
        ]);
    }

    public function saveWizardStep(Request $request)
    {
        Gate::authorize('create_projects');

        /** @var User $user */
        $user = Auth::user();

        $step = (int) $request->input('step');
        $projectId = $request->input('project_id');

        if ($step === 1) {
            $officeScope = $request->input('primary_office_id');
            $data = $request->validate([
                'project_name' => ['required', 'string', 'max:150'],
                'description' => ['nullable', 'string', 'max:2000'],
                'client' => ['nullable', 'string', 'max:150'],
                'project_type' => ['nullable', 'string', 'max:100'],
                'project_type_id' => [
                    'nullable',
                    Rule::exists('project_types', 'project_type_id')->where(function ($q) use ($officeScope) {
                        $q->where(function ($q2) use ($officeScope) {
                            $q2->whereNull('office_id')->orWhere('office_id', $officeScope);
                        });
                    }),
                ],
                'project_manager_id' => [
                    'nullable',
                    Rule::exists('users', 'user_id')->where(function ($query) use ($user) {
                        $query->where('status', 'Active')->where('office_id', $user->office_id);
                    }),
                ],
                'priority' => ['nullable', 'in:Low,Medium,High,Urgent'],
                'start_date' => ['nullable', 'date'],
                'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
                'allocated_amount' => ['nullable', 'numeric', 'min:0'],
                'primary_office_id' => [
                    'required',
                    Rule::exists('offices', 'office_id')->where('office_id', $user->office_id),
                ],
                'participating_offices' => ['nullable', 'array'],
                'participating_offices.*' => ['exists:offices,office_id'],
            ]);

            $project = $projectId ? Project::findOrFail($projectId) : new Project;
            $project->fill([
                'project_name' => $data['project_name'],
                'description' => $data['description'] ?? null,
                'client' => $data['client'] ?? null,
                'project_type' => $data['project_type'] ?? optional(ProjectType::find($data['project_type_id'] ?? null))->name ?? 'Software',
                'project_type_id' => $this->resolveProjectTypeId($data),
                'project_manager_id' => $data['project_manager_id'] ?? null,
                'priority' => $data['priority'] ?? 'Medium',
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'status' => 'planning',
                'progress' => 0,
                'created_by' => $project->created_by ?: $user->user_id,
            ]);
            $project->save();

            // Primary + participating offices. The primary office is stored
            // on the project row AND represented in the pivot so the
            // many-to-many stays complete without duplicate rows.
            $project->primary_office_id = $data['primary_office_id'] ?? null;
            $project->save();

            $officePivot = [];
            if (! empty($data['primary_office_id'])) {
                $officePivot[$data['primary_office_id']] = ['participation_type' => 'primary'];
            }
            foreach (collect($data['participating_offices'] ?? [])->unique() as $poId) {
                if ((int) $poId !== (int) ($data['primary_office_id'] ?? 0)) {
                    $officePivot[$poId] = ['participation_type' => 'participating'];
                }
            }
            $project->offices()->sync($officePivot);

            if (! empty($data['primary_office_id']) || ! empty($officePivot)) {
                Activity::log('Assigned office to project', 'Project', $project->project_id,
                    optional(Office::find($data['primary_office_id'] ?? null))->office_name ?? 'cross-office');
            }

            ProjectBudget::updateOrCreate(['project_id' => $project->project_id], [
                'allocated_amount' => $data['allocated_amount'] ?? 0,
                'spent_amount' => 0,
                'currency' => 'ETB',
            ]);

            if ($project->phases()->count() === 0) {
                foreach (self::PHASES as $i => $phaseName) {
                    $phase = Phase::create([
                        'project_id' => $project->project_id,
                        'phase_name' => $phaseName,
                        'status' => $i === 0 ? 'In Progress' : 'Not started',
                        'sequence_order' => $i,
                    ]);
                    PhaseBudget::create([
                        'phase_id' => $phase->phase_id,
                        'allocated_amount' => round(($data['allocated_amount'] ?? 0) / 5),
                        'spent_amount' => 0,
                    ]);
                }
            }
        } elseif ($step === 2) {
            $project = Project::findOrFail($projectId);
            $data = $request->validate([
                'teams' => ['required', 'array', 'min:1'],
                'teams.*' => ['exists:teams,team_id'],
            ]);
            $teamIds = collect($data['teams'])->map(fn ($id) => (int) $id)->unique()->values();

            // Server-side office restriction for the wizard team step.
            $allowedOfficeIds = $this->authorizedOfficeIds($project);
            if ($allowedOfficeIds->isNotEmpty()) {
                $invalid = Team::whereIn('team_id', $teamIds)->whereNotIn('office_id', $allowedOfficeIds)->get();
                if ($invalid->isNotEmpty()) {
                    return response()->json([
                        'message' => 'Some teams belong to offices that are not associated with this project: '.$invalid->pluck('team_name')->implode(', '),
                    ], 422);
                }
            }

            $project->update(['team_id' => $teamIds->first()]);
            DB::table('project_teams')->where('project_id', $project->project_id)->delete();
            foreach ($teamIds as $teamId) {
                DB::table('project_teams')->insert([
                    'project_id' => $project->project_id,
                    'team_id' => $teamId,
                    'assigned_date' => now(),
                ]);
            }
        } elseif ($step === 3) {
            $project = Project::findOrFail($projectId);
            $data = $request->validate([
                'tasks' => ['nullable', 'array'],
                'tasks.*.task_name' => ['nullable', 'string', 'max:150'],
                'tasks.*.team_id' => ['nullable', 'exists:teams,team_id'],
                'tasks.*.assigned_to' => ['nullable'],
                'tasks.*.user_ids' => ['nullable', 'array'],
                'tasks.*.user_ids.*' => ['exists:users,user_id'],
                'tasks.*.priority' => ['nullable', 'in:Low,Medium,High,Urgent'],
                'tasks.*.budget' => ['nullable', 'numeric', 'min:0'],
                'tasks.*.start_date' => ['nullable', 'date'],
                'tasks.*.end_date' => ['nullable', 'date', 'after_or_equal:tasks.*.start_date'],
            ]);
            $firstPhase = $project->phases()->orderBy('sequence_order')->first();

            $requestedTaskAllocation = collect($data['tasks'] ?? [])
                ->sum(fn ($taskData) => blank($taskData['task_name'] ?? null) ? 0 : (float) ($taskData['budget'] ?? 0));
            if ($firstPhase) {
                app(TaskBudgetAllocationService::class)->assertBatchAllocationAllowed($firstPhase, $requestedTaskAllocation);
            }

            // Server-side office restriction for task teams.
            $allowedOfficeIds = $this->authorizedOfficeIds($project);
            if ($allowedOfficeIds->isNotEmpty()) {
                $taskTeamIds = collect($data['tasks'] ?? [])->pluck('team_id')->filter()->map(fn ($id) => (int) $id);
                if ($taskTeamIds->isNotEmpty() && Team::whereIn('team_id', $taskTeamIds)->whereNotIn('office_id', $allowedOfficeIds)->exists()) {
                    return response()->json(['message' => 'One or more task teams belong to offices that are not associated with this project.'], 422);
                }
            }

            $project->tasks()->delete();
            foreach ($data['tasks'] ?? [] as $taskData) {
                if (blank($taskData['task_name'] ?? null)) {
                    continue;
                }

                $taskTeamId = $taskData['team_id'] ?? $project->team_id;

                // Multi-select payload: several team members per task. The
                // legacy single `assigned_to` value is still honoured.
                $assigneeIds = collect($taskData['user_ids'] ?? [])
                    ->push($taskData['assigned_to'] ?? null)
                    ->map(fn ($id) => $this->resolveUserId($id, $taskTeamId))
                    ->filter()
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values();

                // Server-side office restriction for task assignees.
                foreach ($assigneeIds as $candidateId) {
                    $candidate = User::find($candidateId);
                    if ($candidate && ! $project->canAssignUser($candidate)) {
                        return response()->json([
                            'message' => "{$candidate->full_name} belongs to an office that is not associated with this project.",
                        ], 422);
                    }
                }

                $task = Task::create([
                    'project_id' => $project->project_id,
                    'phase_id' => $firstPhase?->phase_id,
                    'team_id' => $taskTeamId,
                    'task_name' => $taskData['task_name'],
                    'assigned_to' => $assigneeIds->first(),
                    'priority' => $taskData['priority'] ?? 'Medium',
                    'status' => 'To Do',
                    'budget' => $taskData['budget'] ?? 0,
                    'start_date' => $taskData['start_date'] ?? null,
                    'end_date' => $taskData['end_date'] ?? null,
                    'progress' => 0,
                ]);

                foreach ($assigneeIds as $assigneeId) {
                    TaskAssignment::create([
                        'task_id' => $task->task_id,
                        'user_id' => $assigneeId,
                        'role_label' => $assigneeId === $task->assigned_to ? 'Primary Assignee' : 'Contributor',
                        'acceptance_status' => 'Pending Acceptance',
                        'assigned_by' => $user->user_id,
                        'assigned_at' => now(),
                    ]);

                    if ($assigneeId !== (int) $user->user_id) {
                        Activity::notify($assigneeId, "You have been assigned: \"{$task->task_name}\" on {$project->project_name}", 'task');
                    }
                }
            }
            $project->recalculateProgress();
        } elseif ($step === 4) {
            $project = Project::findOrFail($projectId);

            return response()->json(['redirect' => route('projects.show', $project)]);
        } else {
            abort(422, 'Invalid wizard step.');
        }

        return response()->json([
            'project_id' => $project->project_id,
            'message' => "Step {$step} saved successfully.",
        ]);
    }

    /**
     * Prefers an explicit project_type_id; otherwise falls back to the
     * legacy free-text project_type string so old clients keep working.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveProjectTypeId(array $data): ?int
    {
        if (! empty($data['project_type_id'])) {
            return (int) $data['project_type_id'];
        }

        $legacy = trim((string) ($data['project_type'] ?? ''));

        if ($legacy === '') {
            return null;
        }

        return ProjectType::whereRaw('lower(name) = ?', [strtolower($legacy)])
            ->value('project_type_id');
    }

    public function store(StoreProjectRequest $request)
    {
        Gate::authorize('create_projects');

        abort_unless(Auth::user()->office_id, 403, 'Your account must belong to an office before creating a project.');

        $project = $this->projectWizardService->handleWizardSave($request);

        return redirect()->route('projects.show', $project)->with('status', 'Project created.');
    }

    public function edit(Project $project)
    {
        $this->authorize('update', $project);
        /** @var User $user */
        $user = Auth::user();

        return view('projects.edit', [
            'project' => $project->load(['budget', 'memberRoles.user', 'memberRoles.role', 'projectManager', 'team.leader', 'offices']),
            'teams' => $this->eligibleTeamsFor($user, $project),
            'projectTypes' => ProjectType::where('is_active', true)->orderBy('name')->get(),
            'statuses' => self::STATUSES,
            'projectManagers' => User::where('status', 'Active')->orderBy('full_name')->get(),
            'offices' => Office::active()->orderBy('office_name')->get(),
            'canEditBudget' => $user->can('manage_budgets'),
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project)
    {
        $this->authorize('update', $project);
        /** @var User $user */
        $user = Auth::user();

        $pmInput = $request->input('project_manager_id') ?? $request->input('project_manager_name');
        $resolvedPmId = $this->projectWizardService->resolveUserId($pmInput, $request->input('team_id') ?? $project->team_id);

        // Server-side office restriction for the project manager.
        if ($resolvedPmId) {
            $pm = User::find($resolvedPmId);
            if ($pm && ! $project->canAssignUser($pm)) {
                return back()->withErrors([
                    'project_manager_id' => "{$pm->full_name} belongs to an office that is not associated with this project.",
                ])->withInput();
            }
        }

        $data = $request->validated();

        // Resolve the submitted type name to a type valid for the (possibly
        // new) primary office: office-scoped types take precedence over
        // global ones. Falls back to the legacy free-text value.
        $primaryOfficeId = $data['primary_office_id'] ?? null;
        $resolvedTypeId = ProjectType::whereRaw('lower(name) = ?', [strtolower($data['project_type'])])
            ->when($primaryOfficeId, fn ($q) => $q->orderByRaw('CASE WHEN office_id = ? THEN 0 ELSE 1 END', [$primaryOfficeId]))
            ->where(function ($q) use ($primaryOfficeId) {
                $q->whereNull('office_id');
                if ($primaryOfficeId) {
                    $q->orWhere('office_id', $primaryOfficeId);
                }
            })
            ->value('project_type_id');

        $project->update([
            'project_name' => $data['project_name'],
            'description' => $data['description'] ?? null,
            'project_type' => $data['project_type'],
            'project_type_id' => $resolvedTypeId ?? $project->project_type_id,
            'team_id' => $data['team_id'],
            'project_manager_id' => $resolvedPmId,
            'status' => $data['status'],
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
        ]);

        // Primary + participating offices on edit (validated IDs only).
        $officePivot = [];
        if ($primaryOfficeId) {
            $officePivot[$primaryOfficeId] = ['participation_type' => 'primary'];
        }
        foreach (collect($data['participating_offices'] ?? [])->unique() as $poId) {
            if ((int) $poId !== (int) $primaryOfficeId) {
                $officePivot[$poId] = ['participation_type' => 'participating'];
            }
        }
        $project->offices()->sync($officePivot);

        if ((int) ($project->getOriginal('primary_office_id') ?? 0) !== (int) ($primaryOfficeId ?? 0)) {
            $project->primary_office_id = $primaryOfficeId;
            $project->save();
            Activity::log('Changed project primary office', 'Project', $project->project_id,
                optional(Office::find($primaryOfficeId))->office_name ?? 'none');
        }

        // Safe Member Synchronization
        if ($request->has('members') && is_array($request->input('members'))) {
            $submittedUserIds = [];

            foreach ($request->input('members') as $memberData) {
                if (! empty($memberData['user_id'])) {
                    $submittedUserIds[] = $memberData['user_id'];

                    ProjectMemberRole::updateOrCreate(
                        [
                            'project_id' => $project->project_id,
                            'user_id' => $memberData['user_id'],
                        ],
                        [
                            'role_id' => $memberData['role_id'] ?? null,
                            'specialty' => $memberData['specialty'] ?? null,
                            'assigned_date' => now()->toDateString(),
                        ]
                    );
                }
            }

            // Only remove members that were explicitly removed from the form list
            if (! empty($submittedUserIds)) {
                $project->memberRoles()->whereNotIn('user_id', $submittedUserIds)->delete();
            }
        }

        // Budget figures sync
        if (isset($data['allocated_amount']) && $project->budget && $user->can('manage_budgets')) {
            $previous = $project->budget->allocated_amount;
            $project->budget->update(['allocated_amount' => $data['allocated_amount']]);
            if ((float) $previous !== (float) $data['allocated_amount']) {
                Activity::log('Updated project budget', 'Project', $project->project_id, "{$project->project_name}: ETB ".number_format($previous).' → ETB '.number_format($data['allocated_amount']));
            }
        }

        Activity::log('Updated project', 'Project', $project->project_id, $project->project_name);

        return redirect()->route('projects.show', $project)->with('status', 'Project updated successfully.');
    }

    public function updateSchedule(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $project->update([
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
        ]);

        Activity::log('Updated project schedule', 'Project', $project->project_id, $project->project_name);

        return back()->with('status', 'Project schedule updated successfully.');
    }

    public function assignTeam(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        /** @var User $user */
        $user = Auth::user();

        $data = $request->validate([
            'team_id' => ['required', 'exists:teams,team_id'],
        ]);

        $team = Team::findOrFail($data['team_id']);

        // Server-side office restriction: the team's office must be one of the
        // project's primary or participating offices.
        $allowedOfficeIds = $this->authorizedOfficeIds($project);
        if ($allowedOfficeIds->isNotEmpty() && ! $allowedOfficeIds->contains((int) $team->office_id)) {
            return back()->withErrors([
                'team_id' => "Team \"{$team->team_name}\" belongs to an office that is not associated with this project.",
            ])->withInput();
        }

        DB::table('project_teams')->insertOrIgnore([
            'project_id' => $project->project_id,
            'team_id' => $team->team_id,
            'assigned_date' => now(),
        ]);

        Activity::log('Assigned team to project', 'Project', $project->project_id, "{$team->team_name} → {$project->project_name}");

        if ($team->team_leader_id && (int) $team->team_leader_id !== (int) $user->user_id) {
            Activity::notify($team->team_leader_id, "Your team ({$team->team_name}) was assigned to project: \"{$project->project_name}\"", 'project');
        }

        return back()->with('status', "Team \"{$team->team_name}\" assigned to project successfully.");
    }

    public function removeTeam(Project $project, Team $team)
    {
        $this->authorize('update', $project);

        DB::table('project_teams')
            ->where('project_id', $project->project_id)
            ->where('team_id', $team->team_id)
            ->delete();

        Activity::log('Removed team from project', 'Project', $project->project_id, "{$team->team_name} removed from {$project->project_name}");

        return back()->with('status', "Team \"{$team->team_name}\" unassigned from this project.");
    }

    public function addMember(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        /** @var User $user */
        $user = Auth::user();

        $input = $request->input('user_id') ?? $request->input('user_name') ?? $request->input('name');
        $resolvedUserId = $this->projectWizardService->resolveUserId($input, $project->team_id);

        if (! $resolvedUserId) {
            return back()->withErrors(['user_id' => 'Please provide a valid member name or select from the list.']);
        }

        // Server-side office restriction: the member's office must be one of
        // the project's primary or participating offices.
        $resolvedUser = User::find($resolvedUserId);
        if ($resolvedUser && ! $project->canAssignUser($resolvedUser)) {
            return back()->withErrors([
                'user_id' => "{$resolvedUser->full_name} belongs to an office that is not associated with this project.",
            ])->withInput();
        }

        $specialty = $request->input('specialty');
        $roleId = $request->input('role_id');

        $existing = $project->memberRoles()->where('user_id', $resolvedUserId)->first();
        if ($existing) {
            $existing->update([
                'role_id' => $roleId ?? $existing->role_id,
                'specialty' => $specialty ?? $existing->specialty,
            ]);
        } else {
            ProjectMemberRole::create([
                'project_id' => $project->project_id,
                'user_id' => $resolvedUserId,
                'role_id' => $roleId ?? null,
                'specialty' => $specialty ?? null,
                'assigned_date' => now()->toDateString(),
            ]);
        }

        $assignedUser = User::find($resolvedUserId);
        $roleLabel = $specialty ?: 'Team Member';
        Activity::log('Added project member', 'Project', $project->project_id, "{$assignedUser->full_name} ({$roleLabel}) → {$project->project_name}");

        if ((int) $resolvedUserId !== (int) $user->user_id) {
            Activity::notify((int) $resolvedUserId, "You have been added to project \"{$project->project_name}\" as {$roleLabel}", 'project');
        }

        return back()->with('status', "{$assignedUser->full_name} assigned as {$roleLabel}.");
    }

    public function storeDeliverable(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'deliverable_name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'due_date' => ['nullable', 'date'],
            'status' => ['nullable', 'in:Pending,Delivered'],
        ]);

        $deliverable = ProjectDeliverable::create([
            'project_id' => $project->project_id,
            'deliverable_name' => $data['deliverable_name'],
            'description' => $data['description'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'status' => $data['status'] ?? 'Pending',
        ]);

        Activity::log('Added project deliverable', 'Project', $project->project_id, "{$deliverable->deliverable_name} on {$project->project_name}");

        return back()->with('status', "Deliverable \"{$deliverable->deliverable_name}\" added successfully.");
    }

    public function toggleDeliverable(Project $project, ProjectDeliverable $deliverable)
    {
        $this->authorize('update', $project);
        abort_unless((int) $deliverable->project_id === (int) $project->project_id, 404);

        $newStatus = $deliverable->status === 'Delivered' ? 'Pending' : 'Delivered';
        $deliverable->update(['status' => $newStatus]);

        Activity::log('Updated deliverable status', 'Project', $project->project_id, "{$deliverable->deliverable_name} → {$newStatus}");

        return back()->with('status', "Deliverable marked as {$newStatus}.");
    }

    public function destroyDeliverable(Project $project, ProjectDeliverable $deliverable)
    {
        $this->authorize('update', $project);
        abort_unless((int) $deliverable->project_id === (int) $project->project_id, 404);

        $name = $deliverable->deliverable_name;
        $deliverable->delete();

        Activity::log('Deleted project deliverable', 'Project', $project->project_id, "{$name} removed from {$project->project_name}");

        return back()->with('status', "Deliverable \"{$name}\" removed.");
    }

    public function updateMember(Request $request, Project $project, ProjectMemberRole $memberRole)
    {
        $this->authorize('update', $project);
        abort_unless((int) $memberRole->project_id === (int) $project->project_id, 404);

        $data = $request->validate([
            'specialty' => ['nullable', 'string', 'max:100'],
            'role_id' => ['nullable', 'exists:roles,role_id'],
        ]);

        $memberRole->update([
            'specialty' => $data['specialty'] ?? null,
            'role_id' => $data['role_id'] ?? null,
        ]);

        $userName = optional($memberRole->user)->full_name ?? 'Member';
        $specialty = $data['specialty'] ?: 'Member';
        Activity::log('Updated project member role', 'Project', $project->project_id, "{$userName} role updated to {$specialty}");

        return back()->with('status', "Role updated for {$userName}.");
    }

    public function removeMember(Project $project, ProjectMemberRole $memberRole)
    {
        $this->authorize('update', $project);
        abort_unless((int) $memberRole->project_id === (int) $project->project_id, 404);

        $userName = optional($memberRole->user)->full_name ?? 'A member';
        $memberRole->delete();

        Activity::log('Removed project member', 'Project', $project->project_id, "{$userName} removed from {$project->project_name}");

        return back()->with('status', "{$userName} was removed from this project roster.");
    }

    public function destroy(Project $project)
    {
        $this->authorize('delete', $project);

        $name = $project->project_name;
        Activity::log('Deleted project', 'Project', $project->project_id, $name);
        $project->delete();

        return redirect()->route('projects.index')->with('status', "\"{$name}\" was deleted.");
    }

    public function storeChangeRequest(Request $request, Project $project)
    {
        Gate::authorize('view', $project);

        $data = $request->validate([
            'description' => ['required', 'string', 'max:1000'],
        ]);

        $cr = ChangeRequest::create([
            'project_id' => $project->project_id,
            'requested_by' => Auth::id(),
            'description' => $data['description'],
            'status' => 'Pending',
            'requested_date' => now(),
        ]);

        Activity::log('Created change request', 'ChangeRequest', $cr->change_request_id, $data['description']);

        if (optional($project->team)->team_leader_id) {
            Activity::notify($project->team->team_leader_id, Auth::user()->full_name." filed a change request on \"{$project->project_name}\"", 'approval');
        }

        return back()->with('status', 'Change request submitted.');
    }

    private function resolveUserId(string|int|null $input, ?int $teamId = null): ?int
    {
        return $this->projectWizardService->resolveUserId($input, $teamId);
    }

    /**
     * Teams this user is allowed to create/assign a project under: any team
     * for a Director/Admin (or anyone editing a project they already manage),
     * otherwise only teams they actually lead.
     */
    private function eligibleTeamsFor(User $user, ?Project $editingProject = null): Collection
    {
        $query = Team::orderBy('team_name');

        if (! $user->canAccessGlobalScope()) {
            $officeIds = $user->officeScopeIds();
            $query->whereIn('office_id', $officeIds->all());
        }

        if (! $user->isDirectorOrAdmin()) {
            $query->where('team_leader_id', $user->user_id);
        }

        $teams = $query->get();

        // Restrict to the project's primary or participating offices.
        if ($editingProject) {
            $allowedOfficeIds = $this->authorizedOfficeIds($editingProject);
            if ($allowedOfficeIds->isNotEmpty()) {
                $teams = $teams->filter(fn ($t) => $allowedOfficeIds->contains((int) $t->office_id))->values();
            }

            // Keep the currently assigned team selectable even if the offices changed.
            if ($editingProject->team && ! $teams->contains('team_id', $editingProject->team_id)) {
                $teams->push($editingProject->team);
            }
        }

        return $teams;
    }

    /**
     * Offices a project may draw teams from: primary + participating.
     *
     * @return Collection<int, int>
     */
    private function authorizedOfficeIds(Project $project): Collection
    {
        return $project->authorizedOfficeIds();
    }
}
