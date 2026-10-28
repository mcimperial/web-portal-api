<?php

namespace Modules\ClientMasterlist\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ResignedImportAudit extends Model
{
    use HasFactory;

    protected $table = 'cm_resigned_import_audits';

    protected $fillable = [
        'principal_id',
        'enrollment_id',
        'import_log_id',
        'employee_id',
        'first_name',
        'last_name',
        'resigned_date',
        'source_file_original_name',
        'source_file_stored_name',
        'source_file_path',
        'source_file_url',
        'source_company_code',
        'source_provider_title',
        'recorded_at',
    ];

    protected $casts = [
        'resigned_date' => 'date:Y-m-d',
        'recorded_at' => 'datetime',
    ];

    public function principal()
    {
        return $this->belongsTo(Enrollee::class, 'principal_id');
    }

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class, 'enrollment_id');
    }

    public function importLog()
    {
        return $this->belongsTo(ImportLog::class, 'import_log_id');
    }
}
