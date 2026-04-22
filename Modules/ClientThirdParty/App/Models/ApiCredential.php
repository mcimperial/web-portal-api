<?php

namespace Modules\ClientThirdParty\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ApiCredential extends Model
{
    use SoftDeletes;

    protected $table = 'ct_api_credentials';

    protected $fillable = [
        'uuid',
        'name',
        'client_name',
        'contact_email',
        'api_key',
        'api_secret',
        'permissions',
        'allowed_ips',
        'expires_at',
        'last_used_at',
        'request_count',
        'status',
        'notes',
        'deleted_by',
    ];

    protected $hidden = [
        'api_secret',
        'deleted_at',
        'deleted_by',
    ];

    protected $casts = [
        'permissions'   => 'array',
        'allowed_ips'   => 'array',
        'expires_at'    => 'datetime',
        'last_used_at'  => 'datetime',
        'request_count' => 'integer',
    ];

    // -----------------------------------------------------------------------
    // Boot – auto-generate uuid, api_key and api_secret on creation
    // -----------------------------------------------------------------------
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->api_key)) {
                $model->api_key = self::generateApiKey();
            }
            if (empty($model->api_secret)) {
                $model->api_secret = self::generateApiSecret();
            }
        });
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Generate a URL-safe API key prefixed with "ctpk_".
     */
    public static function generateApiKey(): string
    {
        return 'ctpk_' . bin2hex(random_bytes(24)); // 48 hex chars → total ~53 chars
    }

    /**
     * Generate a random API secret (stored hashed via mutator).
     * Returns the *plain-text* secret so it can be shown once on creation.
     */
    public static function generateApiSecret(): string
    {
        return bin2hex(random_bytes(32)); // 64 hex chars
    }

    /**
     * Hash the secret before storing.
     */
    public function setApiSecretAttribute(string $value): void
    {
        // Only hash if the value is not already hashed (bcrypt starts with $2y$)
        $this->attributes['api_secret'] = str_starts_with($value, '$2y$')
            ? $value
            : bcrypt($value);
    }

    /**
     * Check whether the provided plain-text secret matches the stored hash.
     */
    public function verifySecret(string $plainSecret): bool
    {
        return password_verify($plainSecret, $this->attributes['api_secret']);
    }

    /**
     * Returns TRUE when the credential is usable (active, not expired).
     */
    public function isValid(): bool
    {
        if ($this->status !== 'ACTIVE') {
            return false;
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }
        return true;
    }

    /**
     * Returns TRUE if $permission exists in the permissions array,
     * or if permissions is null/empty (= unrestricted).
     */
    public function hasPermission(string $permission): bool
    {
        if (empty($this->permissions)) {
            return true;
        }
        return in_array($permission, $this->permissions, true);
    }

    /**
     * Returns TRUE if $ip is in the allowed_ips whitelist,
     * or if no whitelist is configured.
     */
    public function allowsIp(string $ip): bool
    {
        if (empty($this->allowed_ips)) {
            return true;
        }
        return in_array($ip, $this->allowed_ips, true);
    }

    /**
     * Increment usage counters.
     */
    public function recordUsage(): void
    {
        $this->timestamps = false;
        $this->increment('request_count');
        $this->update(['last_used_at' => now()]);
        $this->timestamps = true;
    }
}
