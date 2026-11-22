<?php

namespace Modules\ClientMobileData\App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileLoaRequestDetail extends Model
{
    protected $connection = 'mysql';

    protected $table = 'mobile_loa_request_details';

    protected $guarded = [];

    protected $casts = [
        'details' => 'array',
    ];
}
