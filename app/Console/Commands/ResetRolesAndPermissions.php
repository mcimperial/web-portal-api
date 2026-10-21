<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\RbacPermissionMatrixSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResetRolesAndPermissions extends Command
{
    use ConfirmableTrait;

    protected $signature = 'rbac:reset-roles-permissions {--force : Force the operation in production}';

    protected $description = 'Truncate RBAC tables, reseed roles/permissions, and remap only admin/admin-se users from legacy users.role';

    public function handle(): int
    {
        if (! $this->confirmToProceed(
            'This will truncate RBAC role/permission tables and replace them with seeded data.',
            fn () => (bool) $this->option('force')
        )) {
            return self::FAILURE;
        }

        try {
            Schema::disableForeignKeyConstraints();

            DB::table('user_role')->truncate();
            DB::table('permission_role')->truncate();
            DB::table('permissions')->truncate();
            DB::table('roles')->truncate();
        } catch (\Throwable $e) {
            Schema::enableForeignKeyConstraints();
            $this->error('RBAC reset failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        Schema::enableForeignKeyConstraints();

        $exitCode = $this->call('db:seed', [
            '--class' => RolesAndPermissionsSeeder::class,
            '--force' => true,
        ]);

        if ($exitCode !== self::SUCCESS) {
            $this->error('Base roles/permissions seeding failed after truncation.');

            return self::FAILURE;
        }

        Permission::query()->updateOrCreate(
            ['slug' => 'self-enrollment.enrollees.manage-notifications'],
            [
                'name' => 'Manage Notifications',
                'description' => 'Show Manage Notifications button/actions for enrollees',
                'app' => 'self-enrollment',
                'sub_app' => 'enrollees-manage-notifications',
                'link' => null,
                'is_system' => true,
            ]
        );

        $exitCode = $this->call('db:seed', [
            '--class' => RbacPermissionMatrixSeeder::class,
            '--force' => true,
        ]);

        if ($exitCode !== self::SUCCESS) {
            $this->error('Seeding failed after truncation.');

            return self::FAILURE;
        }

        $roleIdBySlug = Role::query()->pluck('id', 'slug');
        $adminSeRoleId = $roleIdBySlug->get('admin-se');
        $adminRoleId = $roleIdBySlug->get('admin');

        $legacyAssignments = User::query()
            ->select('id', 'role')
            ->whereNotNull('role')
            ->whereIn(DB::raw('LOWER(TRIM(role))'), ['admin', 'admin-se'])
            ->get()
            ->map(function (User $user) use ($adminRoleId, $adminSeRoleId) {
                $slug = strtolower(trim((string) $user->role));
                $roleId = $slug === 'admin-se' ? $adminSeRoleId : $adminRoleId;

                return [
                    'user_id' => $user->id,
                    'role_id' => $roleId,
                ];
            })
            ->filter(fn (array $row) => ! empty($row['role_id']))
            ->values()
            ->all();

        if (! empty($legacyAssignments)) {
            DB::table('user_role')->insertOrIgnore($legacyAssignments);
        }

        $this->info('Roles and permissions were reset; user_role was truncated and only legacy admin/admin-se users were remapped.');

        return self::SUCCESS;
    }
}
