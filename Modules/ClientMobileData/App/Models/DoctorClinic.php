<?php

namespace Modules\ClientMobileData\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Doctor <-> hospital clinic schedule (llibiapp_sync.doctors_clinics).
 */
class DoctorClinic extends Model
{
    protected $connection = 'mysql_sync';

    protected $table = 'doctors_clinics';

    protected $guarded = [];

    public $timestamps = false;

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class, 'hospital_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctors_id');
    }
}
