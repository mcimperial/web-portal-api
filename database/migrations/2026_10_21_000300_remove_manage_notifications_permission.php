<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $permissionId = DB::table('permissions')
            ->where('slug', 'self-enrollment.enrollees.manage-notifications')
            ->value('id');

        if (!$permissionId) {
            return;
        }

        if (Schema::hasTable('permission_role')) {
            DB::table('permission_role')
                ->where('permission_id', $permissionId)
                ->delete();
        }

        DB::table('permissions')
            ->where('id', $permissionId)
            ->delete();
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $existingId = DB::table('permissions')
            ->where('slug', 'self-enrollment.enrollees.manage-notifications')
            ->value('id');

        if ($existingId) {
            return;
        }

        DB::table('permissions')->insert([
            'name' => 'Manage Notifications',
            'slug' => 'self-enrollment.enrollees.manage-notifications',
            'description' => 'Show Manage Notifications button/actions for enrollees',
            'app' => 'self-enrollment',
            'sub_app' => 'enrollees-manage-notifications',
            'link' => null,
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
