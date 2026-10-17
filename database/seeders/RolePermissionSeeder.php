<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create roles
        $roleGuest = Role::firstOrCreate(['name' => 'guest']);
        $roleUser = Role::firstOrCreate(['name' => 'user']);
        $roleManager = Role::firstOrCreate(['name' => 'admin']);

        // Create permissions
        $permissionUseEnrollmentPortal = Permission::firstOrCreate(['name' => 'use enrollment portal']);
        $permissionViewReport = Permission::firstOrCreate(['name' => 'view report']);
        $permissionExtractReport = Permission::firstOrCreate(['name' => 'extract report']);
        $permissionManageEverything = Permission::firstOrCreate(['name' => 'manage everything']);

        // Assign permissions to roles
        $roleGuest->givePermissionTo($permissionUseEnrollmentPortal);
        $roleUser->givePermissionTo([$permissionViewReport, $permissionExtractReport]);
        $roleManager->givePermissionTo([$permissionManageEverything]);
    }
}
