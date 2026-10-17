<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RbacPermissionMatrixSeeder extends Seeder
{
    public function run(): void
    {
        $modules = config('rbac.modules', []);
        $actions = config('rbac.actions', []);

        foreach ($modules as $module) {
            foreach ($actions as $action) {
                $slug = "{$module}.{$action}";

                Permission::query()->updateOrCreate(
                    ['slug' => $slug],
                    [
                        'name' => strtoupper($module) . ': ' . strtoupper($action),
                        'description' => "{$action} access for {$module}",
                        'app' => $module,
                        'sub_app' => $action,
                        'link' => null,
                        'is_system' => true,
                    ]
                );
            }
        }

        $enrolleeUiPermissions = [
            [
                'slug' => 'self-enrollment.enrollees.add',
                'name' => 'Add Enrollees',
                'description' => 'Show Add Enrollees button/actions',
                'app' => 'self-enrollment',
                'sub_app' => 'enrollees-add',
            ],
            [
                'slug' => 'self-enrollment.enrollees.update',
                'name' => 'Update Enrollees',
                'description' => 'Show Update Enrollees button/actions',
                'app' => 'self-enrollment',
                'sub_app' => 'enrollees-update',
            ],
            [
                'slug' => 'self-enrollment.enrollees.delete',
                'name' => 'Delete Enrollees',
                'description' => 'Show Delete Enrollees button/actions',
                'app' => 'self-enrollment',
                'sub_app' => 'enrollees-delete',
            ],
            [
                'slug' => 'self-enrollment.enrollees.import',
                'name' => 'Import Enrollees',
                'description' => 'Show Import Enrollees button/actions',
                'app' => 'self-enrollment',
                'sub_app' => 'enrollees-import',
            ],
            [
                'slug' => 'self-enrollment.enrollees.compare-temp-import',
                'name' => 'Compare Temp Import vs Existing (Same Enrollment)',
                'description' => 'Show Compare Temp Import vs Existing (Same Enrollment) button/actions',
                'app' => 'self-enrollment',
                'sub_app' => 'enrollees-compare-temp-import',
            ],
            [
                'slug' => 'self-enrollment.enrollees.import-company-provider',
                'name' => 'Import Enrollees w/ Company & Provider',
                'description' => 'Show Import Enrollees W/ Company & Provider button/actions',
                'app' => 'self-enrollment',
                'sub_app' => 'enrollees-import-company-provider',
            ],
            [
                'slug' => 'self-enrollment.enrollees.manage-notifications',
                'name' => 'Manage Notifications',
                'description' => 'Show Manage Notifications button/actions for enrollees',
                'app' => 'self-enrollment',
                'sub_app' => 'enrollees-manage-notifications',
            ],
            [
                'slug' => 'self-enrollment.enrollees.card-delivery',
                'name' => 'Card Delivery',
                'description' => 'Show Card Delivery button/actions for enrollees',
                'app' => 'self-enrollment',
                'sub_app' => 'enrollees-card-delivery',
            ],
            [
                'slug' => 'self-enrollment.enrollees.export-legacy',
                'name' => 'Export Legacy',
                'description' => 'Show original export button/options for enrollees',
                'app' => 'self-enrollment',
                'sub_app' => 'enrollees-export-legacy',
            ],
            [
                'slug' => 'self-enrollment.enrollees.export-viewer',
                'name' => 'Export Viewer',
                'description' => 'Show HR/viewer export button/options for enrollees',
                'app' => 'self-enrollment',
                'sub_app' => 'enrollees-export-viewer',
            ],
            [
                'slug' => 'self-enrollment.enrollees.table-legacy',
                'name' => 'Table Legacy',
                'description' => 'Show original enrollees table and filters',
                'app' => 'self-enrollment',
                'sub_app' => 'enrollees-table-legacy',
            ],
            [
                'slug' => 'self-enrollment.enrollees.table-viewer',
                'name' => 'Table Viewer',
                'description' => 'Show HR/viewer enrollees table and filters',
                'app' => 'self-enrollment',
                'sub_app' => 'enrollees-table-viewer',
            ],
        ];

        foreach ($enrolleeUiPermissions as $permission) {
            Permission::query()->updateOrCreate(
                ['slug' => $permission['slug']],
                [
                    'name' => $permission['name'],
                    'description' => $permission['description'],
                    'app' => $permission['app'],
                    'sub_app' => $permission['sub_app'],
                    'link' => null,
                    'is_system' => true,
                ]
            );
        }

        $pagePermissions = [
            [
                'slug' => 'page.dashboard',
                'name' => 'Dashboard',
                'description' => 'Access dashboard page',
                'app' => 'dashboard',
                'sub_app' => 'page-dashboard',
                'link' => 'dashboard',
            ],
            [
                'slug' => 'page.self-enrollment.admin-access',
                'name' => 'Enrollment: Admin',
                'description' => 'Access self-enrollment admin access page',
                'app' => 'self-enrollment',
                'sub_app' => 'page-admin-access',
                'link' => 'self-enrollment/manage-admin-access',
            ],
            [
                'slug' => 'page.self-enrollment.manage',
                'name' => 'Manage Enrollment',
                'description' => 'Access self-enrollment manage page',
                'app' => 'self-enrollment',
                'sub_app' => 'page-manage-enrollment',
                'link' => 'self-enrollment/manage',
            ],
            [
                'slug' => 'page.self-enrollment.billing',
                'name' => 'Billing Import',
                'description' => 'Access self-enrollment billing import page',
                'app' => 'self-enrollment',
                'sub_app' => 'page-billing',
                'link' => 'self-enrollment/billing',
            ],
            [
                'slug' => 'page.self-enrollment.storage',
                'name' => 'Enrollment Storage',
                'description' => 'Access self-enrollment storage page',
                'app' => 'self-enrollment',
                'sub_app' => 'page-enrollment-storage',
                'link' => 'self-enrollment/manage-enrollment-storage',
            ],
            [
                'slug' => 'page.self-enrollment.deleted-enrollees',
                'name' => 'Deleted Enrollees',
                'description' => 'Access deleted enrollees page',
                'app' => 'self-enrollment',
                'sub_app' => 'page-deleted-enrollees',
                'link' => 'self-enrollment/manage-deleted-enrollees',
            ],
            [
                'slug' => 'page.settings.account',
                'name' => 'Account Settings',
                'description' => 'Access account settings page',
                'app' => 'settings',
                'sub_app' => 'page-account-settings',
                'link' => 'settings/account',
            ],
        ];

        foreach ($pagePermissions as $permission) {
            Permission::query()->updateOrCreate(
                ['slug' => $permission['slug']],
                [
                    'name' => $permission['name'],
                    'description' => $permission['description'],
                    'app' => $permission['app'],
                    'sub_app' => $permission['sub_app'],
                    'link' => $permission['link'],
                    'is_system' => true,
                ]
            );
        }

        $allPermissionIds = Permission::query()->pluck('id')->all();

        foreach (['admin', 'admin-se'] as $adminSlug) {
            $role = Role::where('slug', $adminSlug)->first();
            if (!$role) {
                continue;
            }

            $role->permissions()->syncWithoutDetaching($allPermissionIds);
        }

        $dependencyMap = config('rbac.permission_dependencies', []);
        $allActions = config('rbac.actions', ['view', 'export', 'create', 'update', 'delete']);
        $exportRequiresView = (bool) config('rbac.export_requires_view', true);

        if (!empty($dependencyMap) || $exportRequiresView) {
            Role::query()->with('permissions:id,slug')->get()->each(function (Role $role) use ($dependencyMap, $allActions, $exportRequiresView) {
                $selectedSlugs = $role->permissions
                    ->pluck('slug')
                    ->filter()
                    ->values()
                    ->all();

                if (empty($selectedSlugs)) {
                    return;
                }

                $dependencySlugs = [];

                foreach ($selectedSlugs as $selectedSlug) {
                    foreach ($dependencyMap as $sourceSlugPattern => $dependencies) {
                        if (!Str::is((string) $sourceSlugPattern, (string) $selectedSlug)) {
                            continue;
                        }

                        foreach ((array) $dependencies as $dependencySlug) {
                            if (is_string($dependencySlug) && $dependencySlug !== '') {
                                $dependencySlugs[] = $dependencySlug;
                            }
                        }
                    }
                }

                if ($exportRequiresView) {
                    foreach ($selectedSlugs as $selectedSlug) {
                        [$module, $action] = array_pad(explode('.', (string) $selectedSlug, 2), 2, null);
                        if ($module && in_array($action, $allActions, true) && $action === 'export') {
                            $dependencySlugs[] = "{$module}.view";
                        }
                    }
                }

                $dependencySlugs = array_values(array_unique(array_filter($dependencySlugs)));
                if (empty($dependencySlugs)) {
                    return;
                }

                $dependencyIds = Permission::query()
                    ->whereIn('slug', $dependencySlugs)
                    ->pluck('id')
                    ->all();

                if (!empty($dependencyIds)) {
                    $role->permissions()->syncWithoutDetaching($dependencyIds);
                }
            });
        }
    }
}
