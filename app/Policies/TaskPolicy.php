<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Policies\Concerns\ChecksHierarchicalLeadership;
use App\Services\RbacService;

/**
 * Object-level authorization for tasks. Visibility follows the parent
 * project; a task without a resolvable project (orphaned phase data) is
 * only visible to organization-wide task viewers.
 */
class TaskPolicy
{
    use ChecksHierarchicalLeadership;

    public function view(User $user, Task $task): bool
    {
        // Assigned user always has access.
        if ((int) $task->assigned_to === (int) $user->user_id
            || $task->assignments()->where('user_id', $user->user_id)->exists()) {
            return true;
        }

        $project = $task->project ?? optional($task->phase)->project;

        if ($project) {
            return $user->can('view', $project)
                || app(RbacService::class)->can($user, 'view_tasks', $project);
        }

        return $user->hasPermission('view_tasks');
    }

    /** The task's project manager, org edit-project holders, or the assignee. */
    public function update(User $user, Task $task): bool
    {
        if ($task->is_locked) {
            return false;
        }

        $project = $task->project ?? optional($task->phase)->project;

        if ((int) $task->assigned_to === (int) $user->user_id) {
            return true;
        }

        // Sub-Team Lead / Team Lead / PM / Office Head / Dept Head.
        if ($this->leadsOrOversees($user, $task)) {
            return true;
        }

        if ($project) {
            return $project->isManagedBy($user);
        }

        return $user->hasPermission('edit_projects');
    }

    /** Commenting follows view access. */
    public function comment(User $user, Task $task): bool
    {
        return $this->view($user, $task);
    }

    /** Attachments follow view access (upload) / uploader-or-admin (delete). */
    public function attach(User $user, Task $task): bool
    {
        return $this->view($user, $task);
    }

    public function deleteAttachment(User $user, Task $task): bool
    {
        return $user->isDirectorOrAdmin();
    }

    /**
     * Reassignment is authorized like any other manage-level action. The
     * agreement lock protects the assignee list as an agreed term, so it is
     * enforced here — but only against callers who cannot override locked
     * terms. Blanket-denying a locked task made the controller's own
     * modifyLocked override unreachable for the managers who are supposed to
     * hold it.
     */
    public function assign(User $user, Task $task): bool
    {
        if ($task->is_locked && ! $this->modifyLocked($user, $task)) {
            return false;
        }

        $project = $task->project ?? optional($task->phase)->project;

        if ($project) {
            return app(RbacService::class)
                ->can($user, 'assign_tasks', $project);
        }

        return $user->hasPermission('assign_tasks');
    }

    /**
     * Status transitions follow delivery participation, not the agreement
     * lock. An accepted assignee may always move the task between To Do,
     * In Progress, In Review and Completed — including on a task whose agreed
     * terms are locked. A user who still owes an accept/reject response is
     * blocked until they respond (locked-term overriders aside), and anyone
     * managing the task keeps their authority.
     */
    public function updateStatus(User $user, Task $task): bool
    {
        $assignment = $task->assignmentFor((int) $user->user_id);

        if ($assignment?->isPending() && ! $this->modifyLocked($user, $task)) {
            return false;
        }

        if ($assignment?->isAccepted() || $this->managesTask($user, $task)) {
            return true;
        }

        return $this->update($user, $task);
    }

    /**
     * Adding, toggling, and deleting subtasks follows the same participation
     * rule as status updates so assigned members can keep their checklist
     * current without unlocking the agreed terms.
     */
    public function manageSubtasks(User $user, Task $task): bool
    {
        return $this->updateStatus($user, $task) || $this->update($user, $task);
    }

    /**
     * Authority over a task's delivery state: project manager, office head,
     * department head, team lead, or an organization-wide project editor.
     * Deliberately independent of the agreement lock, which protects the
     * agreed terms (name/budget/dates/assignees), not day-to-day progress.
     */
    protected function managesTask(User $user, Task $task): bool
    {
        if ($user->canAccessGlobalScope()) {
            return true;
        }

        if ($this->leadsOrOversees($user, $task)) {
            return true;
        }

        $project = $task->project ?? optional($task->phase)->project;

        return $project
            ? $project->isManagedBy($user)
            : $user->hasPermission('edit_projects');
    }

    /** Only assignees can accept/reject their assignment on a task. */
    public function accept(User $user, Task $task): bool
    {
        return (int) $task->assigned_to === (int) $user->user_id
            || $task->assignments()->where('user_id', $user->user_id)->exists();
    }

    public function reject(User $user, Task $task): bool
    {
        return $this->accept($user, $task);
    }

    /** Check if user can override locked fields on an accepted task. */
    public function modifyLocked(User $user, Task $task): bool
    {
        $project = $task->project ?? optional($task->phase)->project;

        return $user->isAdmin()
            || $user->isDirectorOrAdmin()
            || ($project && $project->isManagedBy($user));
    }
}
