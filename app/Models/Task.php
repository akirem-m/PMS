<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $primaryKey = 'task_id';

    public $timestamps = false;

    protected $fillable = [
        'project_id', 'phase_id', 'team_id', 'sub_team_id', 'parent_task_id', 'task_name', 'description', 'assigned_to',
        'status', 'priority', 'progress', 'budget', 'blocker_reason',
        'start_date', 'end_date', 'duration',
        'is_locked', 'locked_at', 'locked_by', 'lock_reason',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_locked' => 'boolean',
        'locked_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    public function team()
    {
        return $this->belongsTo(Team::class, 'team_id', 'team_id');
    }

    /** Optional sub-team scope; null means the task sits at the team level. */
    public function subTeam()
    {
        return $this->belongsTo(SubTeam::class, 'sub_team_id', 'sub_team_id');
    }

    /** Sub-Team Lead when scoped to a sub-team with a lead, else the Team Lead. */
    public function teamLead(): ?User
    {
        if ($this->sub_team_id) {
            $lead = $this->subTeam?->lead;

            if ($lead) {
                return $lead;
            }
        }

        return $this->team?->leader;
    }

    public function projectManager(): ?User
    {
        return $this->project?->projectManager;
    }

    public function officeHead(): ?User
    {
        return $this->project?->primaryOffice?->head;
    }

    public function departmentHead(): ?User
    {
        return $this->project?->primaryOffice?->department?->head;
    }

    /**
     * User ids holding leadership over this task and every node above it,
     * used by the hierarchical policies. Gracefully falls back up the chain
     * when sub_team_id is null: Sub-Team Lead (optional) -> Team Lead ->
     * Project Manager -> Office Head -> Department Head.
     *
     * @return array<int, int>
     */
    public function leadershipUserIds(): array
    {
        return collect([
            $this->sub_team_id ? $this->subTeam?->lead_user_id : null,
            $this->teamLead()?->user_id,
            $this->projectManager()?->user_id,
            $this->officeHead()?->user_id,
            $this->departmentHead()?->user_id,
        ])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function phase()
    {
        return $this->belongsTo(Phase::class, 'phase_id', 'phase_id');
    }

    public function parent()
    {
        return $this->belongsTo(Task::class, 'parent_task_id', 'task_id');
    }

    public function subtasks()
    {
        return $this->hasMany(Task::class, 'parent_task_id', 'task_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to', 'user_id');
    }

    public function assignments()
    {
        return $this->hasMany(TaskAssignment::class, 'task_id', 'task_id');
    }

    public function collaborators()
    {
        return $this->belongsToMany(User::class, 'task_assignments', 'task_id', 'user_id')
            ->withPivot('task_assignment_id', 'role_label', 'acceptance_status', 'rejection_reason', 'assigned_at', 'responded_at');
    }

    /**
     * Acceptance decision of one user on this task, if they hold an
     * assignment row. Returns null when the user is not an assignee.
     */
    public function assignmentFor(?int $userId): ?TaskAssignment
    {
        if (! $userId) {
            return null;
        }

        if ($this->relationLoaded('assignments')) {
            return $this->assignments->firstWhere('user_id', $userId);
        }

        return $this->assignments()->where('user_id', $userId)->first();
    }

    /** True when at least one assignee still owes an accept/reject response. */
    public function hasPendingAcceptance(): bool
    {
        if ($this->relationLoaded('assignments')) {
            return $this->assignments->contains(fn (TaskAssignment $assignment) => $assignment->isPending());
        }

        return $this->assignments()
            ->whereIn('acceptance_status', [TaskAssignment::STATUS_PENDING, 'pending'])
            ->exists();
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'task_id', 'task_id');
    }

    public function locker()
    {
        return $this->belongsTo(User::class, 'locked_by', 'user_id');
    }

    public function isEditable(): bool
    {
        return ! $this->isLocked();
    }

    public function isLocked(): bool
    {
        if ($this->is_locked || $this->locked_at) {
            return true;
        }

        return in_array($this->status, ['In Progress', 'In Review', 'Completed', 'Done'])
            && $this->assignments()
                ->whereIn('acceptance_status', [TaskAssignment::STATUS_ACCEPTED, 'accepted'])
                ->exists();
    }

    public function lock(?int $userId = null, ?string $reason = null): void
    {
        $this->update([
            'is_locked' => true,
            'locked_at' => now(),
            'locked_by' => $userId,
            'lock_reason' => $reason ?? 'Agreed task terms locked upon acceptance',
        ]);
    }

    public function unlock(?int $userId = null): void
    {
        $this->update([
            'is_locked' => false,
            'locked_at' => null,
            'locked_by' => null,
            'lock_reason' => null,
        ]);
    }

    public function totalCost(): float
    {
        $direct = (float) $this->payments()->where('payment_status', 'Completed')->sum('amount');
        $sub = (float) $this->subtasks->sum(fn ($s) => $s->totalCost());

        return $direct + $sub;
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class, 'entity_id', 'task_id')->where('entity_type', 'Task')->orderByDesc('uploaded_at');
    }

    public function comments()
    {
        return $this->hasMany(TaskComment::class, 'task_id', 'task_id')->orderBy('created_at');
    }

    public function isOverdue(): bool
    {
        return ! in_array($this->status, ['Done', 'Completed'])
            && $this->end_date
            && $this->end_date->isBefore(today());
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'Done', 'Completed' => 'b-active',
            'In Progress' => 'b-planning',
            'In Review' => 'b-review',
            'Blocked' => 'b-blocked',
            default => 'b-risk',
        };
    }

    public function priorityBadgeClass(): string
    {
        return 'p-'.strtolower($this->priority ?: 'medium');
    }

    public function progressLogs()
    {
        return $this->hasMany(TaskProgressLog::class, 'task_id', 'task_id')->orderByDesc('changed_at');
    }

    // tasks this one depends on
    public function dependencies()
    {
        return $this->belongsToMany(Task::class, 'task_dependencies', 'task_id', 'depends_on_task_id')
            ->withPivot('dependency_type');
    }

    // tasks that depend on this one
    public function dependents()
    {
        return $this->belongsToMany(Task::class, 'task_dependencies', 'depends_on_task_id', 'task_id')
            ->withPivot('dependency_type');
    }
}
