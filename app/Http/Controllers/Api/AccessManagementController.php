<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\LogsActions;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Modules\ClientMasterlist\App\Services\EmailSender;
use Modules\ClientMasterlist\App\Models\Enrollment;
use Modules\ClientMasterlist\App\Models\EnrollmentRole;
use Throwable;

class AccessManagementController extends Controller
{
    use LogsActions;

    public function permissionCatalog(): JsonResponse
    {
        $modules = config('rbac.modules', []);
        $actions = config('rbac.actions', []);

        $permissions = Permission::query()
            ->orderBy('app')
            ->orderBy('name')
            ->get();

        $matrixPermissions = $permissions
            ->filter(fn(Permission $permission) => in_array($permission->app, $modules, true))
            ->filter(fn(Permission $permission) => in_array($permission->sub_app, $actions, true))
            ->values();

        return response()->json([
            'modules' => $modules,
            'actions' => $actions,
            'presets' => config('rbac.presets', []),
            'export_requires_view' => (bool) config('rbac.export_requires_view', true),
            'permission_dependencies' => config('rbac.permission_dependencies', []),
            'permissions' => $permissions,
            'matrix_permissions' => $matrixPermissions,
        ]);
    }

    public function listRoles(): JsonResponse
    {
        $roles = Role::with('permissions')->orderBy('name')->get();
        return response()->json($roles);
    }

