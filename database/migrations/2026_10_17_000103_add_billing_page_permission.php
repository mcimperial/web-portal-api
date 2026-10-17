<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $permissionId = DB::table('permissions')->updateOrInsert(
            ['slug' => 'page.self-enrollment.billing'],
            [
                'name' => 'Billing Import',
                'description' => 'Access self-enrollment billing import page',
                'app' => 'self-enrollment',
                'sub_app' => 'page-billing',
                'link' => 'self-enrollment/billing',
                'is_system' => true,
            ]
        );

        $billingPermissionId = DB::table('permissions')
            ->where('slug', 'page.self-enrollment.billing')
            ->value('id');

        if (!$billingPermissionId || !Schema::hasTable('permission_role') || !Schema::hasTable('roles')) {
            return;
        }

        $roleIds = DB::table('roles')
            ->whereIn('slug', ['admin', 'admin-se', 'manager-se'])
            ->pluck('id')
            ->all();

        foreach ($roleIds as $roleId) {
            DB::table('permission_role')->updateOrInsert(
                [
                    'role_id' => $roleId,
                    'permission_id' => $billingPermissionId,
                ],
                []
            );
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $billingPermissionId = DB::table('permissions')
            ->where('slug', 'page.self-enrollment.billing')
            ->value('id');

        if ($billingPermissionId && Schema::hasTable('permission_role')) {
            DB::table('permission_role')
                ->where('permission_id', $billingPermissionId)
                ->delete();
        }

        DB::table('permissions')
            ->where('slug', 'page.self-enrollment.billing')
            ->delete();
    }
};
