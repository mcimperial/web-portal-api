<?php

namespace Modules\ClientThirdParty\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'params',
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
        'params'        => 'array',
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

        $binaryIp = filter_var($ip, FILTER_VALIDATE_IP)
            ? inet_pton($ip)
            : false;

        if ($binaryIp === false) {
            return false;
        }

        foreach ($this->allowed_ips as $allowed) {
            if (!is_string($allowed)) {
                continue;
            }

            $allowed = trim($allowed);

            if (filter_var($allowed, FILTER_VALIDATE_IP)) {
                if ($binaryIp === inet_pton($allowed)) {
                    return true;
                }

                continue;
            }

            $range = self::parseIpv4Range($allowed);
            $ipNumber = self::ipv4ToNumber($ip);

            if ($range !== null && $ipNumber !== null
                && $ipNumber >= $range[0] && $ipNumber <= $range[1]) {
                return true;
            }
        }

        return false;
    }

    public static function isValidIpRestriction(string $value): bool
    {
        $value = trim($value);

        return filter_var($value, FILTER_VALIDATE_IP) !== false
            || self::parseIpv4Range($value) !== null;
    }

    private static function parseIpv4Range(string $value): ?array
    {
        $cidrParts = explode('/', $value);

        if (count($cidrParts) === 2) {
            $ipNumber = self::ipv4ToNumber(trim($cidrParts[0]));
            $prefix = trim($cidrParts[1]);

            if ($ipNumber === null || preg_match('/^(?:[0-9]|[12][0-9]|3[0-2])$/', $prefix) !== 1) {
                return null;
            }

            $prefixLength = (int) $prefix;
            $mask = $prefixLength === 0
                ? 0
                : (0xFFFFFFFF << (32 - $prefixLength)) & 0xFFFFFFFF;
            $network = $ipNumber & $mask;
            $broadcast = $network | (0xFFFFFFFF ^ $mask);

            return [$network, $broadcast];
        }

        if (count($cidrParts) !== 1) {
            return null;
        }

        $parts = explode('-', $value);

        if (count($parts) !== 2) {
            return null;
        }

        $start = trim($parts[0]);
        $end = trim($parts[1]);

        if (preg_match('/^\d{1,3}$/', $end) === 1) {
            $startOctets = explode('.', $start);

            if (count($startOctets) !== 4) {
                return null;
            }

            $end = implode('.', array_slice($startOctets, 0, 3)) . '.' . $end;
        }

        $startNumber = self::ipv4ToNumber($start);
        $endNumber = self::ipv4ToNumber($end);

        if ($startNumber === null || $endNumber === null || $startNumber > $endNumber) {
            return null;
        }

        return [$startNumber, $endNumber];
    }

    private static function ipv4ToNumber(string $ip): ?int
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        return unpack('Nnumber', inet_pton($ip))['number'];
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

    public function usageLogs(): HasMany
    {
        return $this->hasMany(ApiUsageLog::class, 'api_credential_id');
    }
}
