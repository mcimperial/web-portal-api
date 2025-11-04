<?php

namespace Modules\ClientMasterlist\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


class Dependent extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'cm_dependent';

    protected $fillable = [
        'principal_id',
        'employee_id',
        'member_id',
        'first_name',
        'last_name',
        'middle_name',
        'suffix',
        'relation',
        'birth_date',
        'gender',
        'nationality',
        'marital_status',
        'enrollment_status',
        'status',
    ];

    // Each dependent has one health insurance record
    public function healthInsurance()
    {
        return $this->hasOne(HealthInsurance::class, 'dependent_id');
    }

    public function attachment()
    {
        return $this->hasOne(Attachment::class, 'dependent_id');
    }

    // Get all attachments for this dependent
    public function attachments()
    {
        return $this->hasMany(Attachment::class, 'dependent_id');
    }

    // Get all required_document attachments
    public function requiredDocuments()
    {
        return $this->hasMany(Attachment::class, 'dependent_id')
            ->where('attachment_for', 'required_document');
    }

    // Get the latest skip_hierarchy attachment only
    public function attachmentForSkipHierarchy()
    {
        return $this->hasOne(Attachment::class, 'dependent_id')
            ->where('attachment_for', 'skip_hierarchy')
            ->latest('created_at');
    }

    public function attachmentForRequirement()
    {
        return $this->hasOne(Attachment::class, 'dependent_id')
            ->where('attachment_for', 'required_document')
            ->latest('created_at');
    }

    // Alias for Laravel relationship naming convention
    public function health_insurance()
    {
        return $this->healthInsurance();
    }
}
