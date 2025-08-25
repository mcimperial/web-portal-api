<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create Admin User (if doesn't exist)
        $adminUser = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'role' => 'admin-se',
                'name' => 'Admin User',
                'password' => Hash::make('password123'),
                'email_verified_at' => now()
            ]
        );
        $adminUser->roles()->sync(Role::where('slug', 'admin-se')->first()->id);

        // Create Manager User
        $managerUser = User::firstOrCreate(
            ['email' => 'manager@example.com'],
            [
                'role' => 'manager-se',
                'name' => 'Manager User',
                'password' => Hash::make('password123'),
                'email_verified_at' => now()
            ]
        );
        $managerUser->roles()->sync(Role::where('slug', 'manager-se')->first()->id);

        // Create Employee Users
        $employee1 = User::firstOrCreate(
            ['email' => 'employee@example.com'],
            [
                'role' => 'employee-se',
                'name' => 'John Employee',
                'password' => Hash::make('password123'),
                'email_verified_at' => now()
            ]
        );
        $employee1->roles()->sync(Role::where('slug', 'employee-se')->first()->id);

        $employee2 = User::firstOrCreate(
            ['email' => 'guest@example.com'],
            [
                'role' => 'guest',
                'name' => 'Jane Guest',
                'password' => Hash::make('password123'),
                'email_verified_at' => now()
            ]
        );
        $employee2->roles()->sync(Role::where('slug', 'guest')->first()->id);

        // Create a user with multiple roles (Manager + Employee)
        $multiRoleUser = User::firstOrCreate(
            ['email' => 'multi@example.com'],
            [
                'role' => 'multi-role',
                'name' => 'Multi Role User',
                'password' => Hash::make('password123'),
                'email_verified_at' => now()
            ]
        );
        $multiRoleUser->roles()->sync([
            Role::where('slug', 'manager-se')->first()->id,
            Role::where('slug', 'employee-se')->first()->id
        ]);
    }
}
