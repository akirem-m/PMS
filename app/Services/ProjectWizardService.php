<?php

namespace App\Services;

use App\Http\Requests\StoreProjectRequest;
use App\Models\Phase;
use App\Models\PhaseBudget;
use App\Models\Project;
use App\Models\ProjectBudget;
use App\Models\ProjectMemberRole;
use App\Models\ProjectType;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Handles the multi-step project creation flow: project instantiation,
 * default phase & budget creation, roster assignment and initial task setup.
 */
class ProjectWizardService
{
    public const PHASES = ['Initiation', 'Planning', 'Execution', 'Monitoring', 'Closure'];

    /**
     * Create a project (with default phases, budgets, roster and initial tasks)
     * from a validated wizard request. The whole flow runs inside a single
     * database transaction so a failure in any step rolls everything back.
     */
    public function handleWizardSave(StoreProjectRequest $request): Project
    {
        /** @var User $user */
        $user = Auth::user();

        // Admins can create projects for any office. Other users must have an office and create within it.
        if (! $user->canAccessGlobalScope()) {
            if (! $user->office_id || (int) $request->input('primary_office_id') !== (int) $user->office_id) {
                abort(422, 'Projects must be created under your assigned office.');
            }
        }

        $pmInput = $request->input('project_manager_id') ?? $request->input('project_manager_name');
        $resolvedPmId = $this->resolveUserId($pmInput, $request->input('team_id'));

        $data = $request->validated();

        // Offices this project may draw people from: primary + participating.
        $allowedOfficeIds = collect([(int) ($data['primary_office_id'] ?? null)])
            ->merge($data['participating_offices'] ?? [])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $assertUserAllowed = function (?int $userId) use ($allowedOfficeIds): void {
            if (! $userId || $allowedOfficeIds->isEmpty()) {
                return;
            }

            $candidate = User::find($userId);
            if ($candidate && $candidate->office_id && ! $allowedOfficeIds->contains((int) $candidate->office_id)) {
                abort(422, "{$candidate->full_name} belongs to an office that is not associated with this project.");
            }
        };

        $assertUserAllowed($resolvedPmId);

        if ($resolvedPmId) {
            $projectManager = User::find($resolvedPmId);
            // For non-admins, the project manager must belong to their office.
            // For admins, project managers can belong to any office.
            if (! $projectManager) {
                abort(422, 'The selected Project Manager does not exist.');
            }
            if (! $user->canAccessGlobalScope() && (int) $projectManager->office_id !== (int) $user->office_id) {
                abort(422, 'The Project Manager must belong to your office.');
            }
        }

        $selectedTeamIds = collect($request->input('team_ids', []))
            ->merge($request->input('teams', []))
            ->push($request->input('team_id'))
            ->filter()
            ->values();

        $primaryTeamId = $selectedTeamIds->first() ?? $request->input('team_id');

        $project = DB::transaction(function () use ($user, $data, $resolvedPmId, $selectedTeamIds, $primaryTeamId, $assertUserAllowed) {
            $project = Project::create([
                'project_name' => $data['project_name'],
                'description' => $data['description'] ?? null,
                'client' => $data['client'] ?? null,
                'project_type' => $data['project_type'] ?? optional(ProjectType::find($data['project_type_id'] ?? null))->name ?? 'Software',
                'project_type_id' => $this->resolveProjectTypeId($data),
                'team_id' => $primaryTeamId,
                'project_manager_id' => $resolvedPmId,
                'priority' => $data['priority'] ?? 'Medium',
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'status' => 'planning',
                'progress' => 0,
                'created_by' => $user->user_id,
                'primary_office_id' => $data['primary_office_id'],
            ]);

            $officePivot = [
                (int) $data['primary_office_id'] => ['participation_type' => 'primary'],
            ];
            foreach (collect($data['participating_offices'] ?? [])->unique() as $officeId) {
                if ((int) $officeId !== (int) $data['primary_office_id']) {
                    $officePivot[(int) $officeId] = ['participation_type' => 'participating'];
                }
            }
            $project->offices()->sync($officePivot);

            // Attach all selected teams in project_teams pivot
            if ($selectedTeamIds->isNotEmpty()) {
                foreach ($selectedTeamIds as $tid) {
                    DB::table('project_teams')->insertOrIgnore([
                        'project_id' => $project->project_id,
                        'team_id' => $tid,
                        'assigned_date' => now(),
                    ]);
                }
            }

            // Save flexible member assignments
            if (! empty($data['members']) && is_array($data['members'])) {
                $assignedUserIds = [];
                foreach ($data['members'] as $memberData) {
                    if (! empty($memberData['user_id']) && ! in_array($memberData['user_id'], $assignedUserIds)) {
                        $assertUserAllowed((int) $memberData['user_id']);
                        $assignedUserIds[] = $memberData['user_id'];
                        ProjectMemberRole::create([
                            'project_id' => $project->project_id,
                            'user_id' => $memberData['user_id'],
                            'role_id' => $memberData['role_id'] ?? null,
                            'specialty' => $memberData['specialty'] ?? null,
                            'assigned_date' => now()->toDateString(),
                        ]);

                        if ((int) $memberData['user_id'] !== (int) $user->user_id) {
                            $roleName = ! empty($memberData['specialty']) ? " as {$memberData['specialty']}" : '';
                            Activity::notify((int) $memberData['user_id'], "You were assigned to \"{$project->project_name}\"{$roleName}", 'project');
                        }
                    }
                }
            }

            ProjectBudget::create([
                'project_id' => $project->project_id,
                'allocated_amount' => $data['allocated_amount'] ?? 0,
                'spent_amount' => 0,
                'currency' => 'ETB',
            ]);

            $firstPhase = null;
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

                $firstPhase = $firstPhase ?? $phase;
            }

            // Create initial tasks if provided in the wizard workflow
            if (! empty($data['tasks']) && is_array($data['tasks'])) {
                foreach ($data['tasks'] as $taskData) {
                    if (empty($taskData['task_name'])) {
                        continue;
                    }

                    $taskTeamId = ! empty($taskData['team_id']) ? (int) $taskData['team_id'] : $primaryTeamId;

                    // A task may be assigned to several team members at once
                    // (multi-select picker); legacy single-value payloads using
                    // `assigned_to` keep working.
                    $assigneeIds = collect($taskData['user_ids'] ?? [])
                        ->push($taskData['assigned_to'] ?? null)
                        ->map(fn ($id) => $this->resolveUserId($id, $taskTeamId))
                        ->filter()
                        ->map(fn ($id) => (int) $id)
                        ->unique()
                        ->values();

                    foreach ($assigneeIds as $assigneeId) {
                        $assertUserAllowed($assigneeId);
                    }

                    $assigneeId = $assigneeIds->first();

                    $status = $taskData['status'] ?? 'To Do';
                    if ($status === 'Pending') {
                        $status = 'To Do';
                    }

                    $taskBudget = isset($taskData['budget']) ? (float) $taskData['budget'] : 0;
                    if ($firstPhase) {
                        app(TaskBudgetAllocationService::class)->assertAllocationAllowed($firstPhase, $taskBudget);
                    }

                    $task = Task::create([
                        'project_id' => $project->project_id,
                        'phase_id' => $firstPhase ? $firstPhase->phase_id : null,
                        'team_id' => $taskTeamId,
                        'task_name' => $taskData['task_name'],
                        'description' => $taskData['description'] ?? null,
                        'assigned_to' => $assigneeId,
                        'priority' => $taskData['priority'] ?? 'Medium',
                        'status' => $status,
                        'budget' => $taskBudget,
                        'start_date' => $data['start_date'] ?? now()->toDateString(),
                        'end_date' => $taskData['end_date'] ?? $data['end_date'] ?? null,
                        'progress' => in_array($status, ['Done', 'Completed']) ? 100 : 0,
                    ]);

                    if ($assigneeIds->isNotEmpty()) {
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
                }

                $project->recalculateProgress();
            }

            return $project;
        });

        Activity::log('Created project', 'Project', $project->project_id, $project->project_name);

        if ($project->project_manager_id && (int) $project->project_manager_id !== (int) $user->user_id) {
            Activity::notify((int) $project->project_manager_id, $user->full_name." assigned you as Project Manager for \"{$project->project_name}\"", 'project');
        }

        return $project;
    }

    /**
     * Prefers an explicit project_type_id; otherwise falls back to the
     * legacy free-text project_type string so old clients keep working.
     *
     * @param  array<string, mixed>  $data
     */
    public function resolveProjectTypeId(array $data): ?int
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

    /**
     * Resolve a user ID, e-mail or typed name to a user id, creating an
     * unprivileged placeholder for a name that matches nobody. Delegates to
     * UserResolver so the project, team and task forms agree on what a typed
     * name means.
     */
    public function resolveUserId(string|int|null $input, ?int $teamId = null): ?int
    {
        return app(UserResolver::class)->resolve($input, $teamId);
    }
}
