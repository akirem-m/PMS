<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Office;
use App\Models\Phase;
use App\Models\Project;
use App\Models\ProjectTeam;
use App\Models\ProjectType;
use App\Models\Role;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Services\OrgHierarchyService;
use App\Services\RbacService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Roles & permissions, then offices (FK dependencies first).
        $this->call([
            RbacSeeder::class,
            OfficeSeeder::class,
        ]);

        // 2. Canonical project types, then the supplementary catalogue.
        $this->seedProjectTypes();
        $this->call(ProjectTypeSeeder::class);

        // 3. Users (admins, PMs, team leads, staff) across all offices.
        $this->call(UserSeeder::class);

        // 4. Teams and their memberships.
        $this->call(TeamSeeder::class);

        // 5. Projects, offices/teams participation and tasks.
        $this->call(ProjectSeeder::class);

        // 6. Org hierarchy + role/office matrix + workflow fixtures
        //    (sample users, teams, projects, phases and tasks).
        $admin = User::where('email', 'admin@pms.test')->firstOrFail();
        $this->seedRoleAndOfficeMatrix($admin);
    }

    /**
     * Deterministically seed the canonical project types with explicit
     * office associations. Two types are global (office_id = null) and
     * available to every office; the rest are scoped to a seeded office.
     */
    private function seedProjectTypes(): void
    {
        // Fetch office IDs dynamically after seeding offices.
        $ictOffice = Office::where('office_name', 'like', '%ICT%')->first();
        $academicOffice = Office::where('office_name', 'like', '%Academic%')->first();
        $financeOffice = Office::where('office_name', 'like', '%Finance%')
            ->orWhere('office_name', 'like', '%Procurement%')->first();
        $researchOffice = Office::where('office_name', 'like', '%Research%')->first();
        $studentOffice = Office::where('office_name', 'like', '%Student%')->first();

        $projectTypes = [
            // 2 GLOBAL PROJECT TYPES (office_id = null)
            [
                'name' => 'Hardware Procurement',
                'description' => 'Purchase, rollout and lifecycle management of hardware assets.',
                'office_id' => null,
                'is_active' => true,
            ],
            [
                'name' => 'IT Support',
                'description' => 'Helpdesk improvement, support tooling and end-user service initiatives.',
                'office_id' => null,
                'is_active' => true,
            ],

            // OFFICE-SPECIFIC PROJECT TYPES
            [
                'name' => 'Software Development',
                'description' => 'Application and platform build-outs, custom software and integrations.',
                'office_id' => $ictOffice?->office_id,
                'is_active' => true,
            ],
            [
                'name' => 'Network & Infrastructure',
                'description' => 'Cabling, switching, wireless, and network capacity expansion work.',
                'office_id' => $ictOffice?->office_id,
                'is_active' => true,
            ],
            [
                'name' => 'Academic Systems',
                'description' => 'Curriculum, grading, student record and academic workflow tools.',
                'office_id' => $academicOffice?->office_id,
                'is_active' => true,
            ],
            [
                'name' => 'Enterprise Systems',
                'description' => 'Financial tracking, ERP, and procurement management platforms.',
                'office_id' => $financeOffice?->office_id,
                'is_active' => true,
            ],
            [
                'name' => 'Research & Development',
                'description' => 'Grants management, research portals, and institutional study systems.',
                'office_id' => $researchOffice?->office_id,
                'is_active' => true,
            ],
            [
                'name' => 'Student Services Tech',
                'description' => 'Student portal, housing, and campus life automation projects.',
                'office_id' => $studentOffice?->office_id,
                'is_active' => true,
            ],
        ];

        foreach ($projectTypes as $type) {
            ProjectType::updateOrCreate(
                ['name' => $type['name']],
                $type
            );
        }
    }

    private function seedRoleAndOfficeMatrix(User $admin): void
    {
        $password = Hash::make('ChangeMe123!');
        $roles = Role::orderBy('rank')->orderBy('role_id')->get();
        $offices = Office::active()->orderBy('office_id')->get();
        $projectType = ProjectType::where('is_active', true)->orderBy('project_type_id')->firstOrFail();
        $department = Department::firstOrCreate(
            ['department_code' => 'SAMPLE'],
            ['department_name' => 'Sample Organization', 'status' => 'Active']
        );

        $offices->each(function (Office $office) use ($department): void {
            if (! $office->department_id) {
                $office->update(['department_id' => $department->department_id]);
            }
        });

        $roleUsers = [];
        foreach ($roles as $role) {
            $user = $role->role_name === 'Administrator'
                ? $admin
                : User::firstOrCreate(
                    ['email' => 'sample.'.str($role->role_name)->slug('-').'@example.com'],
                    [
                        'full_name' => 'Sample '.$role->role_name,
                        'password_hash' => $password,
                        'status' => 'Active',
                        'department' => 'Sample Organization',
                        'office_id' => $offices->first()?->office_id,
                    ]
                );

            $roleUsers[$role->role_name] = $user;
        }

        $director = User::firstOrCreate(
            ['email' => 'director@example.com'],
            [
                'full_name' => 'Sample Project Director',
                'password_hash' => $password,
                'status' => 'Active',
                'department' => 'Sample Organization',
                'office_id' => $offices->first()?->office_id,
            ]
        );
        $member = User::firstOrCreate(
            ['email' => 'member@example.com'],
            [
                'full_name' => 'Sample Team Member',
                'password_hash' => $password,
                'status' => 'Active',
                'department' => 'Sample Organization',
                'office_id' => $offices->first()?->office_id,
            ]
        );
        $legacyUsers = [
            ['abebe@example.com', 'Abebe Bikila'],
            ['chaltu@example.com', 'Chaltu Bekele'],
            ['caala@example.com', 'Caala Tadesse'],
            ['john@example.com', 'John Doe'],
            ['daniel@example.com', 'Daniel Tesfaye'],
            ['sophia@example.com', 'Sophia Chen'],
        ];
        foreach ($legacyUsers as [$email, $name]) {
            // These are staff accounts, not public registrants. The `role`
            // column defaults to 'guest', which (for an account holding no
            // RBAC role) makes User::isGuest() true and has the approval
            // middleware bounce them to the pending-approval page on every
            // request, so their staff role has to be written explicitly.
            User::updateOrCreate(
                ['email' => $email],
                ['full_name' => $name, 'password_hash' => $password, 'status' => 'Active', 'role' => 'member', 'department' => 'Sample Organization', 'office_id' => $offices->first()?->office_id]
            );
        }

        if ($projectManagerRole = $roles->firstWhere('role_name', 'Project Manager')) {
            app(RbacService::class)->assignRole($director, $projectManagerRole);
        }
        if ($teamMemberRole = $roles->firstWhere('role_name', 'Team Member')) {
            app(RbacService::class)->assignRole($member, $teamMemberRole);
        }

        $teams = collect();
        $projects = collect();
        foreach ($offices as $office) {
            $teamLead = $roleUsers['Team Lead'] ?? $admin;
            $team = Team::updateOrCreate(
                ['team_name' => 'Sample Team - '.$office->office_code],
                [
                    'team_leader_id' => $teamLead->user_id,
                    'description' => 'Development team for '.$office->office_name.'.',
                    'status' => 'Active',
                    'office_id' => $office->office_id,
                ]
            );
            $team->users()->syncWithoutDetaching([$teamLead->user_id, $member->user_id]);
            $teams->push($team);

            $project = Project::updateOrCreate(
                ['project_name' => 'Sample Project - '.$office->office_code],
                [
                    'description' => 'Demonstration project owned by '.$office->office_name.'.',
                    'client' => $office->office_name,
                    'project_type' => $projectType->name,
                    'project_type_id' => $projectType->project_type_id,
                    'team_id' => $team->team_id,
                    'project_manager_id' => ($roleUsers['Project Manager'] ?? $teamLead)->user_id,
                    'created_by' => $admin->user_id,
                    'status' => 'Planning',
                    'priority' => 'Medium',
                    'progress' => 0,
                    'start_date' => now()->toDateString(),
                    'end_date' => now()->addMonths(3)->toDateString(),
                    'primary_office_id' => $office->office_id,
                    'department_id' => $office->department_id ?? $department->department_id,
                ]
            );
            $project->offices()->syncWithoutDetaching([
                $office->office_id => ['participation_type' => 'primary'],
            ]);
            ProjectTeam::updateOrCreate(
                ['project_id' => $project->project_id, 'team_id' => $team->team_id],
                ['assigned_date' => now(), 'access_level' => 'manage']
            );
            $projects->push($project);
        }

        $firstProject = $projects->first();
        $firstTeam = $teams->first();
        $firstOffice = $offices->first();
        $hierarchy = app(OrgHierarchyService::class);

        foreach ($roles as $role) {
            $user = $roleUsers[$role->role_name];
            if ($role->role_name === 'Administrator') {
                app(RbacService::class)->assignRole($user, $role);
            } elseif (str_starts_with($role->role_name, 'Head of ')) {
                $scope = match ($role->role_name) {
                    'Head of Department' => $department,
                    'Head of Office' => $firstOffice,
                    'Head of Project' => $firstProject,
                    'Head of Team' => $firstTeam,
                    default => null,
                };
                if ($scope) {
                    $hierarchy->assignHead($user, $scope);
                }
            } elseif ($role->scope === 'project' && $firstProject) {
                app(RbacService::class)->assignRole($user, $role, $firstProject);
            } else {
                app(RbacService::class)->assignRole($user, $role);
            }
        }

        $hierarchy->sync();
        $this->seedWorkflowFixtures($admin, $password, $department, $firstOffice, $projectType);
        $this->command?->info(sprintf(
            'Sample matrix created: %d role accounts, %d offices, %d teams, %d projects.',
            count($roleUsers), $offices->count(), $teams->count(), $projects->count()
        ));
    }

    private function seedWorkflowFixtures(User $admin, string $password, Department $department, ?Office $office, ProjectType $projectType): void
    {
        $ictOfficeId = $office?->office_id;
        $john = User::updateOrCreate(
            ['email' => 'john.smith@example.com'],
            ['full_name' => 'John Smith', 'password_hash' => $password, 'status' => 'Active', 'department' => 'Sample Organization', 'office_id' => $ictOfficeId]
        );
        $sarah = User::updateOrCreate(
            ['email' => 'sarah@example.com'],
            ['full_name' => 'Sarah Connor', 'password_hash' => $password, 'status' => 'Active', 'department' => 'Sample Organization', 'office_id' => $ictOfficeId]
        );
        $david = User::updateOrCreate(
            ['email' => 'david@example.com'],
            ['full_name' => 'David Kim', 'password_hash' => $password, 'status' => 'Active', 'department' => 'Sample Organization', 'office_id' => $ictOfficeId]
        );
        $projectManagerRole = Role::where('role_name', 'Project Manager')->first();
        $memberRole = Role::where('role_name', 'Team Member')->first();
        if ($projectManagerRole) {
            app(RbacService::class)->assignRole($john, $projectManagerRole);
        }
        if ($memberRole) {
            app(RbacService::class)->assignRole($sarah, $memberRole);
            app(RbacService::class)->assignRole($david, $memberRole);
        }

        $uiux = Team::updateOrCreate(
            ['team_name' => 'UI/UX Team'],
            ['team_leader_id' => $john->user_id, 'description' => 'Design and user research.', 'status' => 'Active', 'office_id' => $ictOfficeId]
        );
        $frontend = Team::updateOrCreate(
            ['team_name' => 'Frontend Team'],
            ['team_leader_id' => $john->user_id, 'description' => 'Web interface delivery.', 'status' => 'Active', 'office_id' => $ictOfficeId]
        );
        $backend = Team::updateOrCreate(
            ['team_name' => 'Backend Team'],
            ['team_leader_id' => $john->user_id, 'description' => 'Services and APIs.', 'status' => 'Active', 'office_id' => $ictOfficeId]
        );
        $network = Team::updateOrCreate(
            ['team_name' => 'Network & Infrastructure'],
            ['team_leader_id' => $john->user_id, 'description' => 'Infrastructure operations.', 'status' => 'Active', 'office_id' => $ictOfficeId]
        );
        $engineering = Team::updateOrCreate(
            ['team_name' => 'Software Engineering'],
            ['team_leader_id' => $john->user_id, 'description' => 'Enterprise software engineering.', 'status' => 'Active', 'office_id' => $ictOfficeId]
        );
        foreach ([$uiux, $frontend, $backend] as $team) {
            $team->users()->syncWithoutDetaching([$john->user_id, $sarah->user_id, $david->user_id]);
        }

        $ecommerce = Project::updateOrCreate(
            ['project_name' => 'E-Commerce Website'],
            [
                'description' => 'Online retail platform.', 'client' => 'Global Retail Corporation',
                'project_type' => $projectType->name, 'project_type_id' => $projectType->project_type_id,
                'team_id' => $frontend->team_id, 'project_manager_id' => $john->user_id, 'created_by' => $admin->user_id,
                'status' => 'active', 'priority' => 'High', 'progress' => 45,
                'start_date' => now()->toDateString(), 'end_date' => now()->addMonths(4)->toDateString(),
                'primary_office_id' => $ictOfficeId, 'department_id' => $department->department_id,
            ]
        );
        foreach ([$uiux, $frontend, $backend] as $team) {
            ProjectTeam::updateOrCreate(
                ['project_id' => $ecommerce->project_id, 'team_id' => $team->team_id],
                ['assigned_date' => now(), 'access_level' => 'manage']
            );
        }
        if ($ictOfficeId) {
            $ecommerce->offices()->syncWithoutDetaching([$ictOfficeId => ['participation_type' => 'primary']]);
        }

        $matrixProject = Project::where('project_name', 'Sample Project - ICT')->first();
        if ($matrixProject) {
            $matrixProject->update(['project_name' => 'Sample PMS']);
        }

        $samplePms = Project::updateOrCreate(
            ['project_name' => 'Sample PMS'],
            [
                'description' => 'Project management system demonstration.', 'client' => 'Sample Organization',
                'project_type' => $projectType->name, 'project_type_id' => $projectType->project_type_id,
                'team_id' => $frontend->team_id, 'project_manager_id' => $john->user_id, 'created_by' => $admin->user_id,
                'status' => 'active', 'priority' => 'Medium', 'progress' => 20,
                'start_date' => now()->toDateString(), 'end_date' => now()->addMonths(3)->toDateString(),
                'primary_office_id' => $ictOfficeId, 'department_id' => $department->department_id,
            ]
        );

        $samplePlanning = Phase::firstOrCreate(
            ['project_id' => $samplePms->project_id, 'phase_name' => 'Requirements'],
            ['start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(2)->toDateString(), 'duration' => 14, 'status' => 'In Progress', 'sequence_order' => 1]
        );
        Phase::firstOrCreate(
            ['project_id' => $samplePms->project_id, 'phase_name' => 'Development'],
            ['start_date' => now()->addWeeks(3)->toDateString(), 'end_date' => now()->addWeeks(6)->toDateString(), 'duration' => 21, 'status' => 'Not started', 'sequence_order' => 2]
        );
        Task::firstOrCreate(
            ['project_id' => $samplePms->project_id, 'task_name' => 'Requirements Gathering'],
            ['phase_id' => $samplePlanning->phase_id, 'team_id' => $frontend->team_id, 'description' => 'Gather and document requirements.', 'assigned_to' => $sarah->user_id, 'status' => 'In Progress', 'priority' => 'High', 'progress' => 40, 'start_date' => now()->toDateString(), 'end_date' => now()->addWeek()->toDateString()]
        );

        $this->seedPhasesAndTasks($ecommerce, $uiux, $frontend, $backend, $sarah, $david);
    }

    private function seedPhasesAndTasks(Project $project, Team $uiux, Team $frontend, Team $backend, User $sarah, User $david): void
    {
        $phaseNames = ['Design', 'Development', 'Testing', 'Deployment & UAT'];
        foreach ($phaseNames as $index => $name) {
            $phase = Phase::firstOrCreate(
                ['project_id' => $project->project_id, 'phase_name' => $name],
                ['start_date' => now()->addWeeks($index)->toDateString(), 'end_date' => now()->addWeeks($index + 2)->toDateString(), 'duration' => 14, 'status' => $index === 0 ? 'In Progress' : 'Not started', 'sequence_order' => $index + 1]
            );
            $tasks = match ($name) {
                'Design' => [['Homepage Wireframe', $uiux, $sarah], ['Product Page Design', $uiux, $sarah], ['Checkout Design', $uiux, $sarah]],
                'Development' => [['Build Homepage', $frontend, $david], ['Build Product Listing', $frontend, $david], ['Build Shopping Cart', $frontend, $david]],
                'Testing' => [['Create Authentication API', $backend, $david], ['Create Product API', $backend, $david], ['Create Order API', $backend, $david], ['Create Payment API', $backend, $david]],
                default => [['Deployment Checklist', $backend, $david]],
            };
            foreach ($tasks as [$name, $team, $assignee]) {
                Task::firstOrCreate(
                    ['project_id' => $project->project_id, 'task_name' => $name],
                    ['phase_id' => $phase->phase_id, 'team_id' => $team->team_id, 'description' => $name.'.', 'assigned_to' => $assignee->user_id, 'status' => 'Pending', 'priority' => 'Medium', 'progress' => 0, 'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(2)->toDateString()]
                );
            }
        }
    }
}
