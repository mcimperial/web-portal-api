<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'company';

    protected $fillable = [
        'uuid',
        'company_code',
        'company_name',
        'address',
        'phone1',
        'phone2',
        'email1',
        'email2',
        'website',
        'tax_id',
        'logo',
        'status',
        'deleted_by',
    ];
}
