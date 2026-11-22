<?php

namespace Modules\ClientMobileApp\App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileConfiguration extends Model
{
    protected $connection = 'mysql';

    protected $table = 'mobile_configuration';

    protected $fillable = [
        'config_key',
        'config_value',
        'api_header',
        'api_key',
        'api_url',
    ];

    protected $hidden = ['api_key'];
}
