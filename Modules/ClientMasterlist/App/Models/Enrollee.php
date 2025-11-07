<?php

namespace Modules\ClientMasterlist\App\Models;

use Illuminate\Support\Str;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;


use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\ClientMasterlist\App\Models\Dependent;

class Enrollee extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'cm_principal';

    protected $fillable = [
        'uuid',
        'enrollment_id',
        'company_id',
        'member_id',
        'employee_id',
        'department',
        'position',
        'employment_start_date',
        'employment_end_date',
        'first_name',
        'last_name',
        'middle_name',
        'suffix',
        'birth_date',
        'gender',
        'nationality',
        'marital_status',
        'enrollment_status',
        'email1',
        'email2',
        'phone1',
        'phone2',
        'address',
        'with_dependents',
        'notes',
        'deleted_by',
        'status',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->employee_id)) {
                // Generate a unique employee_id (e.g., based on timestamp and random)
                $model->employee_id = 'EMP-' . now()->format('YmdHis') . '-' . mt_rand(1000, 9999);
            }
        });
    }

    // Principal belongs to an enrollment
    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class, 'enrollment_id');
    }

    // Principal has many dependents
    public function dependents()
    {
        return $this->hasMany(Dependent::class, 'principal_id');
    }

    // Principal has one insurance provider through enrollment
    public function insuranceProvider()
    {
        return $this->hasOneThrough(
            InsuranceProvider::class,
            Enrollment::class,
            'id', // Foreign key on Enrollment table...
            'id', // Foreign key on InsuranceProvider table...
            'enrollment_id', // Local key on Principal (Enrollee) table...
            'insurance_provider_id' // Local key on Enrollment table...
        );
    }

    // Principal has one health insurance record
    public function healthInsurance()
    {
        return $this->hasOne(HealthInsurance::class, 'principal_id');
    }
}
