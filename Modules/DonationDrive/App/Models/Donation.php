<?php

namespace Modules\DonationDrive\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Donation extends Model
{
    protected $table = 'donation_drive_donations';

    protected $fillable = [
        'drive_id',
        'donor_id',
        'cutoff',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'cutoff' => 'integer',
    ];

    public function donor(): BelongsTo
    {
        return $this->belongsTo(DonationDonor::class, 'donor_id');
    }

    public function drive(): BelongsTo
    {
        return $this->belongsTo(DonationDrive::class, 'drive_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(DonationHistory::class, 'donation_id');
    }
}
