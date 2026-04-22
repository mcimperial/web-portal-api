<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Add the permission for the Third-party API Credentials admin page
        $permission = Permission::firstOrCreate(
            ['slug' => 'manage-third-party-api'],
            [
                'name'        => 'Manage Third-Party API',
                'slug'        => 'manage-third-party-api',
                'description' => 'Manage third-party API credentials',
                'app'         => 'third-party',
                'link'        => 'self-enrollment/manage-api-credentials',
                'sub_app'     => 'admin',
            ]
        );

        // Attach to the "admin-se" (Administrator) role and any "admin" role
        Role::whereIn('slug', ['admin-se', 'admin'])->each(function (Role $role) use ($permission) {
            if (!$role->permissions()->where('permissions.id', $permission->id)->exists()) {
                $role->permissions()->attach($permission->id);
            }
        });
    }

    public function down(): void
    {
        $permission = Permission::where('slug', 'manage-third-party-api')->first();
        if ($permission) {
            $permission->roles()->detach();
            $permission->delete();
        }
    }
};
