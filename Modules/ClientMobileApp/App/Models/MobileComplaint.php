<?php

namespace Modules\ClientMobileApp\App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileComplaint extends Model
{
    protected $connection = 'mysql';

    protected $table = 'mobile_complaints';

    protected $fillable = ['title', 'normalized_title'];
}
