<?php

namespace Modules\ClientMasterlist\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Enrollment extends Model
{
    use SoftDeletes;

    protected $table = 'cm_enrollment';

    protected $fillable = [
        'company_id',
        'insurance_provider_id',
        'title',
        'note',
        'with_address',
        'with_skip_hierarchy',
        'premium',
        'premium_computation',
        'premium_variable',
        'with_monthly',
        'principal_mbl',
        'principal_room_and_board',
        'dependent_mbl',
        'dependent_room_and_board',
        'status',
        'deleted_by',
    ];

    /**
     * Get the insurance provider for this enrollment
     */
    public function insuranceProvider()
    {
        return $this->belongsTo(InsuranceProvider::class, 'insurance_provider_id');
    }
}
