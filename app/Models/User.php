<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Traits\HasRoles;

use Modules\ClientMasterlist\App\Models\EnrollmentRole;
use Modules\ClientMasterlist\App\Models\Enrollment;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'device_name',
        'social_providers',
        'email_verified_at',
        'email_otp',
        'email_otp_expires_at',
        'email_otp_attempts',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'social_providers',
        'email_otp',
        'email_otp_expires_at',
        'email_otp_attempts',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'email_otp_expires_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
        'social_providers' => 'array',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_role');
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'permission_role',
            'role_id',
            'permission_id'
        );
    }

    public function hasRole($role): bool
    {
        return $this->roles()->where('slug', $role)->exists();
    }

    public function hasPermission($permission): bool
    {
        return $this->roles()
            ->whereHas(
                'permissions',
                fn($query) =>
                $query->where('slug', $permission)
            )->exists();
    }

    public function hasEnrollmentRole()
    {
        return $this->hasMany(EnrollmentRole::class, 'user_id');
    }

    public function enrollmentAssignments()
    {
        return $this->hasMany(EnrollmentRole::class, 'user_id');
    }

    public function assignedEnrollments(): BelongsToMany
    {
        return $this->belongsToMany(
            Enrollment::class,
            'cm_enrollment_role',
            'user_id',
            'enrollment_id'
        );
    }

    /**
     * Get only the enrollment_id values for this user.
     */
    public function enrollmentIds(): array
    {
        return $this->hasEnrollmentRole()->pluck('enrollment_id')->filter()->unique()->values()->toArray();
    }

    public function isSuperAdmin(): bool
    {
        $superAdminRoles = config('rbac.super_admin_roles', ['admin', 'admin-se']);
        return $this->roles()->whereIn('slug', $superAdminRoles)->exists();
    }

    public function hasModulePermission(string $module, string $action): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->isSuperAdmin()) {
            return true;
        }

        $module = strtolower(trim($module));
        $action = strtolower(trim($action));

        $allowedActions = config('rbac.actions', ['view', 'export', 'create', 'update', 'delete']);
        if (!in_array($action, $allowedActions, true)) {
            return false;
        }

        if ($action === 'export' && config('rbac.export_requires_view', true)) {
            if (!$this->hasModulePermission($module, 'view')) {
                return false;
            }
        }

        return $this->roles()
            ->whereHas('permissions', function ($query) use ($module, $action) {
                $query->where('slug', "{$module}.{$action}")
                    ->orWhere(function ($legacy) use ($module, $action) {
                        $legacy->where('app', $module)->where('sub_app', $action);
                    });
            })->exists();
    }
}
