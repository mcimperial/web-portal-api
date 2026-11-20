<?php

namespace Modules\DonationDrive\App\Models;

use Illuminate\Database\Eloquent\Model;

class DonationHistory extends Model
{
    protected $table = 'donation_drive_donation_histories';

    protected $fillable = [
        'donation_id',
        'previous_amount',
        'new_amount',
    ];

    protected $casts = [
        'previous_amount' => 'decimal:2',
        'new_amount' => 'decimal:2',
    ];
}
