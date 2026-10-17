<?php

namespace App\Console\Commands;

use App\Models\Role;
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

    protected $description = 'Truncate roles/permissions RBAC tables and reseed full RBAC permissions while preserving user-role links';

    public function handle(): int
    {
        if (! $this->confirmToProceed(
            'This will truncate RBAC role/permission tables and replace them with seeded data.',
            fn () => (bool) $this->option('force')
        )) {
            return self::FAILURE;
        }

        $userRoleAssignments = DB::table('user_role')
            ->join('roles', 'roles.id', '=', 'user_role.role_id')
            ->select('user_role.user_id', 'roles.slug')
            ->get();

        try {
            Schema::disableForeignKeyConstraints();

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

        $restoredAssignments = [];

        foreach ($userRoleAssignments as $assignment) {
            $roleId = $roleIdBySlug->get($assignment->slug);

            if (! $roleId && $assignment->slug === 'admin' && $adminSeRoleId) {
                $roleId = $adminSeRoleId;
            }

            if ($roleId) {
                $restoredAssignments[] = [
                    'user_id' => $assignment->user_id,
                    'role_id' => $roleId,
                ];
            }
        }

        Schema::disableForeignKeyConstraints();
        DB::table('user_role')->truncate();
        Schema::enableForeignKeyConstraints();

        if (! empty($restoredAssignments)) {
            DB::table('user_role')->insertOrIgnore($restoredAssignments);
        }

        $legacyAssignments = User::query()
            ->select('id', 'role')
            ->whereNotNull('role')
            ->get()
            ->map(function (User $user) use ($roleIdBySlug, $adminSeRoleId) {
                $slug = strtolower(trim((string) $user->role));
                $roleId = $roleIdBySlug->get($slug);

                if (! $roleId && $slug === 'admin' && $adminSeRoleId) {
                    $roleId = $adminSeRoleId;
                }

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

        Schema::disableForeignKeyConstraints();
        DB::table('user_role')->insertOrIgnore([
            'user_id' => 1,
            'role_id' => 1,
        ]);
        Schema::enableForeignKeyConstraints();

        $this->info('Roles and permissions were reset, user-role assignments restored, and user 1 was mapped to role 1.');

        return self::SUCCESS;
    }
}
