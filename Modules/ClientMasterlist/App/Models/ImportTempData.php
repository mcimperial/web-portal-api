<?php

namespace Modules\ClientMasterlist\App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportTempData extends Model
{
    protected $table = 'cm_import_temp_data';

    protected $fillable = [
        'enrollment_id',
        'import_date',
        'row_number',
        'column_names',
        'row_data',
    ];

    protected $casts = [
        'import_date' => 'date:Y-m-d',
        'column_names' => 'array',
        'row_data' => 'array',
    ];

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class, 'enrollment_id');
    }
}
