<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ChangeRequestController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OfficeController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PhaseController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectTypeController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SystemSettingController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Landing Page
|--------------------------------------------------------------------------
*/

Route::get(
    '/',
    [LandingController::class, 'index']
)->name('landing');

/*
|--------------------------------------------------------------------------
| Guest Authentication
|--------------------------------------------------------------------------
|
| These routes are available to users who are not logged in.
|
*/

Route::middleware('guest')->group(function () {

    /*
     * Login
     */
    Route::get(
        '/login',
        [AuthController::class, 'showLogin']
    )->name('login');

    Route::post(
        '/login',
        [AuthController::class, 'login']
    )->name('login.attempt');

    /*
     * Public Registration
     */
    Route::get(
        '/register',
        [AuthController::class, 'showRegister']
    )->name('register');

    Route::post(
        '/register',
        [AuthController::class, 'register']
    )->name('register.attempt');

});

/*
|--------------------------------------------------------------------------
| Authenticated Application
|--------------------------------------------------------------------------
|
| 'active' runs alongside 'auth' on every route in this group.
|
| This means a user whose account has been deactivated is
| immediately prevented from continuing to use the system.
|
*/

Route::middleware(['auth', 'active', 'approved'])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/logout',
        [AuthController::class, 'logout']
    )->name('logout');

    /*
    |--------------------------------------------------------------------------
    | Guest Onboarding (Restricted)
    |--------------------------------------------------------------------------
    |
    | Pending guest registrations are kept on this read-only landing
    | page. The 'approved' middleware ensures they cannot reach any
    | other authenticated route, and that approved users are bounced
    | back to the dashboard if they try to visit it.
    |
    */

    Route::get(
        '/pending-approval',
        [GuestController::class, 'pendingApproval']
    )->name('guest.pending');

    /*
    |--------------------------------------------------------------------------
    | Profile
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/profile',
        [ProfileController::class, 'edit']
    )->name('profile.edit');

    Route::put(
        '/profile',
        [ProfileController::class, 'update']
    )->name('profile.update');

    Route::put(
        '/profile/password',
        [ProfileController::class, 'updatePassword']
    )->name('profile.password');

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/dashboard',
        [DashboardController::class, 'index']
    )->name('dashboard');

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/search',
        [SearchController::class, 'index']
    )->name('search');

    /*
    |--------------------------------------------------------------------------
    | Projects
    |--------------------------------------------------------------------------
    |
    | Static /projects/create must be registered before the
    | /projects/{project} wildcard.
    |
    */

    Route::get(
        '/projects/create',
        [ProjectController::class, 'create']
    )
        ->name('projects.create')
        ->middleware('can:create_projects');

    Route::post(
        '/projects',
        [ProjectController::class, 'store']
    )
        ->name('projects.store')
        ->middleware('can:create_projects');

    Route::post(
        '/projects/wizard/save',
        [ProjectController::class, 'saveWizardStep']
    )
        ->name('projects.wizard.save')
        ->middleware('can:create_projects');

    Route::get(
        '/projects',
        [ProjectController::class, 'index']
    )
        ->name('projects.index')
        ->middleware('can:view_projects');

    Route::get(
        '/projects/{project}/edit',
        [ProjectController::class, 'edit']
    )
        ->name('projects.edit')
        ->middleware('can:edit_projects');

    Route::put(
        '/projects/{project}',
        [ProjectController::class, 'update']
    )
        ->name('projects.update')
        ->middleware('can:edit_projects');

    Route::patch(
        '/projects/{project}/schedule',
        [ProjectController::class, 'updateSchedule']
    )
        ->name('projects.schedule.update')
        ->middleware('can:edit_projects');

    Route::delete(
        '/projects/{project}',
        [ProjectController::class, 'destroy']
    )
        ->name('projects.destroy')
        ->middleware('can:delete_projects');

    Route::post(
        '/projects/{project}/teams',
        [ProjectController::class, 'assignTeam']
    )
        ->name('projects.teams.assign')
        ->middleware('can:edit_projects');

    Route::delete(
        '/projects/{project}/teams/{team}',
        [ProjectController::class, 'removeTeam']
    )
        ->name('projects.teams.remove')
        ->middleware('can:edit_projects');

    Route::post(
        '/projects/{project}/members',
        [ProjectController::class, 'addMember']
    )
        ->name('projects.members.add')
        ->middleware('can:edit_projects');

    Route::put(
        '/projects/{project}/members/{memberRole}',
        [ProjectController::class, 'updateMember']
    )
        ->name('projects.members.update')
        ->middleware('can:edit_projects');

    Route::delete(
        '/projects/{project}/members/{memberRole}',
        [ProjectController::class, 'removeMember']
    )
        ->name('projects.members.remove')
        ->middleware('can:edit_projects');

    Route::post(
        '/projects/{project}/deliverables',
        [ProjectController::class, 'storeDeliverable']
    )
        ->name('projects.deliverables.store')
        ->middleware('can:edit_projects');

    Route::post(
        '/projects/{project}/deliverables/{deliverable}/toggle',
        [ProjectController::class, 'toggleDeliverable']
    )
        ->name('projects.deliverables.toggle')
        ->middleware('can:edit_projects');

    Route::delete(
        '/projects/{project}/deliverables/{deliverable}',
        [ProjectController::class, 'destroyDeliverable']
    )
        ->name('projects.deliverables.destroy')
        ->middleware('can:edit_projects');

    Route::post(
        '/projects/{project}/change-requests',
        [ProjectController::class, 'storeChangeRequest']
    )
        ->name('projects.changeRequests.store')
        ->middleware('can:view_projects');

    /*
    |--------------------------------------------------------------------------
    | Project Phases
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/projects/{project}/phases',
        [PhaseController::class, 'store']
    )
        ->name('phases.store')
        ->middleware('can:edit_projects');

    Route::put(
        '/phases/{phase}',
        [PhaseController::class, 'update']
    )
        ->name('phases.update')
        ->middleware('can:edit_projects');

    Route::post(
        '/phases/{phase}/status',
        [PhaseController::class, 'updateStatus']
    )
        ->name('phases.status')
        ->middleware('can:edit_projects');

    Route::delete(
        '/phases/{phase}',
        [PhaseController::class, 'destroy']
    )
        ->name('phases.destroy')
        ->middleware('can:edit_projects');

    /*
    |--------------------------------------------------------------------------
    | Project Details
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/projects/{project}',
        [ProjectController::class, 'show']
    )
        ->name('projects.show')
        ->middleware('can:view_projects');

    /*
    |--------------------------------------------------------------------------
    | Tasks
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/tasks',
        [TaskController::class, 'index']
    )
        ->name('tasks.index');

    Route::post(
        '/tasks',
        [TaskController::class, 'store']
    )
        ->name('tasks.store');

    Route::get(
        '/tasks/{task}',
        [TaskController::class, 'show']
    )
        ->name('tasks.show');

    Route::put(
        '/tasks/{task}',
        [TaskController::class, 'update']
    )
        ->name('tasks.update');

    Route::delete(
        '/tasks/{task}',
        [TaskController::class, 'destroy']
    )
        ->name('tasks.destroy');

    Route::post(
        '/tasks/{task}/status',
        [TaskController::class, 'updateStatus']
    )
        ->name('tasks.status');

    Route::post(
        '/tasks/{task}/comments',
        [TaskController::class, 'addComment']
    )
        ->name('tasks.comments');

    Route::post(
        '/tasks/{task}/assign',
        [TaskController::class, 'assign']
    )
        ->name('tasks.assign');

    Route::post(
        '/tasks/assignments/{assignment}/respond',
        [TaskController::class, 'respondToAssignment']
    )
        ->name('tasks.assignments.respond')
        ->middleware('auth');

    Route::post(
        '/tasks/{task}/lock',
        [TaskController::class, 'lock']
    )
        ->name('tasks.lock')
        ->middleware('auth');

    Route::post(
        '/tasks/{task}/unlock',
        [TaskController::class, 'unlock']
    )
        ->name('tasks.unlock')
        ->middleware('auth');

    Route::post(
        '/tasks/{task}/attachments',
        [TaskController::class, 'uploadAttachment']
    )
        ->name('tasks.attachments.upload')
        ->middleware('can:view_tasks');

    Route::delete(
        '/tasks/{task}/attachments/{attachment}',
        [TaskController::class, 'deleteAttachment']
    )
        ->name('tasks.attachments.delete')
        ->middleware('can:view_tasks');

    Route::get(
        '/attachments/{attachment}/download',
        [TaskController::class, 'downloadAttachment']
    )
        ->name('attachments.download')
        ->middleware('can:view_tasks');

    Route::post(
        '/tasks/{task}/subtasks',
        [TaskController::class, 'storeSubtask']
    )
        ->name('tasks.subtasks.store')
        ->middleware('can:view_tasks');

    Route::post(
        '/tasks/subtasks/{subtask}/toggle',
        [TaskController::class, 'toggleSubtask']
    )
        ->name('tasks.subtasks.toggle')
        ->middleware('can:view_tasks');

    Route::delete(
        '/tasks/subtasks/{subtask}',
        [TaskController::class, 'destroySubtask']
    )
        ->name('tasks.subtasks.destroy')
        ->middleware('can:view_tasks');

    Route::post(
        '/tasks/{task}/accept',
        [TaskController::class, 'accept']
    )->name('tasks.accept');

    Route::post(
        '/tasks/{task}/reject',
        [TaskController::class, 'reject']
    )->name('tasks.reject');

    Route::post(
        '/tasks/{task}/assign-users',
        [TaskController::class, 'assignUsers']
    )->name('tasks.assign-users');

    Route::delete(
        '/tasks/{task}/assignees/{user}',
        [TaskController::class, 'removeAssignee']
    )->name('tasks.assignees.remove');

    /*
    |--------------------------------------------------------------------------
    | Change Requests
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/change-requests/{changeRequest}/approve',
        [ChangeRequestController::class, 'approve']
    )
        ->name('changeRequests.approve')
        ->middleware('can:approve_change_requests');

    Route::post(
        '/change-requests/{changeRequest}/reject',
        [ChangeRequestController::class, 'reject']
    )
        ->name('changeRequests.reject')
        ->middleware('can:approve_change_requests');

    /*
    |--------------------------------------------------------------------------
    | Teams
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/teams/create',
        [TeamController::class, 'create']
    )
        ->name('teams.create');

    Route::post(
        '/teams',
        [TeamController::class, 'store']
    )
        ->name('teams.store');

    Route::get(
        '/teams',
        [TeamController::class, 'index']
    )
        ->name('teams.index')
        ->middleware('can:view_projects');

    Route::post(
        '/teams/{team}/members',
        [TeamController::class, 'addMember']
    )
        ->name('teams.members.add')
        ->middleware('can:manageMembers,team');

    Route::delete(
        '/teams/{team}/members/{member}',
        [TeamController::class, 'removeMember']
    )
        ->name('teams.members.remove')
        ->middleware('can:manageMembers,team');

    Route::post(
        '/teams/{team}/leader',
        [TeamController::class, 'updateLeader']
    )
        ->name('teams.leader')
        ->middleware('can:manageMembers,team');

    Route::get(
        '/teams/{team}/edit',
        [TeamController::class, 'edit']
    )
        ->name('teams.edit')
        ->middleware('can:update,team');

    Route::put(
        '/teams/{team}',
        [TeamController::class, 'update']
    )
        ->name('teams.update')
        ->middleware('can:update,team');

    Route::delete(
        '/teams/{team}',
        [TeamController::class, 'destroy']
    )
        ->name('teams.destroy')
        ->middleware('can:delete,team');

    Route::get(
        '/teams/{team}',
        [TeamController::class, 'show']
    )
        ->name('teams.show')
        ->middleware('can:view_projects');

    /*
    |--------------------------------------------------------------------------
    | Budgets
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/budgets',
        [BudgetController::class, 'index']
    )
        ->name('budgets.index')
        ->middleware('can:view_budgets');

    Route::post(
        '/budgets/projects/{project}',
        [BudgetController::class, 'updateProjectBudget']
    )
        ->name('budgets.projects.update')
        ->middleware('can:manage_budgets');

    Route::post(
        '/budgets/phases/{phase}',
        [BudgetController::class, 'updatePhaseBudget']
    )
        ->name('budgets.phases.update')
        ->middleware('can:manage_budgets');

    /*
    |--------------------------------------------------------------------------
    | Payments & Costs
    |--------------------------------------------------------------------------
    */

    /*
     | Expenditure workflow. The listing is intentionally open to every
     | authenticated user — contributors must be able to review the expenses
     | they submitted — and is scoped inside PaymentController, while every
     | transition is authorized by PaymentPolicy.
     |
     | Contributor submits (Pending) -> Team Lead / PM approves (Approved)
     | -> Head of Office / Finance confirms disbursement (Completed).
     */

    Route::get(
        '/payments',
        [PaymentController::class, 'index']
    )->name('payments.index');

    Route::post(
        '/payments',
        [PaymentController::class, 'store']
    )->name('payments.store');

    Route::post(
        '/payments/{payment}/approve',
        [PaymentController::class, 'approve']
    )->name('payments.approve');

    Route::post(
        '/payments/{payment}/disburse',
        [PaymentController::class, 'disburse']
    )->name('payments.disburse');

    Route::post(
        '/payments/{payment}/reject',
        [PaymentController::class, 'reject']
    )->name('payments.reject');

    Route::put(
        '/payments/{payment}',
        [PaymentController::class, 'update']
    )->name('payments.update');

    Route::delete(
        '/payments/{payment}',
        [PaymentController::class, 'destroy']
    )->name('payments.destroy');

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/notifications',
        [NotificationController::class, 'index']
    )->name('notifications.index');

    Route::post(
        '/notifications/mark-all-read',
        [NotificationController::class, 'markAllRead']
    )->name('notifications.markAllRead');

    /*
    |--------------------------------------------------------------------------
    | Reports
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/reports',
        [ReportController::class, 'index']
    )
        ->name('reports.index')
        ->middleware('can:view_reports');

    /*
    |--------------------------------------------------------------------------
    | Calendar
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/calendar',
        [CalendarController::class, 'index']
    )
        ->name('calendar.index')
        ->middleware('can:view_calendar');

    /*
    |--------------------------------------------------------------------------
    | Roles
    |--------------------------------------------------------------------------
    */

    Route::prefix('admin')->name('admin.roles.')->group(function () {
        Route::get(
            '/roles',
            [RoleController::class, 'index']
        )->name('index');

        Route::get(
            '/roles/create',
            [RoleController::class, 'create']
        )->name('create')->middleware('can:manage_roles');

        Route::post(
            '/roles',
            [RoleController::class, 'store']
        )->name('store')->middleware('can:manage_roles');

        Route::get(
            '/roles/{role}/edit',
            [RoleController::class, 'edit']
        )->name('edit')->middleware('can:manage_roles');

        Route::put(
            '/roles/{role}',
            [RoleController::class, 'update']
        )->name('update')->middleware('can:manage_roles');

        Route::delete(
            '/roles/{role}',
            [RoleController::class, 'destroy']
        )->name('destroy')->middleware('can:manage_roles');

        Route::post(
            '/roles/{role}/users',
            [RoleController::class, 'assignUser']
        )->name('assignUser')->middleware('can:manage_roles');

        Route::delete(
            '/roles/{role}/users/{user}',
            [RoleController::class, 'revokeUser']
        )->name('revokeUser')->middleware('can:manage_roles');

        Route::post(
            '/roles/users/{user}',
            [RoleController::class, 'updateUserRole']
        )->name('updateUserRole')->middleware('can:manage_users');

        Route::post(
            '/roles/{role}/permissions/{permission}',
            [RoleController::class, 'togglePermission']
        )->name('togglePermission')->middleware('can:manage_roles');
    });

    /*
    |--------------------------------------------------------------------------
    | Offices (Multi-Office Management)
    |--------------------------------------------------------------------------
    |
    | Office administration is permission-protected twice: via the `can`
    | middleware here AND via OfficePolicy checks in the controller, so
    | a direct POST/PUT is blocked server-side even if the UI is bypassed.
    |
    */

    Route::get(
        '/admin/offices',
        [OfficeController::class, 'index']
    )
        ->name('admin.offices.index')
        ->middleware('can:view_offices');

    Route::get(
        '/admin/offices/create',
        [OfficeController::class, 'create']
    )
        ->name('admin.offices.create')
        ->middleware('can:manage_offices');

    Route::post(
        '/admin/offices',
        [OfficeController::class, 'store']
    )
        ->name('admin.offices.store')
        ->middleware('can:manage_offices');

    Route::get(
        '/admin/offices/{office}/edit',
        [OfficeController::class, 'edit']
    )
        ->name('admin.offices.edit')
        ->middleware('can:manage_offices');

    Route::put(
        '/admin/offices/{office}',
        [OfficeController::class, 'update']
    )
        ->name('admin.offices.update')
        ->middleware('can:manage_offices');

    Route::post(
        '/admin/offices/{office}/toggle-status',
        [OfficeController::class, 'toggleStatus']
    )
        ->name('admin.offices.toggleStatus')
        ->middleware('can:manage_offices');

    Route::get(
        '/admin/offices/{office}',
        [OfficeController::class, 'show']
    )
        ->name('admin.offices.show')
        ->middleware('can:view_offices');

    /*
    |--------------------------------------------------------------------------
    | Audit Log
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/admin/audit-log',
        [AuditLogController::class, 'index']
    )
        ->name('admin.audit')
        ->middleware('can:view_audit_logs');

    Route::get(
        '/admin/audit-log/export',
        [AuditLogController::class, 'export']
    )
        ->name('admin.audit.export')
        ->middleware('can:view_audit_logs');

    /*
    |--------------------------------------------------------------------------
    | Users
    |--------------------------------------------------------------------------
    |
    | System Administrators can manage existing users and
    | approve/reject public registration requests.
    |
    */

    Route::get(
        '/admin/users',
        [UserController::class, 'index']
    )
        ->name('admin.users.index')
        ->middleware('can:manage_users');

    Route::get(
        '/admin/users/create',
        [UserController::class, 'create']
    )
        ->name('admin.users.create')
        ->middleware('can:manage_users');

    Route::post(
        '/admin/users',
        [UserController::class, 'store']
    )
        ->name('admin.users.store')
        ->middleware('can:manage_users');

    /*
    |--------------------------------------------------------------------------
    | Registration Approval
    |--------------------------------------------------------------------------
    |
    | A pending user does not choose their own role.
    | The administrator chooses the role when approving.
    |
    */

    Route::post(
        '/admin/users/{user}/approve',
        [UserController::class, 'approve']
    )
        ->name('admin.users.approve')
        ->middleware('can:manage_users');

    Route::post(
        '/admin/users/{user}/reject',
        [UserController::class, 'reject']
    )
        ->name('admin.users.reject')
        ->middleware('can:manage_users');

    /*
    |--------------------------------------------------------------------------
    | Edit User
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/admin/users/{user}/edit',
        [UserController::class, 'edit']
    )
        ->name('admin.users.edit')
        ->middleware('can:manage_users');

    Route::put(
        '/admin/users/{user}',
        [UserController::class, 'update']
    )
        ->name('admin.users.update')
        ->middleware('can:manage_users');

    /*
    |--------------------------------------------------------------------------
    | Reset User Password
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/admin/users/{user}/reset-password',
        [UserController::class, 'resetPassword']
    )
        ->name('admin.users.resetPassword')
        ->middleware('can:users.reset-password');

    /*
    |--------------------------------------------------------------------------
    | Toggle User Status
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/admin/users/{user}/toggle-status',
        [UserController::class, 'toggleStatus']
    )
        ->name('admin.users.toggleStatus')
        ->middleware('can:manage_users');

    /*
    |--------------------------------------------------------------------------
    | System Settings
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/admin/settings',
        [SystemSettingController::class, 'edit']
    )
        ->name('admin.settings')
        ->middleware('can:manage_system_settings');

    Route::put(
        '/admin/settings',
        [SystemSettingController::class, 'update']
    )
        ->name('admin.settings.update')
        ->middleware('can:manage_system_settings');

    /*
    |--------------------------------------------------------------------------
    | Project Types (Settings)
    |--------------------------------------------------------------------------
    |
    | Database-managed catalogue used by the project creation wizard.
    |
    */

    Route::get(
        '/settings/project-types',
        [ProjectTypeController::class, 'index']
    )
        ->name('admin.project-types.index')
        ->middleware('can:manage_project_types');

    /*
     | JSON lookup used by the project wizard: returns the active types
     | available for a given primary office (office-scoped + global).
     | Read-only for any authenticated, approved user with create access.
     */
    Route::get(
        '/settings/project-types/for-office',
        [ProjectTypeController::class, 'forOffice']
    )
        ->name('admin.project-types.forOffice')
        ->middleware('can:create_projects');

    Route::get(
        '/settings/project-types/{projectType}/edit',
        [ProjectTypeController::class, 'edit']
    )
        ->name('admin.project-types.edit')
        ->middleware('can:manage_project_types');

    Route::post(
        '/settings/project-types',
        [ProjectTypeController::class, 'store']
    )
        ->name('admin.project-types.store')
        ->middleware('can:manage_project_types');

    Route::put(
        '/settings/project-types/{projectType}',
        [ProjectTypeController::class, 'update']
    )
        ->name('admin.project-types.update')
        ->middleware('can:manage_project_types');

    Route::post(
        '/settings/project-types/{projectType}/toggle',
        [ProjectTypeController::class, 'toggleActive']
    )
        ->name('admin.project-types.toggle')
        ->middleware('can:manage_project_types');

    Route::delete(
        '/settings/project-types/{projectType}',
        [ProjectTypeController::class, 'destroy']
    )
        ->name('admin.project-types.destroy')
        ->middleware('can:manage_project_types');

});
