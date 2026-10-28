<?php

namespace Modules\ClientMasterlist\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ImportedEnrollmentFile extends Model
{
    use HasFactory;

    protected $table = 'cm_imported_enrollment_files';

    protected $fillable = [
        'enrollment_id',
        'import_log_id',
        'source_type',
        'company_code',
        'provider_title',
        'original_name',
        'stored_name',
        'file_path',
        'file_url',
        'mime_type',
        'uploaded_at',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
    ];

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class, 'enrollment_id');
    }

    public function importLog()
    {
        return $this->belongsTo(ImportLog::class, 'import_log_id');
    }
}
