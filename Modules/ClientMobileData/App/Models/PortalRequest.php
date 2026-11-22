<?php

namespace Modules\ClientMobileData\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * LOA / HMO request (llibiapp_portal.app_portal_requests).
 */
class PortalRequest extends Model
{
    protected $connection = 'mysql_masterlist';

    protected $table = 'app_portal_requests';

    protected $guarded = [];

    public const PUBLIC_COLUMNS = [
        'id', 'client_id', 'member_id', 'provider_id', 'provider',
        'doctor_id', 'doctor_name', 'loa_type', 'provider_procedure_type',
        'complaint', 'diagnosis', 'loa_number', 'loa_attachment',
        'approval_code', 'loa_status', 'used_date',
        'created_at', 'updated_at',
    ];
}
