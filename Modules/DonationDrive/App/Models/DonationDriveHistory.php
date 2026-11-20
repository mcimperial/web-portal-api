<?php

namespace Modules\DonationDrive\App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DonationDriveHistory extends Model
{
    protected $table = 'donation_drive_histories';

    protected $fillable = [
        'drive_id',
        'user_id',
        'action',
        'previous_is_active',
        'current_is_active',
        'details',
    ];

    protected $casts = [
        'previous_is_active' => 'boolean',
        'current_is_active' => 'boolean',
        'details' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
