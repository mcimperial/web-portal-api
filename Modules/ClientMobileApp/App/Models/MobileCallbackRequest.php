<?php

namespace Modules\ClientMobileApp\App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileCallbackRequest extends Model
{
    protected $connection = 'mysql';

    protected $fillable = [
        'mobile_user_id',
        'idempotency_key',
        'request_hash',
        'requestor_name',
        'email',
        'mobile',
        'landline',
        'request_message',
        'alt_email',
        'alt_landline',
        'alt_mobile',
        'status',
    ];

    protected $hidden = ['mobile_user_id', 'idempotency_key', 'request_hash'];
}
