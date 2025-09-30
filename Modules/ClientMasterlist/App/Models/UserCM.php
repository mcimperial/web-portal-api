<?php

namespace Modules\ClientMasterlist\App\Models;

use Illuminate\Database\Eloquent\Model;

class UserCM extends Model
{
    protected $table = 'users';

    protected $fillable = [
        'id',
        'name',
        'email',
    ];
}