    public function createRole(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:roles,slug'],
            'description' => ['nullable', 'string', 'max:500'],
            'permission_ids' => ['array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        $permissionIds = $this->normalizeRolePermissionIds($validated['permission_ids'] ?? []);

        $role = DB::transaction(function () use ($validated, $permissionIds) {
            $role = Role::create([
                'name' => $validated['name'],
                'slug' => $validated['slug'] ?? Str::slug($validated['name']),
                'description' => $validated['description'] ?? null,
            ]);

            $role->permissions()->sync($permissionIds);
            return $role->load('permissions');
        });

        $this->logCreate($role, ['action' => 'role_create']);

        return response()->json($role, 201);
    }

    public function updateRole(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:roles,slug,' . $role->id],
            'description' => ['nullable', 'string', 'max:500'],
            'permission_ids' => ['array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        $actor = $request->user();
        $this->ensureRoleIsManageableByActor($actor, $role);
        $permissionIds = $this->normalizeRolePermissionIds($validated['permission_ids'] ?? []);

        $old = $role->toArray();

        DB::transaction(function () use ($role, $validated, $permissionIds) {
            $role->update([
                'name' => $validated['name'],
                'slug' => $validated['slug'],
                'description' => $validated['description'] ?? null,
            ]);

            $role->permissions()->sync($permissionIds);
        });

        $role->load('permissions');
        $this->logUpdate($role, $old, ['action' => 'role_update']);

        return response()->json($role);
    }

    public function duplicateRole(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:roles,slug'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $actor = $request->user();
        $this->ensureRoleIsManageableByActor($actor, $role);

        $duplicate = DB::transaction(function () use ($role, $validated) {
            $copy = Role::create([
                'name' => $validated['name'],
                'slug' => $validated['slug'] ?? Str::slug($validated['name']),
                'description' => $validated['description'] ?? $role->description,
            ]);

            $permissionIds = $this->normalizeRolePermissionIds($role->permissions()->pluck('permissions.id')->all());
            $copy->permissions()->sync($permissionIds);
            return $copy->load('permissions');
        });

        $this->logCreate($duplicate, ['action' => 'role_duplicate', 'source_role_id' => $role->id]);

        return response()->json($duplicate, 201);
    }

    public function deleteRole(Request $request, Role $role): JsonResponse
    {
        $actor = $request->user();
        $this->ensureRoleIsManageableByActor($actor, $role);

        $assignedUsersCount = $role->users()->count();
        if ($assignedUsersCount > 0) {
            return response()->json([
                'message' => "Role cannot be deleted while assigned to {$assignedUsersCount} user(s).",
            ], 422);
        }

        $old = $role->load('permissions')->toArray();

        DB::transaction(function () use ($role) {
            $role->permissions()->detach();
            $role->delete();
        });

        $this->logAction('DELETE', Role::class, $old, null, null, [
            'action' => 'role_delete',
            'role_id' => $old['id'] ?? null,
            'role_slug' => $old['slug'] ?? null,
            'role_name' => $old['name'] ?? null,
        ]);

        return response()->json(['message' => 'Role deleted successfully.']);
    }

    public function listUsers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,inactive'],
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'enrollment_id' => ['nullable', 'integer', 'exists:cm_enrollment,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = User::query()->with(['roles.permissions', 'enrollmentAssignments.enrollment']);

        if (!empty($validated['search'])) {
            $search = trim($validated['search']);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (!empty($validated['status'])) {
            $isActive = $validated['status'] === 'active';
            $query->where('is_active', $isActive);
        }

        if (!empty($validated['role_id'])) {
            $roleId = (int) $validated['role_id'];
            $query->whereHas('roles', fn($q) => $q->where('roles.id', $roleId));
        }

        if (!empty($validated['enrollment_id'])) {
            $enrollmentId = (int) $validated['enrollment_id'];
            $query->whereHas('enrollmentAssignments', fn($q) => $q->where('enrollment_id', $enrollmentId));
        }

        $users = $query->orderBy('name')->paginate($validated['per_page'] ?? 15);

        $users->getCollection()->transform(function (User $user) {
            $user->enrollment_ids = $user->enrollmentIds();
            return $user;
        });

        return response()->json($users);
    }

    public function showUser(User $user): JsonResponse
    {
        $user->load(['roles.permissions', 'enrollmentAssignments.enrollment']);
        $user->enrollment_ids = $user->enrollmentIds();

        return response()->json($user);
    }

    public function createUser(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['nullable', 'string', 'min:8'],
            'is_active' => ['sometimes', 'boolean'],
            'send_invite' => ['sometimes', 'boolean'],
            'role_ids' => ['array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'enrollment_ids' => ['array'],
            'enrollment_ids.*' => ['integer', 'exists:cm_enrollment,id'],
        ]);

        $actor = $request->user();

        $user = DB::transaction(function () use ($validated, $actor) {
            $created = User::create([
                'name' => $validated['name'],
                'email' => Str::lower($validated['email']),
                'password' => Hash::make($validated['password'] ?? Str::random(24)),
                'is_active' => $validated['is_active'] ?? true,
            ]);

            $this->syncUserRolesWithGuard($actor, $created, $validated['role_ids'] ?? []);
            $this->syncUserEnrollments($created, $validated['enrollment_ids'] ?? []);

            return $created->load(['roles.permissions', 'enrollmentAssignments.enrollment']);
        });

        $this->logCreate($user, ['action' => 'user_create']);

        $shouldSendInvite = $validated['send_invite'] ?? true;
        $invite = [
            'attempted' => false,
            'sent' => false,
            'status' => null,
            'message' => null,
        ];

        if ($shouldSendInvite) {
            $invite = $this->dispatchUserInvite($user);
        }

        return response()->json([
            'user' => $user,
            'invite' => $invite,
        ], 201);
    }

    public function resendUserInvite(User $user): JsonResponse
    {
        $invite = $this->dispatchUserInvite($user);

        return response()->json([
            'user_id' => $user->id,
            'email' => $user->email,
            'invite' => $invite,
        ]);
    }

    public function updateUser(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'password' => ['nullable', 'string', 'min:8'],
            'send_invite' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'role_ids' => ['array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'enrollment_ids' => ['array'],
            'enrollment_ids.*' => ['integer', 'exists:cm_enrollment,id'],
        ]);

        $actor = $request->user();
        $old = $user->toArray();

        DB::transaction(function () use ($validated, $actor, $user) {
            $password = trim((string) ($validated['password'] ?? ''));

            $user->update([
                'name' => $validated['name'],
                'email' => Str::lower($validated['email']),
                'password' => $password !== '' ? Hash::make($password) : $user->password,
                'is_active' => $validated['is_active'] ?? $user->is_active,
            ]);

            if (array_key_exists('role_ids', $validated)) {
                $this->syncUserRolesWithGuard($actor, $user, $validated['role_ids']);
            }

            if (array_key_exists('enrollment_ids', $validated)) {
                $this->syncUserEnrollments($user, $validated['enrollment_ids']);
            }
        });

        $user->load(['roles.permissions', 'enrollmentAssignments.enrollment']);
        $user->enrollment_ids = $user->enrollmentIds();

        $this->logUpdate($user, $old, ['action' => 'user_update']);

        $shouldSendInvite = $validated['send_invite'] ?? false;
        $invite = [
            'attempted' => false,
            'sent' => false,
            'status' => null,
            'message' => null,
        ];

        if ($shouldSendInvite) {
            $invite = $this->dispatchUserInvite($user);
        }

        return response()->json([
            'user' => $user,
            'invite' => $invite,
        ]);
    }

    public function setUserStatus(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        if (!$validated['is_active']) {
            $this->ensureNotLastActiveSuperAdmin($user);
        }

        $old = $user->toArray();
        $user->update(['is_active' => $validated['is_active']]);

        $this->logUpdate($user, $old, ['action' => 'user_status_update']);

        return response()->json([
            'message' => 'Status updated.',
            'user' => $user,
        ]);
    }

    public function archiveUser(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        if ($actor->id === $user->id) {
            return response()->json(['message' => 'You cannot archive your own account.'], 422);
        }

        $this->ensureNotLastActiveSuperAdmin($user);

        $old = $user->toArray();

        DB::transaction(function () use ($user) {
            $user->update(['is_active' => false]);
            $user->roles()->detach();
            EnrollmentRole::where('user_id', $user->id)->delete();
        });

        $this->logUpdate($user, $old, ['action' => 'user_archive']);

        return response()->json(['message' => 'User archived successfully.']);
    }

    public function permanentlyDeleteUser(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        if ($actor->id === $user->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        $this->ensureNotLastActiveSuperAdmin($user);
        $old = $user->toArray();

        DB::transaction(function () use ($user) {
            $user->roles()->detach();
            EnrollmentRole::where('user_id', $user->id)->delete();
            $user->delete();
        });

        $this->logAction('DELETE', User::class, $old, null, null, [
            'action' => 'user_permanent_delete',
            'user_id' => $old['id'] ?? null,
            'email' => $user->email,
            'name' => $user->name,
        ]);

        return response()->json(['message' => 'User permanently deleted.']);
    }

    public function bulkAssignRoles(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'role_ids' => ['required', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ]);

        $actor = $request->user();

        DB::transaction(function () use ($validated, $actor) {
            $users = User::whereIn('id', $validated['user_ids'])->get();
            foreach ($users as $user) {
                $this->syncUserRolesWithGuard($actor, $user, $validated['role_ids']);
            }
        });

        $this->logAction('UPDATE', User::class, null, [
            'action' => 'bulk_assign_roles',
            'user_ids' => $validated['user_ids'],
            'role_ids' => $validated['role_ids'],
        ]);

        return response()->json(['message' => 'Roles assigned successfully.']);
    }

    public function bulkAssignEnrollments(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'enrollment_ids' => ['required', 'array'],
            'enrollment_ids.*' => ['integer', 'exists:cm_enrollment,id'],
        ]);

