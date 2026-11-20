<?php

namespace Modules\DonationDrive\App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class DonationDonor extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'donation_donors';

    protected $fillable = [
        'first_name',
        'last_name',
        'normalized_name',
        'phone',
        'phone_verified_at',
        'email',
        'email_verified_at',
        'is_admin',
    ];

    protected $casts = [
        'is_admin' => 'boolean',
        'phone_verified_at' => 'datetime',
        'email_verified_at' => 'datetime',
    ];

    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class, 'donor_id');
    }
}
