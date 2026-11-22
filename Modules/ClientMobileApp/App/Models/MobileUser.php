<?php

namespace Modules\ClientMobileApp\App\Models;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

class MobileUser extends Authenticatable
{
    use HasApiTokens;

    protected $connection = 'mysql';

    protected $table = 'mobile_users';

    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            $user->uuid ??= (string) Str::uuid();
        });
    }

    protected $fillable = [
        'name',
        'email',
        'contact_number',
        'password',
        'device_name',
        'social_providers',
        'member_id',
        'email_verified_at',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'social_providers',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'social_providers' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function tokens(): MorphMany
    {
        return $this->morphMany(PersonalAccessToken::class, 'tokenable');
    }
}
