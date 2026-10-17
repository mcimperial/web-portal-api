<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Create or update roles
        $adminRole = Role::query()->updateOrCreate(
            ['slug' => 'admin-se'],
            [
                'name' => 'Administrator',
                'description' => 'System Administrator',
            ]
        );

        $managerRole = Role::query()->updateOrCreate(
            ['slug' => 'manager-se'],
            [
                'name' => 'Manager: Self Enrollment',
                'description' => 'Department Manager Self Enrollment',
            ]
        );

        $employeeRole = Role::query()->updateOrCreate(
            ['slug' => 'employee-se'],
            [
                'name' => 'Employee: Self Enrollment',
                'description' => 'Regular Employee Self Enrollment',
            ]
        );

        $viewerRole = Role::query()->updateOrCreate(
            ['slug' => 'viewer-se'],
            [
                'name' => 'Viewer: Self Enrollment',
                'description' => 'Viewer Self Enrollment',
            ]
        );

        Role::query()->updateOrCreate(
            ['slug' => 'guest'],
            [
                'name' => 'Guest',
                'description' => 'Guest User',
            ]
        );

        // Create permissions with app and sub_app
        $permissions = [
            // Dashboard
            [
                'name' => 'Manage Dashboard',
                'slug' => 'manage-dashboard',
                'description' => 'Access dashboard',
                'app' => 'dashboard',
                'link' => 'dashboard',
                'sub_app' => null,
            ],

            // Self Enrollment - Admin
            [
                'name' => 'Enrollment: Admin',
                'slug' => 'self-enrollment-admin',
                'description' => 'Admin access to self-enrollment',
                'app' => 'self-enrollment',
                'link' => 'self-enrollment/manage-admin-access',
                'sub_app' => 'admin-se',
            ],

            // Self Enrollment - Manager
            [
                'name' => 'Manage Enrollment',
                'slug' => 'self-enrollment-manager',
                'description' => 'Manage self-enrollment using manager role',
                'app' => 'self-enrollment',
                'link' => 'self-enrollment/manage',
                'sub_app' => 'manager-se',
            ],
            [
                'name' => 'Enrollment Storage',
                'slug' => 'self-enrollment-enrollment-storage',
                'description' => 'View enrollment storage using manager role',
                'app' => 'self-enrollment',
                'link' => 'self-enrollment/manage-enrollment-storage',
                'sub_app' => 'manager-se',
            ],
            [
                'name' => 'Deleted Enrollees',
                'slug' => 'self-enrollment-deleted-enrollees',
                'description' => 'Manage deleted enrollees using manager role',
                'app' => 'self-enrollment',
                'link' => 'self-enrollment/manage-deleted-enrollees',
                'sub_app' => 'manager-se',
            ],

            // Self Enrollment - Employee
            [
                'name' => 'Manage Enrollment',
                'slug' => 'self-enrollment-employee',
                'description' => 'Manage self-enrollment using employee role',
                'app' => 'self-enrollment',
                'link' => 'self-enrollment/manage',
                'sub_app' => 'employee-se',
            ],
            [
                'name' => 'Enrollment Storage',
                'slug' => 'self-enrollment-enrollment-storage',
                'description' => 'View enrollment storage using employee role',
                'app' => 'self-enrollment',
                'link' => 'self-enrollment/manage-enrollment-storage',
                'sub_app' => 'employee-se',
            ],

            // Self Enrollment - Viewer
            [
                'name' => 'View Enrollment',
                'slug' => 'self-enrollment-viewer',
                'description' => 'View self-enrollment using viewer role',
                'app' => 'self-enrollment',
                'link' => 'self-enrollment/manage',
                'sub_app' => 'viewer-se',
            ],

            // HR Portal (example, add more as needed)
            [
                'name' => 'Manage HR Portal',
                'slug' => 'manage-hr-portal',
                'description' => 'Access HR portal',
                'app' => 'hr-portal',
                'link' => 'hr-portal',
                'sub_app' => null,
            ],
        ];

        // Create or update permissions
        foreach ($permissions as $perm) {
            Permission::query()->updateOrCreate(
                [
                    'slug' => $perm['slug'],
                    'sub_app' => $perm['sub_app'],
                ],
                $perm
            );
        }

        // Assign permissions to roles
        $adminRole->permissions()->syncWithoutDetaching(
            Permission::query()
                ->whereIn('app', ['self-enrollment', 'dashboard', 'hr-portal'])
                ->pluck('id')
                ->all()
        );

        $managerRole->permissions()->syncWithoutDetaching(Permission::query()->where(function ($q) {
            $q->where('sub_app', 'manager-se')->orWhere('app', 'dashboard');
        })->pluck('id')->all());

        $employeeRole->permissions()->syncWithoutDetaching(Permission::query()->where(function ($q) {
            $q->where('sub_app', 'employee-se')->orWhere('app', 'dashboard');
        })->pluck('id')->all());
    }
}
