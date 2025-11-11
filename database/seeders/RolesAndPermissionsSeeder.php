<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Create roles
        $adminRole = Role::create([
            'name' => 'Administrator',
            'slug' => 'admin-se',
            'description' => 'System Administrator'
        ]);

        $managerRole = Role::create([
            'name' => 'Manager: Self Enrollment',
            'slug' => 'manager-se',
            'description' => 'Department Manager Self Enrollment'
        ]);

        $employeeRole = Role::create([
            'name' => 'Employee: Self Enrollment',
            'slug' => 'employee-se',
            'description' => 'Regular Employee Self Enrollment'
        ]);

        $viewerRole = Role::create([
            'name' => 'Viewer: Self Enrollment',
            'slug' => 'viewer-se',
            'description' => 'Viewer Self Enrollment'
        ]);

        $guestRole = Role::create([
            'name' => 'Guest',
            'slug' => 'guest',
            'description' => 'Guest User'
        ]);

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

        // Create permissions
        foreach ($permissions as $perm) {
            Permission::create($perm);
        }

        // Assign permissions to roles
        $adminRole->permissions()->attach(Permission::where('app', 'self-enrollment')->get());
        $adminRole->permissions()->attach(Permission::where('app', 'dashboard')->get());
        $adminRole->permissions()->attach(Permission::where('app', 'hr-portal')->get());

        $managerRole->permissions()->attach(Permission::where(function ($q) {
            $q->where('sub_app', 'manager-se')->orWhere('app', 'dashboard');
        })->get());

        $employeeRole->permissions()->attach(Permission::where(function ($q) {
            $q->where('sub_app', 'employee-se')->orWhere('app', 'dashboard');
        })->get());
    }
}
