<?php

namespace Modules\DonationDrive\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DonationDriveDocument extends Model
{
    protected $table = 'donation_drive_documents';

    protected $fillable = [
        'drive_id',
        'original_name',
        'path',
        'mime_type',
        'size',
        'uploaded_by',
    ];

    protected $hidden = ['path'];

    public function drive(): BelongsTo
    {
        return $this->belongsTo(DonationDrive::class, 'drive_id');
    }
}
