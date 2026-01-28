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
        'device_name',
        'social_providers',
        'email_verified_at',
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
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
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
            'role_permission',
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

    /**
     * Get only the enrollment_id values for this user.
     */
    public function enrollmentIds(): array
    {
        return $this->hasEnrollmentRole()->pluck('enrollment_id')->filter()->unique()->values()->toArray();
    }
}
