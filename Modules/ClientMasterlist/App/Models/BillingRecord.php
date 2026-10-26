<?php

namespace Modules\ClientMasterlist\App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingRecord extends Model
{
    protected $table = 'cm_billing_records';

    protected $fillable = [
        'enrollment_id',
        'billing_type',
        'billing_month',
        'billing_year',
        'principal_id',
        'dependent_id',
        'source_file_name',
        'sheet_name',
        'source_row_number',
        'column_names',
        'row_data',
        'imported_by_user_id',
    ];

    protected $casts = [
        'column_names' => 'array',
        'row_data' => 'array',
        'source_row_number' => 'integer',
        'billing_month' => 'integer',
        'billing_year' => 'integer',
        'principal_id' => 'integer',
        'dependent_id' => 'integer',
        'enrollment_id' => 'integer',
        'imported_by_user_id' => 'integer',
    ];

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class, 'enrollment_id');
    }

    public function importedBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'imported_by_user_id');
    }
}