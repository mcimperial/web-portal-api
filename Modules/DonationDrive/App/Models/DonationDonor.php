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
        'is_admin',
    ];

    protected $casts = [
        'is_admin' => 'boolean',
    ];

    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class, 'donor_id');
    }
}
