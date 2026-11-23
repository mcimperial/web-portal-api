<?php

namespace Modules\ClientMobileApp\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Remaining consultation allowance per member (llibiapp_portal.app_remaining).
 * `uniquecode` holds the member_id.
 */
class AppRemaining extends Model
{
    protected $connection = 'mysql_masterlist';

    protected $table = 'app_remaining';

    protected $guarded = [];

    public $timestamps = false;
}
