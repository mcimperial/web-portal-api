<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccessRolePermissionDependenciesTest extends TestCase
{
    use RefreshDatabase;

    public function test_manage_enrollment_page_does_not_auto_grant_crud_permissions(): void
    {
        $actor = $this->actingAsRoleManager();

        $managePage = $this->createPermission('page.self-enrollment.manage', 'self-enrollment', 'page-manage-enrollment');
        $enrollmentsView = $this->createPermission('enrollments.view', 'enrollments', 'view');
        $enrollmentsCreate = $this->createPermission('enrollments.create', 'enrollments', 'create');
        $enrollmentsUpdate = $this->createPermission('enrollments.update', 'enrollments', 'update');
        $enrollmentsDelete = $this->createPermission('enrollments.delete', 'enrollments', 'delete');
        $enrollmentsExport = $this->createPermission('enrollments.export', 'enrollments', 'export');

        Sanctum::actingAs($actor);

        $response = $this->postJson('/api/v1/access/roles', [
            'name' => 'Manage Enrollment Page Only',
            'slug' => 'manage-enrollment-page-only',
            'permission_ids' => [$managePage->id],
        ])->assertCreated();

        $permissionSlugs = collect($response->json('permissions'))->pluck('slug')->all();

        $this->assertContains($managePage->slug, $permissionSlugs);
        $this->assertContains($enrollmentsView->slug, $permissionSlugs);
        $this->assertNotContains($enrollmentsCreate->slug, $permissionSlugs);
        $this->assertNotContains($enrollmentsUpdate->slug, $permissionSlugs);
        $this->assertNotContains($enrollmentsDelete->slug, $permissionSlugs);
        $this->assertNotContains($enrollmentsExport->slug, $permissionSlugs);
    }

    public function test_manage_notifications_does_not_auto_grant_notification_actions(): void
    {
        $actor = $this->actingAsRoleManager();

        $manageNotifications = $this->createPermission('self-enrollment.enrollees.manage-notifications', 'self-enrollment', 'enrollees-manage-notifications');
        $viewNotification = $this->createPermission('self-enrollment.enrollees.notifications.view', 'self-enrollment', 'enrollees-notifications-view');
        $sendNotification = $this->createPermission('self-enrollment.enrollees.notifications.send', 'self-enrollment', 'enrollees-notifications-send');
        $createNotification = $this->createPermission('self-enrollment.enrollees.notifications.create', 'self-enrollment', 'enrollees-notifications-create');
        $updateNotification = $this->createPermission('self-enrollment.enrollees.notifications.update', 'self-enrollment', 'enrollees-notifications-update');
        $deleteNotification = $this->createPermission('self-enrollment.enrollees.notifications.delete', 'self-enrollment', 'enrollees-notifications-delete');

        Sanctum::actingAs($actor);

        $response = $this->postJson('/api/v1/access/roles', [
            'name' => 'Manage Notifications Only',
            'slug' => 'manage-notifications-only',
            'permission_ids' => [$manageNotifications->id],
        ])->assertCreated();

        $permissionSlugs = collect($response->json('permissions'))->pluck('slug')->all();

        $this->assertContains($manageNotifications->slug, $permissionSlugs);
        $this->assertNotContains($viewNotification->slug, $permissionSlugs);
        $this->assertNotContains($sendNotification->slug, $permissionSlugs);
        $this->assertNotContains($createNotification->slug, $permissionSlugs);
        $this->assertNotContains($updateNotification->slug, $permissionSlugs);
        $this->assertNotContains($deleteNotification->slug, $permissionSlugs);
    }

    public function test_notification_action_keeps_communication_dependency(): void
    {
        $actor = $this->actingAsRoleManager();

        $notificationView = $this->createPermission('self-enrollment.enrollees.notifications.view', 'self-enrollment', 'enrollees-notifications-view');
        $communicationsView = $this->createPermission('communications.view', 'communications', 'view');

        Sanctum::actingAs($actor);

        $response = $this->postJson('/api/v1/access/roles', [
            'name' => 'Notification View Role',
            'slug' => 'notification-view-role',
            'permission_ids' => [$notificationView->id],
        ])->assertCreated();

        $permissionSlugs = collect($response->json('permissions'))->pluck('slug')->all();

        $this->assertContains($notificationView->slug, $permissionSlugs);
        $this->assertContains($communicationsView->slug, $permissionSlugs);
    }

    private function actingAsRoleManager(): User
    {
        $actor = User::factory()->create(['is_active' => true]);
        $actorRole = Role::create([
            'name' => 'Access Role Manager',
            'slug' => 'access-role-manager',
        ]);

        $rolesCreate = $this->createPermission('roles.create', 'roles', 'create');

        $actorRole->permissions()->attach($rolesCreate->id);
        $actor->roles()->attach($actorRole->id);

        return $actor;
    }

    private function createPermission(string $slug, string $app, string $subApp): Permission
    {
        return Permission::query()->create([
            'name' => strtoupper($slug),
            'slug' => $slug,
            'description' => $slug,
            'app' => $app,
            'sub_app' => $subApp,
            'is_system' => true,
        ]);
    }
}
