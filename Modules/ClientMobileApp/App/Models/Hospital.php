<?php

namespace Modules\ClientMobileApp\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Accredited provider / hospital (llibiapp_sync.hospitals).
 */
class Hospital extends Model
{
    protected $connection = 'mysql_sync';

    protected $table = 'hospitals';

    protected $guarded = [];

    public $timestamps = false;

    public const PUBLIC_COLUMNS = [
        'id', 'code', 'hosp_code', 'name', 'add1', 'add2', 'add3',
        'city', 'state', 'country', 'phone1', 'phone2', 'email1', 'email2',
        'classification', 'accredited', 'status', 'hospitalclass', 'accept_eloa',
    ];

    public function clinics(): HasMany
    {
        return $this->hasMany(DoctorClinic::class, 'hospital_id');
    }
}
