<?php

namespace Modules\ClientMasterlist\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ImportLog extends Model
{
    use HasFactory;

    protected $table = 'cm_import_logs';

    protected $fillable = [
        'enrollment_id',
        'import_date',
        'total_principals',
        'total_dependents',
        'principals_created',
        'principals_updated',
        'dependents_created',
        'dependents_updated',
        'import_details',
        'date_format_detected',
        'date_format_confidence',
        'status',
        'error_message',
    ];

    protected $casts = [
        'import_details' => 'array',
        'import_date' => 'datetime',
    ];

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class, 'enrollment_id');
    }
}
