<?php

namespace Modules\ClientMasterlist\App\Models;

use Illuminate\Database\Eloquent\Model;

class HealthInsurance extends Model
{
    protected $table = 'cm_health_insurance';

    protected $fillable = [
        'principal_id',
        'dependent_id',
        'premium',
        'plan',
        'principal_mbl',
        'principal_room_and_board',
        'dependent_mbl',
        'dependent_room_and_board',
        'is_renewal',
        'is_company_paid',
        'coverage',
        'coverage_start_date',
        'coverage_end_date',
        'certificate_number',
        'certificate_date_issued',
        'is_skipping',
        'reason_for_skipping',
        'attachment_for_skipping',
        'is_kyc_approved',
        'kyc_datestamp',
        'is_card_delivered',
        'card_delivery_date',
        'notes',
        'status',
    ];
}
