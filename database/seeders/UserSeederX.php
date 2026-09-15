<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;

class UserSeederX extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create Test User
        $testUser = User::firstOrCreate(
            ['email' => 'clarencederamos@example.com'],
            [
                'role' => 'employee-se',
                'name' => 'Clarence De Ramos',
                'password' => Hash::make('#@!RSASACLARENCE##@!'),
                'email_verified_at' => now()
            ]
        );
        $testUser->roles()->sync(Role::where('slug', 'employee-se')->first()->id);
    }
}
