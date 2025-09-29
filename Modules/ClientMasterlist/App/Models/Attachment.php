<?php

namespace Modules\ClientMasterlist\App\Models;

use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    protected $table = 'cm_attachment';

    protected $fillable = [
        'principal_id',
        'dependent_id',
        'notification_id',
        'attachment_for',
        'file_path',
        'file_name',
        'file_type',
    ];

    public function principal()
    {
        return $this->belongsTo(Enrollee::class, 'principal_id');
    }

    public function dependent()
    {
        return $this->belongsTo(Dependent::class, 'dependent_id');
    }
}
