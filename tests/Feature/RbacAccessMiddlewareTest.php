<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RbacAccessMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth:sanctum', 'rbac:users,view'])
            ->get('/api/test-rbac-users-view', fn() => response()->json(['ok' => true]));

        Route::middleware(['auth:sanctum', 'rbac:users,export'])
            ->get('/api/test-rbac-users-export', fn() => response()->json(['ok' => true]));
    }

    public function test_user_with_required_permission_can_access_route(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['name' => 'Test Role', 'slug' => 'test-role']);
        $permission = Permission::create([
            'name' => 'USERS: VIEW',
            'slug' => 'users.view',
            'app' => 'users',
            'sub_app' => 'view',
        ]);

        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id);

        Sanctum::actingAs($user);

        $this->getJson('/api/test-rbac-users-view')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_user_without_required_permission_is_forbidden(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['name' => 'No Access Role', 'slug' => 'no-access-role']);
        $user->roles()->attach($role->id);

        Sanctum::actingAs($user);

        $this->getJson('/api/test-rbac-users-view')
            ->assertForbidden();
    }

    public function test_export_permission_requires_view_permission(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['name' => 'Export Role', 'slug' => 'export-role']);
        $permission = Permission::create([
            'name' => 'USERS: EXPORT',
            'slug' => 'users.export',
            'app' => 'users',
            'sub_app' => 'export',
        ]);

        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id);

        Sanctum::actingAs($user);

        $this->getJson('/api/test-rbac-users-export')
            ->assertForbidden();
    }

    public function test_inactive_user_is_forbidden_even_with_permission(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $role = Role::create(['name' => 'Inactive User Role', 'slug' => 'inactive-user-role']);
        $permission = Permission::create([
            'name' => 'USERS: VIEW',
            'slug' => 'users.view',
            'app' => 'users',
            'sub_app' => 'view',
        ]);

        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id);

        Sanctum::actingAs($user);

        $this->getJson('/api/test-rbac-users-view')
            ->assertForbidden();
    }
}
