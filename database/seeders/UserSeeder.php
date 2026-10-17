<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminUser = User::query()->firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin User',
                'role' => 'admin',
                'password' => Hash::make('password123'),
                'email_verified_at' => now(),
                'is_active' => true,
            ]
        );

        $adminRole = Role::query()
            ->whereIn('slug', ['admin', 'admin-se'])
            ->orderByRaw("CASE WHEN slug = 'admin' THEN 0 ELSE 1 END")
            ->first();

        if (!$adminRole) {
            $adminRole = Role::query()->create([
                'name' => 'Administrator',
                'slug' => 'admin',
                'description' => 'System Administrator',
            ]);

            $allPermissionIds = Permission::query()->pluck('id')->all();
            if (!empty($allPermissionIds)) {
                $adminRole->permissions()->syncWithoutDetaching($allPermissionIds);
            }
        }

        $adminUser->roles()->syncWithoutDetaching([$adminRole->id]);
    }
}
