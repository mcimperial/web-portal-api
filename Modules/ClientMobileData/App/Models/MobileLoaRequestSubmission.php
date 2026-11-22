<?php

namespace Modules\ClientMobileData\App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileLoaRequestSubmission extends Model
{
    protected $connection = 'mysql';

    protected $table = 'mobile_loa_request_submissions';

    protected $guarded = [];

    protected $casts = [
        'details' => 'array',
    ];
}
