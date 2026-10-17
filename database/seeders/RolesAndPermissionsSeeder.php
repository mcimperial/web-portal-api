<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'slug' => 'admin',
                'name' => 'Administrator',
                'description' => 'System Administrator',
            ],
            [
                'slug' => 'admin-se',
                'name' => 'Administrator: Self Enrollment',
                'description' => 'Self Enrollment Administrator',
            ],
            [
                'slug' => 'manager',
                'name' => 'Manager: Self Enrollment',
                'description' => 'Department Manager Self Enrollment',
            ],
            [
                'slug' => 'employee',
                'name' => 'Employee: Self Enrollment',
                'description' => 'Regular Employee Self Enrollment',
            ],
            [
                'slug' => 'viewer',
                'name' => 'Viewer: Self Enrollment',
                'description' => 'Viewer Self Enrollment',
            ],
            [
                'slug' => 'hr',
                'name' => 'HR: Self Enrollment',
                'description' => 'HR Read-Only Self Enrollment',
            ],
            [
                'slug' => 'guest',
                'name' => 'Guest',
                'description' => 'Guest User',
            ],
        ];

        foreach ($roles as $role) {
            Role::query()->updateOrCreate(
                ['slug' => $role['slug']],
                [
                    'name' => $role['name'],
                    'description' => $role['description'],
                ]
            );
        }
    }
}
