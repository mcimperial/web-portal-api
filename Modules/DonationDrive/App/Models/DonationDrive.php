<?php

namespace Modules\DonationDrive\App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DonationDrive extends Model
{
    protected $table = 'donation_drives';

    protected $fillable = [
        'title',
        'description',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(DonationDriveDocument::class, 'drive_id');
    }

    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class, 'drive_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(DonationDriveHistory::class, 'drive_id');
    }

    public function reportUsers(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'donation_drive_report_users',
            'drive_id',
            'user_id'
        )->withTimestamps();
    }
}
