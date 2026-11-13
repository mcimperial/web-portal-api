<?php

namespace Modules\ClientMasterlist\App\Models;

use Illuminate\Database\Eloquent\Model;

class EnrollmentSyncLog extends Model
{
    protected $table = 'cm_enrollment_sync_logs';

    protected $fillable = [
        'source_enrollment_id',
        'target_enrollment_id',
        'initiated_by',
        'status',
        'summary',
        'skipped',
        'rollback_payload',
        'rolled_back_at',
        'rolled_back_by',
    ];

    protected $casts = [
        'summary' => 'array',
        'skipped' => 'array',
        'rollback_payload' => 'array',
        'rolled_back_at' => 'datetime',
    ];
}
