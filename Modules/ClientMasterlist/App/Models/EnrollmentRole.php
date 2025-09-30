<?php

namespace Modules\ClientMasterlist\App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class EnrollmentRole extends Model
{

    protected $table = 'cm_enrollment_role';

    protected $fillable = [
        'enrollment_id',
        'user_id',
    ];

    public function user()
    {
        return $this->belongsTo(UserCM::class, 'user_id');
    }

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class, 'enrollment_id');
    }
}
