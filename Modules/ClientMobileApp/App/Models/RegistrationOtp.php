<?php

namespace Modules\ClientMobileApp\App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistrationOtp extends Model
{
    protected $fillable = [
        'email',
        'name',
        'otp',
        'expires_at',
        'attempts',
        'verified',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified' => 'boolean',
    ];

    protected $hidden = [
        'otp',
    ];

    /**
     * Check if OTP is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if OTP is verified.
     */
    public function isVerified(): bool
    {
        return $this->verified;
    }

    /**
     * Check if max attempts reached.
     */
    public function hasMaxAttempts(): bool
    {
        return $this->attempts >= 5;
    }
}
