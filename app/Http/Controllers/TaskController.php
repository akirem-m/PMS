<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTaskCommentRequest;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Models\Attachment;
use App\Models\Payment;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskComment;
use App\Models\TaskProgressLog;
use App\Models\Team;
use App\Models\User;
use App\Services\MentionService;
use App\Services\RbacService;
use App\Services\TaskBudgetAllocationService;
use App\Services\UserResolver;
use App\Support\Activity;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TaskController extends Controller
{
    public const STATUSES = ['To Do', 'In Progress', 'In Review', 'Completed', 'Blocked', 'Pending', 'Done'];

    // Tasks list — supporting "My Tasks" (default) and "All Tasks", Kanban & List views
    public function index(Request $request)
    {
        $user = Auth::user();
        $filter = $request->get('filter', 'mine');
        $view = $request->get('view', 'kanban'); // 'kanban' or 'list'
        $status = $request->get('status');
        $priority = $request->get('priority');
        $search = $request->get('q');
        $projectId = $request->get('project');
        $teamId = $request->get('team');
        $assigneeId = $request->get('assignee');
        $dueDate = $request->get('due_date');

        $query = Task::with(['project', 'team', 'phase.project', 'assignee', 'comments', 'attachments', 'subtasks', 'assignments.user'])->orderBy('end_date');

        // Scoping: "mine" shows assigned tasks; "all" shows tasks of projects
        // the user participates in (plus anything assigned to them).
        if ($filter === 'mine' || ! $user->can('view_projects')) {
            $query->where(function ($q) use ($user) {
                $q->where('assigned_to', $user->user_id)
                    ->orWhereHas('assignments', fn ($aq) => $aq->where('user_id', $user->user_id));
            });
        } elseif ($filter === 'all') {
            $query->where(function ($q) use ($user) {
                $q->where('assigned_to', $user->user_id)
                    ->orWhereHas('assignments', fn ($aq) => $aq->where('user_id', $user->user_id))
                    ->orWhereHas('project', fn ($pq) => $pq->visibleTo($user))
                    ->orWhereHas('phase.project', fn ($pq) => $pq->visibleTo($user));
            });
        }

        if ($status) {
            if ($status === 'To Do') {
                $query->whereIn('status', ['To Do', 'Pending', 'Not started']);
            } elseif ($status === 'Completed') {
                $query->whereIn('status', ['Completed', 'Done']);
            } else {
                $query->where('status', $status);
            }
        }

        if ($priority) {
            $query->where('priority', $priority);
        }

        if ($search) {
            $query->where('task_name', 'like', "%{$search}%");
        }

        if ($projectId) {
            $query->where(function ($q) use ($projectId) {
                $q->where('project_id', $projectId)
                    ->orWhereHas('phase', fn ($pq) => $pq->where('project_id', $projectId));
            });
        }

        if ($teamId) {
            $query->where('team_id', $teamId);
        }

        if ($assigneeId && $filter === 'all') {
            $query->where('assigned_to', $assigneeId);
        }

        if ($dueDate) {
            $query->whereDate('end_date', $dueDate);
        }

        $tasks = $query->get();

        // Status counts computed in SQL (single GROUP BY) instead of counting
        // the loaded collection in PHP — keeps memory flat for large tables.
        $countQuery = Task::query();
        if ($filter === 'mine' || ! $user->can('view_projects')) {
            $countQuery->where(function ($q) use ($user) {
                $q->where('assigned_to', $user->user_id)
                    ->orWhereHas('assignments', fn ($aq) => $aq->where('user_id', $user->user_id));
            });
        } elseif ($filter === 'all') {
            $countQuery->where(function ($q) use ($user) {
                $q->where('assigned_to', $user->user_id)
                    ->orWhereHas('assignments', fn ($aq) => $aq->where('user_id', $user->user_id))
                    ->orWhereHas('project', fn ($pq) => $pq->visibleTo($user))
                    ->orWhereHas('phase.project', fn ($pq) => $pq->visibleTo($user));
            });
        }
        $statusCounts = $countQuery->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $kanbanCounts = [
            'todo' => ($statusCounts['To Do'] ?? 0) + ($statusCounts['Pending'] ?? 0) + ($statusCounts['Not started'] ?? 0),
            'in-progress' => $statusCounts['In Progress'] ?? 0,
            'in-review' => $statusCounts['In Review'] ?? 0,
            'completed' => ($statusCounts['Completed'] ?? 0) + ($statusCounts['Done'] ?? 0),
            'blocked' => $statusCounts['Blocked'] ?? 0,
        ];

        $myCount = Task::where(function ($q) use ($user) {
            $q->where('assigned_to', $user->user_id)
                ->orWhereHas('assignments', fn ($aq) => $aq->where('user_id', $user->user_id));
        })->count();
        $allCount = (clone $countQuery)->count();

        $projects = Project::query()
            ->visibleTo($user)
            ->with('phases')
            ->orderBy('project_name')
            ->get();
        $teams = Team::query()
            ->where('status', 'Active')
            ->visibleTo($user)
            ->orderBy('team_name')
            ->get();
        $assignableUsers = User::availableTo($user)->where('status', 'Active')->orderBy('full_name')->get();

        return view('tasks.index', compact(
            'tasks', 'filter', 'view', 'status', 'priority', 'search',
            'projectId', 'teamId', 'assigneeId', 'dueDate',
            'myCount', 'allCount', 'projects', 'teams', 'assignableUsers', 'kanbanCounts'
        ));
    }

    public function show(Task $task)
    {
        $task->load([
            'project.teams', 'team.members.user', 'subtasks', 'comments.user',
            'attachments.uploader', 'dependencies', 'assignee', 'phase.project.team', 'progressLogs.user',
            'assignments.user', 'payments',
        ]);

        $this->authorize('view', $task);

        /** @var User $user */
        $user = Auth::user();
        $project = $task->project ?? optional($task->phase)->project;
        $this->loadTaskTree($task);

        $canManage = ($project && $project->isManagedBy($user)) || $user->can('create_tasks');

        // Assignment decision for the signed-in user, read from the task_user
        // pivot (task_assignments). Every acceptance UI decision — the response
        // banner, the collaborator badge and the status control — keys off this
        // one value so they can no longer disagree.
        $myAssignment = $task->assignmentFor((int) $user->user_id);
        $myAssignmentStatus = $myAssignment?->statusSlug();
        $isAcceptedAssignee = $myAssignmentStatus === 'accepted';
        $isRejectedAssignee = $myAssignmentStatus === 'rejected';

        // A user who still owes an accept/reject response must respond before
        // they may move the task status. Managers who can override locked
        // terms are exempt.
        $acceptanceBlocked = $myAssignmentStatus === 'pending'
            && ! $user->can('modifyLocked', $task);

        $canUpdateStatus = ! $acceptanceBlocked
            && ($canManage
                || $isAcceptedAssignee
                || ((int) $task->assigned_to === (int) $user->user_id && ! $isRejectedAssignee));

        // Assigned members drive the four delivery statuses; managers may also
        // park work as Blocked.
        $assigneeStatuses = ['To Do', 'In Progress', 'In Review', 'Completed'];
        $statuses = $canManage ? [...$assigneeStatuses, 'Blocked'] : $assigneeStatuses;

        $canLogExpense = (bool) ($project
            && ($canManage || $isAcceptedAssignee || (int) $task->assigned_to === (int) $user->user_id)
            && $user->can('create', [Payment::class, $project]));

        $assignableUsers = $project ? $project->getAssignableUsersWithRoles($user) : collect();

        $phases = [];
        if ($project) {
            $phases = $project->phases->map(fn ($ph) => ['id' => $ph->phase_id, 'name' => $ph->phase_name])->values();
        }

        return response()->json([
            'id' => $task->task_id,
            'name' => $task->task_name,
            'status' => $task->status,
            'statuses' => $statuses,
            'priority' => $task->priority,
            'progress' => $task->progress ?: (in_array($task->status, ['Done', 'Completed']) ? 100 : 0),
            'budget' => (float) ($task->budget ?: 0),
            'budget_formatted' => number_format((float) ($task->budget ?: 0)),
            'assignee' => optional($task->assignee)->full_name,
            'assignee_name' => optional($task->assignee)->full_name,
            'assignee_id' => $task->assigned_to,
            'team_id' => $task->team_id,
            'team_name' => optional($task->team)->team_name,
            'phase_id' => $task->phase_id,
            'phase' => optional($task->phase)->phase_name,
            'phase_budget' => $task->phase ? [
                'allocated' => (float) ($task->phase->budget?->allocated_amount ?? 0),
                'spent' => (float) ($task->phase->budget?->spent_amount ?? 0),
                'task_allocated' => $task->phase->allocatedTaskAmount(),
                'remaining' => $task->phase->remainingTaskBudget(),
                'expense_remaining' => $task->phase->remainingExpenseBudget(),
            ] : null,
            'phases' => $phases,
            'project_id' => $project ? $project->project_id : null,
            'project' => $project ? $project->project_name : null,
            'project_url' => $project ? route('projects.show', $project) : '#',
            'start_date' => optional($task->start_date)?->format('Y-m-d'),
            'end_date' => optional($task->end_date)?->format('Y-m-d'),
            'start_date_formatted' => optional($task->start_date)?->format('d M Y'),
            'end_date_formatted' => optional($task->end_date)?->format('d M Y'),
            'due' => optional($task->end_date)?->format('d M Y'),
            'is_overdue' => $task->isOverdue(),
            'blocker_reason' => $task->blocker_reason,
            'description' => $task->description,
            'is_locked' => (bool) $task->is_locked,
            'agreement_locked' => $task->isLocked(),
            'locked_at' => optional($task->locked_at)?->format('d M Y H:i'),
            'lock_reason' => $task->lock_reason,
            'can_modify_locked' => $user->can('modifyLocked', $task),
            'assignees' => $task->assignments->map(fn (TaskAssignment $assignment) => [
                'id' => $assignment->task_assignment_id,
                'user_id' => $assignment->user_id,
                'name' => optional($assignment->user)->full_name ?? 'Unassigned',
                'role_label' => $assignment->role_label ?: 'Assignee',
                'acceptance_status' => $assignment->acceptance_status,
                'status_slug' => $assignment->statusSlug(),
                'rejection_reason' => $assignment->rejection_reason,
                'is_current_user' => (int) $assignment->user_id === (int) $user->user_id,
                'can_respond' => (int) $assignment->user_id === (int) $user->user_id,
                'responded_at' => optional($assignment->responded_at)?->diffForHumans(),
            ])->values(),
            'can_accept' => $user->can('accept', $task),
            'can_reject' => $user->can('reject', $task),
            'total_cost' => $task->totalCost(),
            'can_update_status' => $canUpdateStatus,
            'can_manage' => $canManage,
            'can_log_expense' => $canLogExpense,
            'can_lock' => (bool) ($project && $project->isManagedBy($user)),
            'assignable_users' => $assignableUsers,
            'my_assignment' => $myAssignment ? [
                'id' => $myAssignment->task_assignment_id,
                'status' => $myAssignmentStatus,
                'acceptance_status' => $myAssignment->acceptance_status,
                'role_label' => $myAssignment->role_label,
                'rejection_reason' => $myAssignment->rejection_reason,
                'can_respond' => $myAssignmentStatus === 'pending',
            ] : null,
            'requires_acceptance' => $task->assignments->isNotEmpty(),
            'acceptance_blocked' => $acceptanceBlocked,
            'subtasks' => $this->serializeTaskTree($task->subtasks),
            'attachments' => $task->attachments->map(fn ($a) => [
                'id' => $a->attachment_id,
                'file_name' => $a->file_name,
                'file_url' => Storage::url($a->file_path),
                'uploader' => optional($a->uploader)->full_name ?? 'User',
                'uploaded_at' => optional($a->uploaded_at)->diffForHumans() ?? 'Recently',
            ]),
            'comments' => $task->comments->map(fn ($c) => [
                'id' => $c->comment_id,
                'user' => optional($c->user)->full_name,
                'avatar' => optional($c->user)->avatar,
                'text' => $c->comment_text,
                'at' => $c->created_at?->diffForHumans() ?? 'Just now',
            ]),
            'activity_logs' => $task->progressLogs->map(fn ($l) => [
                'user' => optional($l->user)->full_name ?? 'User',
                'from' => $l->previous_status,
                'to' => $l->new_status,
                'remarks' => $l->remarks,
                'at' => $l->changed_at ? Carbon::parse($l->changed_at)->diffForHumans() : '',
            ]),
        ]);
    }

    public function store(StoreTaskRequest $request)
    {
        $user = Auth::user();
        abort_unless($user->can('create_tasks'), 403);

        $phase = null;
        $project = null;

        if ($request->filled('phase_id')) {
            $phase = Phase::with('project')->find($request->input('phase_id'));
            if ($phase) {
                $project = $phase->project;
            }
        } elseif ($request->filled('project_id')) {
            // A task submitted against a project but with no phase stays
            // phase-less: it is the caller's decision which phase (if any)
            // owns the work, and quietly filing it under the project's first
            // phase would both hide it there and charge that phase's budget.
            $project = Project::find($request->input('project_id'));
        }

        $canManage = $project ? ($project->isManagedBy($user) || $user->can('create_tasks')) : $user->can('create_tasks');
        abort_unless($canManage, 403);

        $assigneeInput = $request->input('assigned_to') ?? $request->input('assignee_name') ?? $request->input('assignee_input');
        $resolvedAssigneeId = $this->resolveAssigneeId($assigneeInput, $project);
        $this->assertAssigneeAllowed($project, $resolvedAssigneeId);

        $data = $request->validated();

        // Budget accountability follows the container that owns the task: a
        // phase budget for phased work, the project budget for project-level
        // work that names no phase.
        if ($phase) {
            app(TaskBudgetAllocationService::class)->assertAllocationAllowed(
                $phase,
                $data['budget'] ?? 0
            );
        } elseif ($project) {
            app(TaskBudgetAllocationService::class)->assertProjectAllocationAllowed(
                $project,
                $data['budget'] ?? 0
            );
        }

        $status = $data['status'] ?? 'To Do';
        $taskProjectId = $project ? $project->project_id : ($data['project_id'] ?? null);
        $taskTeamId = $data['team_id'] ?? ($project ? $project->team_id : null);

        $task = Task::create([
            'project_id' => $taskProjectId,
            'phase_id' => $phase ? $phase->phase_id : ($data['phase_id'] ?? null),
            'team_id' => $taskTeamId,
            'task_name' => $data['task_name'],
            'description' => $data['description'] ?? null,
            'assigned_to' => $resolvedAssigneeId,
            'priority' => $data['priority'],
            'status' => $status,
            'budget' => $data['budget'] ?? 0,
            'progress' => in_array($status, ['Done', 'Completed']) ? 100 : ($data['progress'] ?? 0),
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
        ]);

        if ($resolvedAssigneeId) {
            // syncAssignments already creates the pivot row with a Pending
            // Acceptance default; only the primary role label is stamped here.
            $this->syncAssignments($task, [$resolvedAssigneeId], (int) $user->user_id);
            $task->assignments()
                ->where('user_id', $resolvedAssigneeId)
                ->update(['role_label' => 'Primary Assignee']);
        }

        if ($project) {
            $project->recalculateProgress();
        }

        Activity::log('Created task', 'Task', $task->task_id, $task->task_name);

        if ($task->assigned_to && (int) $task->assigned_to !== (int) $user->user_id) {
            $projName = $project ? " on {$project->project_name}" : '';
            Activity::notify($task->assigned_to, $user->full_name." assigned you a new task: \"{$task->task_name}\"{$projName}", 'task');
        }

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Task added successfully.',
                'task' => $task->load(['assignee', 'phase.project', 'project', 'team']),
            ], 201);
        }

        return back()->with('status', 'Task added successfully.');
    }

    public function updateStatus(Request $request, Task $task)
    {
        $task->load('phase.project.team', 'project', 'assignments');
        $project = $task->project ?? optional($task->phase)->project;
        $user = Auth::user();

        $assignment = $task->assignmentFor((int) $user->user_id);
        $assignmentStatus = $assignment?->statusSlug();
        $isManager = ($project && $project->isManagedBy($user)) || $user->isDirectorOrAdmin();

        // Agreement gate: an assignee who has not answered the assignment
        // request cannot drive the task forward until they accept or reject.
        // This replaces the old blanket "task is locked" abort — the lock now
        // only protects the agreed terms (name/budget/dates/assignees), so an
        // accepted member can still move To Do → In Progress → In Review →
        // Completed on a locked task.
        abort_if(
            $assignmentStatus === 'pending' && ! $user->can('modifyLocked', $task),
            422,
            'Accept or reject your task assignment before changing its status.'
        );

        $this->authorize('updateStatus', $task);

        $canUpdateTaskStatus = $project
            ? app(RbacService::class)->can($user, 'update_task_status', $project)
            : $user->hasPermission('update_task_status');
        $isPrimaryAssignee = (int) $task->assigned_to === (int) $user->user_id;

        abort_unless(
            $canUpdateTaskStatus
            && ($isManager
                || $assignmentStatus === 'accepted'
                || ($isPrimaryAssignee && $assignmentStatus !== 'rejected')),
            403
        );

        // Assigned members move between the four delivery statuses; managers
        // additionally keep the legacy statuses (Blocked, Pending, Done).
        $allowedStatuses = $isManager
            ? self::STATUSES
            : ['To Do', 'In Progress', 'In Review', 'Completed'];

        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', $allowedStatuses)],
            'blocker_reason' => ['nullable', 'string', 'max:1000'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $previous = $task->status;
        $newStatus = $data['status'];
        $progress = in_array($newStatus, ['Done', 'Completed']) ? 100 : ($newStatus === 'To Do' ? 0 : $task->progress);
        $blockerReason = $newStatus === 'Blocked' ? ($data['blocker_reason'] ?? $task->blocker_reason) : null;

        $task->update([
            'status' => $newStatus,
            'progress' => $progress,
            'blocker_reason' => $blockerReason,
        ]);

        $logRemarks = $data['remarks'] ?? ($newStatus === 'Blocked' ? "Blocker: {$blockerReason}" : ($previous === 'Blocked' ? 'Blocker resolved' : null));

        TaskProgressLog::create([
            'task_id' => $task->task_id,
            'user_id' => $user->user_id,
            'previous_status' => $previous,
            'new_status' => $newStatus,
            'remarks' => $logRemarks,
        ]);

        if ($project) {
            $project->recalculateProgress();
        }

        Activity::log('Updated task status', 'Task', $task->task_id, "{$previous} → {$newStatus} ({$task->task_name})".($blockerReason ? " [Blocker: {$blockerReason}]" : ''));

        // Notification routing: notify assignee, team lead, and PM on blockers
        if ($task->assigned_to && (int) $task->assigned_to !== (int) $user->user_id) {
            Activity::notify((int) $task->assigned_to, "\"{$task->task_name}\" was moved to {$newStatus} by ".$user->full_name, 'task');
        }

        if ($newStatus === 'Blocked') {
            if ($project && $project->project_manager_id && (int) $project->project_manager_id !== (int) $user->user_id) {
                Activity::notify((int) $project->project_manager_id, "Task \"{$task->task_name}\" on {$project->project_name} was marked as Blocked".($blockerReason ? ": {$blockerReason}" : ''), 'task');
            }
            if ($task->team && $task->team->team_leader_id && (int) $task->team->team_leader_id !== (int) $user->user_id) {
                Activity::notify((int) $task->team->team_leader_id, "Team task \"{$task->task_name}\" was marked as Blocked".($blockerReason ? ": {$blockerReason}" : ''), 'task');
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Status updated',
            'status' => $task->status,
            'progress' => $task->progress,
            'blocker_reason' => $task->blocker_reason,
            'project_progress' => $project ? $project->fresh()->progressPercentage() : null,
        ]);
    }

    public function assign(Request $request, Task $task)
    {
        $task->load('phase.project', 'project');
        $project = $task->project ?? optional($task->phase)->project;
        $user = Auth::user();

        $this->abortIfLocked($task);

        $canAssignTask = $project
            ? app(RbacService::class)->can($user, 'assign_tasks', $project)
            : $user->hasPermission('assign_tasks');
        abort_unless($canAssignTask && (($project && $project->isManagedBy($user)) || $user->isDirectorOrAdmin()), 403);

        $assigneeInputs = $request->input('assignees', $request->input('assigned_to') ?? $request->input('assignee_name'));
        $assigneeInputs = is_array($assigneeInputs) ? $assigneeInputs : [$assigneeInputs];
        $reason = $request->input('reason');
        $resolvedAssigneeIds = collect($assigneeInputs)
            ->map(fn ($input) => $this->resolveAssigneeId($input, $project))
            ->filter()
            ->unique()
            ->values();
        foreach ($resolvedAssigneeIds as $resolvedAssigneeId) {
            $this->assertAssigneeAllowed($project, $resolvedAssigneeId);
        }

        $previousAssignee = optional($task->assignee)->full_name ?? 'Unassigned';
        $primaryAssigneeId = $resolvedAssigneeIds->first();
        $task->update(['assigned_to' => $primaryAssigneeId]);
        $this->syncAssignments($task, $resolvedAssigneeIds->all(), (int) $user->user_id);

        $assigneeName = optional($task->fresh()->assignee)->full_name ?? 'Unassigned';
        $remarks = "Reassigned from {$previousAssignee} to {$assigneeName}".($reason ? " (Reason: {$reason})" : '');

        TaskProgressLog::create([
            'task_id' => $task->task_id,
            'user_id' => $user->user_id,
            'previous_status' => $task->status,
            'new_status' => $task->status,
            'remarks' => $remarks,
        ]);

        Activity::log('Reassigned task', 'Task', $task->task_id, "{$task->task_name} → {$assigneeName}".($reason ? " (Reason: {$reason})" : ''));

        foreach ($resolvedAssigneeIds as $resolvedAssigneeId) {
            if ((int) $resolvedAssigneeId !== (int) $user->user_id) {
                Activity::notify((int) $resolvedAssigneeId, $user->full_name." assigned you \"{$task->task_name}\"".($reason ? " (Reason: {$reason})" : ''), 'task');
            }
        }

        $freshTask = $task->fresh(['assignments.user']);

        return response()->json([
            'assignee_id' => $task->assigned_to,
            'assignee' => $assigneeName,
            'assignees' => $this->serializeAssignees($freshTask, (int) $user->user_id),
        ]);
    }

    public function respondToAssignment(Request $request, TaskAssignment $assignment)
    {
        $user = Auth::user();
        abort_unless((int) $assignment->user_id === (int) $user->user_id, 403);

        $task = $assignment->task()->firstOrFail();

        abort_if(
            $task->is_locked && ! $user->can('modifyLocked', $task),
            422,
            'This task is already locked.'
        );

        $data = $request->validate([
            'status' => ['required', 'in:accepted,rejected'],
            'rejection_reason' => ['nullable', 'string', 'max:1000'],
            // `response_reason` is kept as a legacy alias for API clients that
            // predate the unified acceptance vocabulary.
            'response_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $accepted = $data['status'] === 'accepted';
        $reason = $data['rejection_reason'] ?? $data['response_reason'] ?? null;

        if (! $accepted) {
            abort_if(blank($reason), 422, 'A reason is required when rejecting a task assignment.');
        }

        $this->applyAssignmentDecision($task, $user, $accepted, $reason);

        Activity::log(
            ($accepted ? 'Accepted' : 'Rejected').' task assignment',
            'Task',
            $assignment->task_id,
            $task->task_name.($accepted ? '' : " — {$reason}")
        );

        $this->notifyAssigner(
            $task,
            $assignment,
            $accepted
                ? "{$user->full_name} accepted the assignment for \"{$task->task_name}\"."
                : "{$user->full_name} rejected task \"{$task->task_name}\": {$reason}"
        );

        $freshTask = $task->fresh(['assignments.user']);

        return response()->json([
            'status' => $assignment->fresh()->statusSlug(),
            'message' => $accepted ? 'Assignment accepted.' : 'Assignment rejected.',
            'task_status' => $freshTask->status,
        ] + $this->acceptancePayload($freshTask, $user));
    }

    public function lock(Task $task)
    {
        $task->load('project', 'phase.project');
        $project = $task->project ?? optional($task->phase)->project;
        abort_unless($project && $project->isManagedBy(Auth::user()), 403);
        abort_if($task->is_locked, 422, 'This task is already locked.');

        $task->update(['is_locked' => true, 'locked_at' => now(), 'locked_by' => Auth::id()]);

        return response()->json(['locked' => true]);
    }

    public function unlock(Task $task)
    {
        $task->load('project', 'phase.project');
        $project = $task->project ?? optional($task->phase)->project;
        abort_unless($project && $project->isManagedBy(Auth::user()), 403);

        $task->update(['is_locked' => false, 'locked_at' => null, 'locked_by' => null]);

        return response()->json(['locked' => false]);
    }

    public function update(UpdateTaskRequest $request, Task $task)
    {
        $task->load('phase.project', 'project', 'assignments');
        $project = $task->project ?? optional($task->phase)->project;
        $user = Auth::user();

        // No blanket lock abort here: the locked-terms guard below is the
        // single decision point, and it honours the administrative override
        // via modifyLocked. Blocking earlier made that override unreachable.
        $canManage = $project ? $project->isManagedBy($user) : false;
        $isAssignee = (int) $task->assigned_to === (int) $user->user_id;

        abort_unless($user->can('create_tasks') || $canManage || $isAssignee || $user->isDirectorOrAdmin(), 403);

        $data = $request->validated();

        // Check if task is locked and non-authorized user tries to modify locked agreed fields
        if ($task->isLocked()) {
            $isModifyingLocked = false;
            $modifiedTerms = [];
            foreach (['task_name', 'description', 'budget', 'start_date', 'end_date'] as $field) {
                if ($request->has($field)) {
                    $oldVal = trim((string) $task->$field);
                    $newVal = trim((string) $request->input($field));
                    if ($oldVal !== $newVal && ! ($oldVal === '0' && $newVal === '') && ! ($oldVal === '' && $newVal === '0')) {
                        $isModifyingLocked = true;
                        $modifiedTerms[] = "{$field}: '{$oldVal}' → '{$newVal}'";
                    }
                }
            }

            if ($request->has('assigned_to')) {
                $newAssignee = $this->resolveAssigneeId($request->input('assigned_to'), $project);
                if ((int) $task->assigned_to !== (int) $newAssignee) {
                    $isModifyingLocked = true;
                    $modifiedTerms[] = 'assigned user changed';
                }
            }

            if ($isModifyingLocked) {
                if (! $user->can('modifyLocked', $task)) {
                    $lockMsg = 'This task is locked after acceptance. Agreed details (terms, budget, timeline, assignees) cannot be modified without administrative authorization.';
                    if ($request->wantsJson()) {
                        return response()->json(['error' => $lockMsg, 'message' => $lockMsg], 422);
                    }

                    return back()->withErrors(['locked' => $lockMsg]);
                }

                Activity::log('Admin override on locked task terms', 'Task', $task->task_id, "Locked task '{$task->task_name}' terms modified by {$user->full_name}: ".implode(', ', $modifiedTerms));
            }
        }

        if ($request->has('assigned_to') || $request->has('assignee_name')) {
            $assigneeInput = $request->input('assigned_to') ?? $request->input('assignee_name');
            $data['assigned_to'] = $this->resolveAssigneeId($assigneeInput, $project);
            $this->assertAssigneeAllowed($project, $data['assigned_to']);
            $this->syncAssignments($task, $data['assigned_to'] ? [$data['assigned_to']] : [], (int) $user->user_id);
        }

        $targetPhase = array_key_exists('phase_id', $data)
            ? Phase::with('budget')->find($data['phase_id'])
            : $task->phase;
        if ($targetPhase) {
            app(TaskBudgetAllocationService::class)->assertAllocationAllowed(
                $targetPhase,
                $data['budget'] ?? $task->budget,
                (int) $targetPhase->phase_id === (int) $task->phase_id ? $task->task_id : null
            );
        } elseif (array_key_exists('budget', $data) && (float) $data['budget'] > 0) {
            $budgetProject = $task->project ?? optional($task->phase)->project;
            abort_unless($budgetProject, 422, 'A task allocation requires a phase.');

            app(TaskBudgetAllocationService::class)->assertProjectAllocationAllowed(
                $budgetProject,
                $data['budget'],
                $task->task_id
            );
        }

        if ($request->has('assignees')) {
            $assigneeIds = collect((array) $request->input('assignees'))
                ->map(fn ($input) => $this->resolveAssigneeId($input, $project))
                ->filter()->unique()->values()->all();
            foreach ($assigneeIds as $assigneeId) {
                $this->assertAssigneeAllowed($project, $assigneeId);
            }
            $data['assigned_to'] = $assigneeIds[0] ?? null;
            $this->syncAssignments($task, $assigneeIds, (int) $user->user_id);
        }

        // A status change smuggled through the generic update endpoint must
        // satisfy exactly the same acceptance gate and role whitelist as the
        // dedicated /tasks/{task}/status endpoint.
        if (isset($data['status']) && $data['status'] !== $task->status) {
            $assignmentStatus = $task->assignmentFor((int) $user->user_id)?->statusSlug();

            abort_if(
                $assignmentStatus === 'pending' && ! $user->can('modifyLocked', $task),
                422,
                'Accept or reject your task assignment before changing its status.'
            );

            abort_unless(
                $canManage
                || $user->isDirectorOrAdmin()
                || $assignmentStatus === 'accepted'
                || (int) $task->assigned_to === (int) $user->user_id,
                403,
                "You don't have permission to change this task's status."
            );

            $allowedStatuses = ($canManage || $user->isDirectorOrAdmin())
                ? self::STATUSES
                : ['To Do', 'In Progress', 'In Review', 'Completed'];

            abort_unless(in_array($data['status'], $allowedStatuses, true), 422, 'That status transition is not available to you.');
        }

        if (isset($data['status'])) {
            if (in_array($data['status'], ['Done', 'Completed'])) {
                $data['progress'] = 100;
            } elseif ($data['status'] === 'To Do' && ! isset($data['progress'])) {
                $data['progress'] = 0;
            }
        }

        $task->update($data);

        if ($project) {
            $project->recalculateProgress();
        }

        Activity::log('Updated task', 'Task', $task->task_id, $task->task_name);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Task updated successfully',
                'task' => $task->fresh(['assignee', 'phase.project', 'project', 'team']),
            ]);
        }

        return back()->with('status', 'Task updated successfully.');
    }

    public function addComment(StoreTaskCommentRequest $request, Task $task)
    {
        $data = $request->validated();

        $user = Auth::user();

        $comment = TaskComment::create([
            'task_id' => $task->task_id,
            'user_id' => $user->user_id,
            'comment_text' => $data['comment_text'],
        ]);

        Activity::log('Commented on task', 'Task', $task->task_id);

        if ($task->assigned_to && (int) $task->assigned_to !== (int) $user->user_id) {
            Activity::notify((int) $task->assigned_to, $user->full_name." commented on \"{$task->task_name}\"", 'mention');
        }

        // @mention parsing: notify every active user tagged in the comment
        // body (never the author) with a deep link back to the task.
        $mentioned = app(MentionService::class)
            ->extractMentionedUsers($comment->comment_text, (int) $user->user_id);
        app(MentionService::class)
            ->notifyMentionedUsers($task, $mentioned, $comment->comment_text);

        return response()->json([
            'id' => $comment->comment_id,
            'user' => $user->full_name,
            'text' => $comment->comment_text,
            'at' => $comment->created_at?->diffForHumans() ?? 'Just now',
        ]);
    }

    public function uploadAttachment(Request $request, Task $task)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480'], // 20MB max
        ]);

        $user = Auth::user();
        $file = $request->file('file');
        $fileName = $file->getClientOriginalName();
        $path = $file->store('attachments', 'public');

        $attachment = Attachment::create([
            'entity_type' => 'Task',
            'entity_id' => $task->task_id,
            'file_name' => $fileName,
            'file_path' => $path,
            'uploaded_by' => $user->user_id,
        ]);

        Activity::log('Uploaded task attachment', 'Task', $task->task_id, "{$fileName} on {$task->task_name}");

        return response()->json([
            'message' => 'File uploaded successfully',
            'attachment' => [
                'id' => $attachment->attachment_id,
                'file_name' => $attachment->file_name,
                'file_url' => Storage::url($attachment->file_path),
                'uploader' => $user->full_name,
                'uploaded_at' => 'Just now',
            ],
        ], 201);
    }

    public function deleteAttachment(Task $task, Attachment $attachment)
    {
        $user = Auth::user();
        abort_unless((int) $attachment->entity_id === (int) $task->task_id, 404);
        abort_unless((int) $attachment->uploaded_by === (int) $user->user_id || $user->isDirectorOrAdmin(), 403);

        if (Storage::disk('public')->exists($attachment->file_path)) {
            Storage::disk('public')->delete($attachment->file_path);
        }

        $attachment->delete();

        return response()->json(['message' => 'Attachment removed']);
    }

    public function downloadAttachment(Attachment $attachment)
    {
        // IDOR guard: the attachment must belong to a task the current user
        // can view (entity_type 'Task'), or belong to an unknown type and be
        // rejected outright.
        abort_unless($attachment->entity_type === 'Task', 404);

        $task = Task::find((int) $attachment->entity_id);
        abort_unless($task !== null, 404);

        $this->authorize('view', $task);

        abort_unless(Storage::disk('public')->exists($attachment->file_path), 404);

        return Storage::disk('public')->download($attachment->file_path, $attachment->file_name);
    }

    public function accept(Request $request, Task $task)
    {
        $user = Auth::user();
        $this->authorize('accept', $task);

        $assignment = $this->applyAssignmentDecision($task, $user, true, null);

        Activity::log('Accepted task assignment', 'Task', $task->task_id, "{$user->full_name} accepted assignment for task '{$task->task_name}'. Agreed terms locked.");

        $this->notifyAssigner($task, $assignment, "{$user->full_name} accepted the assignment for \"{$task->task_name}\".");

        if ($request->wantsJson()) {
            $freshTask = $task->fresh(['assignments.user']);

            return response()->json([
                'message' => 'Task accepted successfully. Agreed details are now locked.',
                'task_status' => $freshTask->status,
            ] + $this->acceptancePayload($freshTask, $user));
        }

        return back()->with('status', 'Task accepted successfully.');
    }

    public function reject(Request $request, Task $task)
    {
        $user = Auth::user();
        $this->authorize('reject', $task);

        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ]);

        abort_if(
            $task->isLocked()
                && $task->assignmentFor((int) $user->user_id)?->isAccepted()
                && ! $user->can('modifyLocked', $task),
            422,
            'This assignment is already accepted and the agreed terms are locked.'
        );

        $assignment = $this->applyAssignmentDecision($task, $user, false, $data['rejection_reason']);

        Activity::log('Rejected task assignment', 'Task', $task->task_id, "{$user->full_name} rejected assignment for task '{$task->task_name}'. Reason: {$data['rejection_reason']}");

        // The person who assigned the work is always notified; the project
        // manager of record is the fallback when the pivot has no assigner.
        $this->notifyAssigner(
            $task,
            $assignment,
            "{$user->full_name} rejected task \"{$task->task_name}\": {$data['rejection_reason']}"
        );

        if ($request->wantsJson()) {
            $freshTask = $task->fresh(['assignments.user']);

            return response()->json([
                'message' => 'Task assignment rejected.',
                'task_status' => $freshTask->status,
            ] + $this->acceptancePayload($freshTask, $user));
        }

        return back()->with('status', 'Task assignment rejected.');
    }

    public function assignUsers(Request $request, Task $task)
    {
        $user = Auth::user();
        $this->authorize('assign', $task);

        $data = $request->validate([
            'users' => ['required', 'array', 'min:1'],
            'users.*.user_id' => ['required', 'exists:users,user_id'],
            'users.*.role_label' => ['nullable', 'string', 'max:100'],
        ]);

        $project = $task->project ?? optional($task->phase)->project;

        foreach ($data['users'] as $u) {
            $targetUserId = (int) $u['user_id'];
            $this->assertAssigneeAllowed($project, $targetUserId);

            TaskAssignment::firstOrCreate(
                ['task_id' => $task->task_id, 'user_id' => $targetUserId],
                [
                    'role_label' => $u['role_label'] ?? 'Contributor',
                    'acceptance_status' => TaskAssignment::STATUS_PENDING,
                    'assigned_by' => $user->user_id,
                    'assigned_at' => now(),
                ]
            );

            Activity::notify($targetUserId, "You have been assigned to task '{$task->task_name}'. Please review and accept/reject.", 'task');
        }

        if (! $task->assigned_to && ! empty($data['users'])) {
            $task->update(['assigned_to' => (int) $data['users'][0]['user_id']]);
        }

        Activity::log('Assigned users to task', 'Task', $task->task_id, "Users assigned to '{$task->task_name}'");

        if ($request->wantsJson()) {
            $freshTask = $task->fresh(['assignments.user']);

            return response()->json([
                'message' => 'Users assigned successfully.',
                'assignees' => $this->serializeAssignees($freshTask, (int) $user->user_id),
            ]);
        }

        return back()->with('status', 'Users assigned successfully.');
    }

    public function removeAssignee(Request $request, Task $task, User $user)
    {
        $currentUser = Auth::user();

        // The agreement lock protects the assignee list. Checked before the
        // policy so a caller without modifyLocked authority gets an
        // explanation rather than a bare 403; managers holding the override
        // fall through to the normal authorization below.
        if ($task->isLocked() && ! $currentUser->can('modifyLocked', $task)) {
            $msg = 'Task is locked. Assignees cannot be removed without administrative authorization.';
            if ($request->wantsJson()) {
                return response()->json(['error' => $msg], 422);
            }

            return back()->withErrors(['locked' => $msg]);
        }

        $this->authorize('assign', $task);

        $task->assignments()->where('user_id', $user->user_id)->delete();

        if ((int) $task->assigned_to === (int) $user->user_id) {
            $nextAssignee = $task->assignments()->first();
            $task->update(['assigned_to' => $nextAssignee ? $nextAssignee->user_id : null]);
        }

        Activity::log('Removed assignee from task', 'Task', $task->task_id, "User {$user->full_name} removed from task '{$task->task_name}'");

        if ($request->wantsJson()) {
            $freshTask = $task->fresh(['assignments.user']);

            return response()->json([
                'message' => 'Assignee removed successfully.',
                'assignees' => $this->serializeAssignees($freshTask, (int) $currentUser->user_id),
            ]);
        }

        return back()->with('status', 'Assignee removed successfully.');
    }

    public function storeSubtask(Request $request, Task $task)
    {
        $this->authorize('manageSubtasks', $task);
        $this->abortIfLocked($task);

        $data = $request->validate([
            'task_name' => ['required', 'string', 'max:150'],
            'priority' => ['nullable', 'in:Low,Medium,High,Urgent'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'assigned_to' => ['nullable', 'exists:users,user_id'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ]);

        // Subtasks share their parent's phase and draw from the same phase
        // budget, so their allocation is validated against it too.
        if ($task->phase && (float) ($data['budget'] ?? 0) > 0) {
            app(TaskBudgetAllocationService::class)->assertAllocationAllowed(
                $task->phase,
                (float) $data['budget']
            );
        }

        $subtask = Task::create([
            'project_id' => $task->project_id,
            'team_id' => $task->team_id,
            'phase_id' => $task->phase_id,
            'parent_task_id' => $task->task_id,
            'task_name' => $data['task_name'],
            'priority' => $data['priority'] ?? ($task->priority ?: 'Medium'),
            'status' => 'To Do',
            'budget' => $data['budget'] ?? 0,
            'assigned_to' => $data['assigned_to'] ?? null,
            'start_date' => $data['start_date'] ?? now()->toDateString(),
            'end_date' => $data['end_date'] ?? $task->end_date,
        ]);

        if ($subtask->assigned_to) {
            TaskAssignment::firstOrCreate(
                ['task_id' => $subtask->task_id, 'user_id' => $subtask->assigned_to],
                [
                    'role_label' => 'Subtask Assignee',
                    'acceptance_status' => TaskAssignment::STATUS_PENDING,
                    'assigned_by' => Auth::id(),
                    'assigned_at' => now(),
                ]
            );
        }

        return response()->json([
            'id' => $subtask->task_id,
            'name' => $subtask->task_name,
            'status' => $subtask->status,
            'budget' => (float) $subtask->budget,
            'is_completed' => false,
        ], 201);
    }

    public function toggleSubtask(Task $subtask)
    {
        $this->authorizeSubtask($subtask);
        $this->abortIfLocked($subtask);

        $newStatus = in_array($subtask->status, ['Done', 'Completed']) ? 'To Do' : 'Completed';
        $subtask->update(['status' => $newStatus, 'progress' => $newStatus === 'Completed' ? 100 : 0]);

        return response()->json([
            'id' => $subtask->task_id,
            'name' => $subtask->task_name,
            'status' => $subtask->status,
            'is_completed' => in_array($subtask->status, ['Done', 'Completed']),
        ]);
    }

    public function destroySubtask(Request $request, Task $subtask)
    {
        $this->authorizeSubtask($subtask);
        $this->abortIfLocked($subtask);

        $parentId = $subtask->parent_task_id;
        $subtaskName = $subtask->task_name;

        Activity::log('Deleted subtask', 'Task', $subtask->task_id, $subtaskName);

        $subtask->comments()->delete();
        $subtask->progressLogs()->delete();
        $subtask->dependencies()->detach();
        $subtask->dependents()->detach();
        $subtask->assignments()->delete();

        // Grandchildren are preserved at the parent level rather than cascaded.
        $subtask->subtasks()->update(['parent_task_id' => $parentId]);
        $subtask->delete();

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Subtask deleted.',
                'id' => $subtask->task_id,
            ]);
        }

        return back()->with('status', "\"{$subtaskName}\" was deleted.");
    }

    /**
     * Assignee resolution (including placeholder creation for a typed name)
     * lives in UserResolver so task, team and project forms cannot drift.
     */
    private function resolveAssigneeId($input, ?Project $project = null): ?int
    {
        return app(UserResolver::class)->resolve($input, $project?->team_id);
    }

    /**
     * Rejects an assignee whose office is not associated with the task's
     * project (primary or participating). Users without an office keep the
     * legacy behaviour.
     */
    private function assertAssigneeAllowed(?Project $project, ?int $assigneeId): void
    {
        if (! $project || ! $assigneeId) {
            return;
        }

        $assignee = User::find($assigneeId);
        if ($assignee && ! $project->canAssignUser($assignee)) {
            abort(422, "{$assignee->full_name} belongs to an office that is not associated with this project.");
        }
    }

    public function destroy(Request $request, Task $task)
    {
        $task->load('phase.project', 'project');
        $project = $task->project ?? optional($task->phase)->project;
        $user = Auth::user();

        $this->abortIfLocked($task);

        $canManage = ($project && $project->isManagedBy($user)) || $user->can('create_tasks') || $user->isDirectorOrAdmin();
        abort_unless($canManage, 403);

        $taskName = $task->task_name;
        Activity::log('Deleted task', 'Task', $task->task_id, $taskName);

        $task->comments()->delete();
        $task->progressLogs()->delete();
        $task->dependencies()->detach();
        $task->dependents()->detach();
        $task->subtasks()->update(['parent_task_id' => null]);
        $task->delete();

        if ($project) {
            $project->recalculateProgress();
        }

        if ($request->wantsJson()) {
            return response()->json(['message' => 'Task deleted successfully']);
        }

        return back()->with('status', "\"{$taskName}\" was deleted.");
    }

    private function abortIfLocked(Task $task): void
    {
        abort_if($task->is_locked, 422, 'This task is locked and cannot be edited.');
    }

    private function syncAssignments(Task $task, array $userIds, ?int $assignedBy = null): void
    {
        $userIds = collect($userIds)->map(fn ($id) => (int) $id)->filter()->unique();
        $assignedBy ??= Auth::id();

        $task->assignments()->whereNotIn('user_id', $userIds->all())->delete();

        foreach ($userIds as $userId) {
            $task->assignments()->firstOrCreate(
                ['user_id' => $userId],
                [
                    'role_label' => 'Contributor',
                    'acceptance_status' => TaskAssignment::STATUS_PENDING,
                    'assigned_by' => $assignedBy,
                    'assigned_at' => now(),
                ]
            );
        }
    }

    /**
     * Record an accept/reject decision on the task_user pivot and apply its
     * side effects: status transition, agreement lock, and the blocker flag
     * when a rejection leaves nobody engaged on the task.
     */
    private function applyAssignmentDecision(Task $task, User $user, bool $accepted, ?string $reason): TaskAssignment
    {
        $assignment = $task->assignmentFor((int) $user->user_id);

        if (! $assignment) {
            $assignment = TaskAssignment::create([
                'task_id' => $task->task_id,
                'user_id' => $user->user_id,
                'role_label' => 'Assignee',
                'acceptance_status' => TaskAssignment::STATUS_PENDING,
                'assigned_by' => $task->project?->project_manager_id ?? $user->user_id,
                'assigned_at' => now(),
            ]);
        }

        $assignment->update([
            'acceptance_status' => $accepted ? TaskAssignment::STATUS_ACCEPTED : TaskAssignment::STATUS_REJECTED,
            'rejection_reason' => $accepted ? null : $reason,
            'responded_at' => now(),
        ]);

        if ($accepted) {
            if (in_array($task->status, ['Pending', 'To Do', 'Pending Acceptance', 'Assigned'])) {
                $task->update(['status' => 'In Progress']);
            }

            // Agreed terms are locked once the assignee commits.
            $task->lock((int) $user->user_id, "Accepted by {$user->full_name}");

            return $assignment;
        }

        // A rejection only blocks the task when nobody else is still engaged.
        $stillEngaged = TaskAssignment::where('task_id', $task->task_id)
            ->whereIn('acceptance_status', [
                TaskAssignment::STATUS_ACCEPTED, TaskAssignment::STATUS_PENDING, 'accepted', 'pending',
            ])
            ->exists();

        if (! $stillEngaged) {
            $task->update([
                'status' => 'Blocked',
                'blocker_reason' => "Task rejected by {$user->full_name}".($reason ? ": {$reason}" : ''),
            ]);
        }

        return $assignment;
    }

    /** Notify whoever assigned the work, falling back to the project manager. */
    private function notifyAssigner(Task $task, ?TaskAssignment $assignment, string $message): void
    {
        $assignerId = $assignment?->assigned_by
            ? (int) $assignment->assigned_by
            : ($task->project?->project_manager_id ? (int) $task->project->project_manager_id : null);

        if ($assignerId) {
            Activity::notify($assignerId, $message, 'task');
        }
    }

    /**
     * Canonical assignee payload shared by every endpoint that mutates the
     * assignment pivot, so the drawer can re-render badges without a reload.
     *
     * @return array<int, array<string, mixed>>
     */
    private function serializeAssignees(Task $task, int $viewerId): array
    {
        return $task->assignments->map(fn (TaskAssignment $assignment) => [
            'id' => $assignment->task_assignment_id,
            'user_id' => $assignment->user_id,
            'name' => optional($assignment->user)->full_name ?? 'Unassigned',
            'role_label' => $assignment->role_label ?: 'Assignee',
            'acceptance_status' => $assignment->acceptance_status,
            'status_slug' => $assignment->statusSlug(),
            'rejection_reason' => $assignment->rejection_reason,
            'is_current_user' => (int) $assignment->user_id === $viewerId,
            'can_respond' => (int) $assignment->user_id === $viewerId,
            'responded_at' => optional($assignment->responded_at)?->diffForHumans(),
        ])->values()->all();
    }

    /**
     * Payload returned by every accept/reject/respond endpoint so the drawer
     * hides the response banner and flips the collaborator badge immediately.
     *
     * @return array<string, mixed>
     */
    private function acceptancePayload(Task $task, User $viewer): array
    {
        $assignment = $task->assignmentFor((int) $viewer->user_id);
        $status = $assignment?->statusSlug();

        return [
            'my_assignment' => $assignment ? [
                'id' => $assignment->task_assignment_id,
                'status' => $status,
                'acceptance_status' => $assignment->acceptance_status,
                'role_label' => $assignment->role_label,
                'rejection_reason' => $assignment->rejection_reason,
                'can_respond' => $status === 'pending',
            ] : null,
            'requires_acceptance' => $task->assignments->isNotEmpty(),
            'assignees' => $this->serializeAssignees($task, (int) $viewer->user_id),
        ];
    }

    /** Subtask mutations follow the parent task's participation rules. */
    private function authorizeSubtask(Task $subtask): void
    {
        $governing = $subtask->parent_task_id
            ? ($subtask->parent()->first() ?? $subtask)
            : $subtask;

        $user = Auth::user();

        abort_unless(
            $user->can('manageSubtasks', $subtask) || $user->can('manageSubtasks', $governing),
            403
        );
    }

    private function serializeTaskTree($tasks): array
    {
        return collect($tasks)->map(function (Task $task): array {
            return [
                'id' => $task->task_id,
                'name' => $task->task_name,
                'status' => $task->status,
                'is_completed' => in_array($task->status, ['Done', 'Completed']),
                'children' => $this->serializeTaskTree($task->relationLoaded('subtasks') ? $task->subtasks : collect()),
            ];
        })->values()->all();
    }

    private function loadTaskTree(Task $task): void
    {
        $task->loadMissing('subtasks');

        foreach ($task->subtasks as $subtask) {
            $this->loadTaskTree($subtask);
        }
    }
}
