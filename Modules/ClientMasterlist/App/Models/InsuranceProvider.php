<?php

namespace Modules\ClientMasterlist\App\Models;

use Illuminate\Database\Eloquent\Model;

class InsuranceProvider extends Model
{
    protected $table = 'cm_insurance_provider';
    protected $fillable = [
        'title',
        'note',
        // Add other fields as needed
    ];
}
