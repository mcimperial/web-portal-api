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
        'enrollment_id',
        'notification_type',
        'to',
        'cc',
        'bcc',
        'title',
        'subject',
        'message',
        'is_html',
        'is_read',
        'schedule',
        'deleted_by',
    ];

    protected $casts = [
        'is_html' => 'boolean',
        'is_read' => 'boolean',
    ];

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class, 'enrollment_id');
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }
}
