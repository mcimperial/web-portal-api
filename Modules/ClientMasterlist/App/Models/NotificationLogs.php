<?php

namespace Modules\ClientMasterlist\App\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\ClientMasterlist\App\Models\Enrollment;
use Illuminate\Database\Eloquent\SoftDeletes;

class Notification extends Model
{
    use SoftDeletes;

    protected $table = 'cm_notification';

    protected $fillable = [
        'notification_id',
        'principal_id',
        'date_sent',
        'status',
        'details',
    ];

    public function notification()
    {
        return $this->belongsTo(Notification::class, 'notification_id');
    }

    public function principal()
    {
        return $this->belongsTo(Enrollment::class, 'principal_id');
    }
}
