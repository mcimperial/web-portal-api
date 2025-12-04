<?php

namespace Modules\ClientMasterlist\App\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\ClientMasterlist\App\Models\Enrollee;
use Illuminate\Database\Eloquent\SoftDeletes;

class NotificationLogs extends Model
{
    use SoftDeletes;

    protected $table = 'cm_notification_logs';

    protected $fillable = [
        'notification_id',
        'principal_id',
        'date_sent',
        'status',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
        'date_sent' => 'datetime',
    ];

    public function notification()
    {
        return $this->belongsTo(Notification::class, 'notification_id');
    }

    public function principal()
    {
        return $this->belongsTo(Enrollee::class, 'principal_id');
    }
}
