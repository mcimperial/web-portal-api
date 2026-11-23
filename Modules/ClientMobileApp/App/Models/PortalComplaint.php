<?php

namespace Modules\ClientMobileApp\App\Models;

use Illuminate\Database\Eloquent\Model;

class PortalComplaint extends Model
{
    protected $connection = 'mysql_masterlist';

    protected $table = 'app_portal_complaints';

    public $timestamps = false;
}
