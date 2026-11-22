<?php

namespace Modules\ClientMobileData\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Accredited doctor (llibiapp_sync.doctors).
 */
class Doctor extends Model
{
    protected $connection = 'mysql_sync';

    protected $table = 'doctors';

    protected $guarded = [];

    public $timestamps = false;

    public const PUBLIC_COLUMNS = [
        'id', 'code', 'first', 'middle', 'last', 'gender',
        'specialization', 'specialization2',
        'add1', 'add2', 'add3', 'city', 'state', 'country',
        'phone1', 'phone2', 'email1', 'email2', 'status',
    ];

    public function clinics(): HasMany
    {
        return $this->hasMany(DoctorClinic::class, 'doctors_id');
    }
}
