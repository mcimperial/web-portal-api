<?php

namespace Modules\ClientMobileData\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Member masterlist record (llibiapp_sync.masterlist).
 */
class Masterlist extends Model
{
    protected $connection = 'mysql_sync';

    protected $table = 'masterlist';

    protected $guarded = [];

    public $timestamps = false;

    /** Columns safe to expose to the mobile app. */
    public const PUBLIC_COLUMNS = [
        'id', 'member_id', 'company_code', 'cost_code', 'company_name',
        'last_name', 'first_name', 'middle_name', 'relation',
        'birth_date', 'email', 'incepfrom', 'incepto', 'empcode',
        'noofconsult', 'plantype', 'rb', 'rbdep', 'ismbl', 'mbl',
        'layer', 'preexist', 'pemonth', 'philhealth', 'currency',
        'rb2', 'rb3', 'plan',
    ];
}
