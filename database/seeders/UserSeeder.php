<?php

namespace Database\Seeders;

use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo users across every seeded office, with organization-scoped RBAC
 * roles from RbacSeeder. All demo accounts share the password "password".
 */
class UserSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'password';

    /**
     * @return array<string, array{full_name: string, office: ?string, department: ?string, role: string}>
     */
    public const USERS = [
        // Administrators
        'admin@pms.test' => [
            'full_name' => 'System Administrator',
            'office' => null,
            'department' => 'Executive',
            'role' => 'Administrator',
        ],

        // Project Managers
        'pm.ict@pms.test' => [
            'full_name' => 'Selam Bekele',
            'office' => 'ICT Directorate',
            'department' => 'ICT',
            'role' => 'Project Manager',
        ],
        'pm.academic@pms.test' => [
            'full_name' => 'Daniel Girma',
            'office' => 'Academic Affairs',
            'department' => 'Academic Systems',
            'role' => 'Project Manager',
        ],
        'pm.finance@pms.test' => [
            'full_name' => 'Hana Tesfaye',
            'office' => 'Finance Directorate',
            'department' => 'Finance',
            'role' => 'Project Manager',
        ],
        'pm.research@pms.test' => [
            'full_name' => 'Yonas Alemu',
            'office' => 'Research',
            'department' => 'Research & Publication',
            'role' => 'Project Manager',
        ],

        // Department Heads / Team Leads
        'lead.ict@pms.test' => [
            'full_name' => 'Meron Haile',
            'office' => 'ICT Directorate',
            'department' => 'ICT',
            'role' => 'Team Lead',
        ],
        'lead.academic@pms.test' => [
            'full_name' => 'Getachew Molla',
            'office' => 'Academic Affairs',
            'department' => 'Registrar Liaison',
            'role' => 'Team Lead',
        ],
        'lead.finance@pms.test' => [
            'full_name' => 'Bethel Abera',
            'office' => 'Finance Directorate',
            'department' => 'Budget & Planning',
            'role' => 'Team Lead',
        ],
        'lead.procurement@pms.test' => [
            'full_name' => 'Solomon Kidane',
            'office' => 'Procurement',
            'department' => 'Supplies',
            'role' => 'Team Lead',
        ],
        'lead.research@pms.test' => [
            'full_name' => 'Liya Girmay',
            'office' => 'Research',
            'department' => 'Grants Office',
            'role' => 'Team Lead',
        ],
        'lead.students@pms.test' => [
            'full_name' => 'Abel Desta',
            'office' => 'Student Services',
            'department' => 'Campus Life',
            'role' => 'Team Lead',
        ],

        // Staff / Team Members
        'staff.ict@pms.test' => [
            'full_name' => 'Kalkidan Tadesse',
            'office' => 'ICT Directorate',
            'department' => 'ICT',
            'role' => 'Team Member',
        ],
        'staff.ict2@pms.test' => [
            'full_name' => 'Nahom Solomon',
            'office' => 'ICT Directorate',
            'department' => 'Network Operations',
            'role' => 'Team Member',
        ],
        'staff.academic@pms.test' => [
            'full_name' => 'Rahel Mengistu',
            'office' => 'Academic Affairs',
            'department' => 'Curriculum',
            'role' => 'Team Member',
        ],
        'staff.finance@pms.test' => [
            'full_name' => 'Eyob Fikru',
            'office' => 'Finance Directorate',
            'department' => 'Accounts',
            'role' => 'Team Member',
        ],
        'staff.procurement@pms.test' => [
            'full_name' => 'Tsion Assefa',
            'office' => 'Procurement',
            'department' => 'Tendering',
            'role' => 'Team Member',
        ],
        'staff.research@pms.test' => [
            'full_name' => 'Fikir Yohannes',
            'office' => 'Research',
            'department' => 'Data Analysis',
            'role' => 'Team Member',
        ],
        'staff.students@pms.test' => [
            'full_name' => 'Mikiyas Wolde',
            'office' => 'Student Services',
            'department' => 'Welfare',
            'role' => 'Team Member',
        ],
    ];

    public function run(): void
    {
        $officeIds = Office::pluck('office_id', 'office_name');
        $firstOfficeId = $officeIds->first();

        foreach (self::USERS as $email => $def) {
            // Admins should be assigned to the first office for UI consistency,
            // but they retain global access via their Administrator role.
            $officeId = null;
            if ($def['office']) {
                $officeId = $officeIds[$def['office']] ?? null;
            } elseif ($def['role'] === 'Administrator' && $firstOfficeId) {
                // Assign admin to first office for UI convenience,
                // though their global access is controlled by the Administrator role.
                $officeId = $firstOfficeId;
            }

            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'full_name' => $def['full_name'],
                    'password_hash' => Hash::make(self::DEMO_PASSWORD),
                    'phone' => null,
                    'department' => $def['department'],
                    'status' => 'Active',
                    'role' => 'staff',
                    'office_id' => $officeId,
                ]
            );

            $role = Role::where('role_name', $def['role'])->firstOrFail();
            $user->roles()->syncWithoutDetaching([
                $role->role_id => ['scope_type' => null, 'scope_id' => null],
            ]);
        }

        // Make the first user of each seeded office its department head.
        foreach ($officeIds as $name => $officeId) {
            $head = User::where('office_id', $officeId)
                ->whereIn('email', [
                    'pm.ict@pms.test',
                    'pm.academic@pms.test',
                    'pm.finance@pms.test',
                    'pm.research@pms.test',
                    'lead.procurement@pms.test',
                    'lead.students@pms.test',
                ])
                ->first();

            if ($head) {
                Office::where('office_id', $officeId)->update(['head_user_id' => $head->user_id]);
            }
        }
    }
}