        DB::transaction(function () use ($validated) {
            $users = User::whereIn('id', $validated['user_ids'])->get();
            foreach ($users as $user) {
                $this->syncUserEnrollments($user, $validated['enrollment_ids']);
            }
        });

        $this->logAction('UPDATE', User::class, null, [
            'action' => 'bulk_assign_enrollments',
            'user_ids' => $validated['user_ids'],
            'enrollment_ids' => $validated['enrollment_ids'],
        ]);

        return response()->json(['message' => 'Enrollments assigned successfully.']);
    }

    public function listEnrollmentsForAssignment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:50'],
        ]);

        $query = Enrollment::query()->select('id', 'title', 'status', 'company_id', 'updated_at');

        if (!empty($validated['search'])) {
            $search = trim($validated['search']);
            $query->where('title', 'like', "%{$search}%");
        }

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return response()->json($query->orderBy('title')->limit(500)->get());
    }

    private function ensureRoleIsManageableByActor(User $actor, Role $role): void
    {
        return;
    }

    private function dispatchUserInvite(User $user): array
    {
        try {
            $token = Password::broker()->createToken($user);
            $resetUrl = config('app.frontend_url') . '/password-reset/' . $token . '?email=' . urlencode($user->getEmailForPasswordReset());

            $subject = 'Set Up Your Account Password';
            $body = $this->buildUserInviteEmailHtml($user, $resetUrl);

            $sent = (new EmailSender(
                email: $user->email,
                body: $body,
                subject: $subject
            ))->send();

            if ($sent) {
                return [
                    'attempted' => true,
                    'sent' => true,
                    'status' => Password::RESET_LINK_SENT,
                    'message' => 'Invite email sent successfully via Infobip.',
                ];
            }

            return [
                'attempted' => true,
                'sent' => false,
                'status' => 'invite_send_failed',
                'message' => 'Infobip did not confirm the invite email send.',
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'attempted' => true,
                'sent' => false,
                'status' => 'invite_send_failed',
                'message' => $exception->getMessage(),
            ];
        }
    }

    private function buildUserInviteEmailHtml(User $user, string $resetUrl): string
    {
        $name = e($user->name ?? 'User');
        $safeResetUrl = e($resetUrl);

        return "
            <div style=\"font-family:Arial,sans-serif;line-height:1.5;color:#111827;\">
                <p>Hello {$name},</p>
                <p>Your account has been created or updated. Please set your password using the link below:</p>
                <p>
                    <a href=\"{$safeResetUrl}\" style=\"display:inline-block;padding:10px 16px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;\">Set Password</a>
                </p>
                <p>If the button does not work, copy and paste this URL into your browser:</p>
                <p><a href=\"{$safeResetUrl}\">{$safeResetUrl}</a></p>
                <p>If you did not expect this email, you can ignore it.</p>
            </div>
        ";
    }

    private function syncUserRolesWithGuard(User $actor, User $target, array $roleIds): void
    {
        $roleIds = array_values(array_unique(array_map('intval', $roleIds)));
        $roles = Role::whereIn('id', $roleIds)->get();

        $targetingSelf = $actor->id === $target->id;
        if ($targetingSelf && !$actor->hasModulePermission('roles', 'update')) {
            abort(403, 'You cannot modify your own roles.');
        }

        foreach ($roles as $role) {
            $this->ensureRoleIsManageableByActor($actor, $role);
        }

        $target->roles()->sync($roleIds);
    }

    private function syncUserEnrollments(User $user, array $enrollmentIds): void
    {
        $enrollmentIds = array_values(array_unique(array_map('intval', $enrollmentIds)));
        $validIds = Enrollment::whereIn('id', $enrollmentIds)->pluck('id')->all();

        EnrollmentRole::where('user_id', $user->id)->whereNotIn('enrollment_id', $validIds)->delete();

        foreach ($validIds as $enrollmentId) {
            EnrollmentRole::firstOrCreate([
                'user_id' => $user->id,
                'enrollment_id' => $enrollmentId,
            ]);
        }
    }

    private function ensureNotLastActiveSuperAdmin(User $user): void
    {
        if (!$user->is_active) {
            return;
        }

        $isPrivilegedTarget = $user->hasModulePermission('users', 'delete')
            && $user->hasModulePermission('roles', 'update');

        if (!$isPrivilegedTarget) {
            return;
        }

        $activePrivilegedCount = User::query()
            ->where('is_active', true)
            ->with(['roles.permissions'])
            ->get()
            ->filter(fn(User $candidate) =>
                $candidate->hasModulePermission('users', 'delete')
                && $candidate->hasModulePermission('roles', 'update')
            )
            ->count();

        if ($activePrivilegedCount <= 1) {
            abort(422, 'Cannot disable/archive the last active super administrator.');
        }
    }

    private function normalizeRolePermissionIds(array $permissionIds): array
    {
        $normalizedIds = array_values(array_unique(array_map('intval', $permissionIds)));

        if (empty($normalizedIds)) {
            return [];
        }

        $selectedSlugs = Permission::query()
            ->whereIn('id', $normalizedIds)
            ->pluck('slug')
            ->filter()
            ->values()
            ->all();

        $dependencySlugs = $this->collectDependentPermissionSlugs($selectedSlugs);

        if ((bool) config('rbac.export_requires_view', true)) {
            foreach ($selectedSlugs as $slug) {
                [$module, $action] = array_pad(explode('.', (string) $slug, 2), 2, null);
                if ($module && $action === 'export') {
                    $dependencySlugs[] = "{$module}.view";
                }
            }
        }

        if (!empty($dependencySlugs)) {
            $dependencyIds = Permission::query()
                ->whereIn('slug', $dependencySlugs)
                ->pluck('id')
                ->all();

            $normalizedIds = array_merge($normalizedIds, $dependencyIds);
        }

        return array_values(array_unique(array_filter($normalizedIds)));
    }

    private function collectDependentPermissionSlugs(array $selectedSlugs): array
    {
        $dependencyMap = config('rbac.permission_dependencies', []);
        if (empty($dependencyMap)) {
            return [];
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

        return array_values(array_unique($dependencySlugs));
    }
}
