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
        $roleGuest = Role::create(['name' => 'guest']);
        $roleUser = Role::create(['name' => 'user']);
        $roleManager = Role::create(['name' => 'admin']);

        // Create permissions
        $permissionUseEnrollmentPortal = Permission::create(['name' => 'use enrollment portal']);
        $permissionViewReport = Permission::create(['name' => 'view report']);
        $permissionExtractReport = Permission::create(['name' => 'extract report']);
        $permissionManageEverything = Permission::create(['name' => 'manage everything']);

        // Assign permissions to roles
        $roleGuest->givePermissionTo($permissionUseEnrollmentPortal);
        $roleUser->givePermissionTo([$permissionViewReport, $permissionExtractReport]);
        $roleManager->givePermissionTo(Permission::all());
    }
}
