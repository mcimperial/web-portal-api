<?php

namespace Modules\ClientMasterlist\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PrincipalUnmappedColumn extends Model
{
    use HasFactory;

    protected $table = 'cm_principal_unmapped_columns';

    protected $fillable = [
        'principal_id',
        'column_name',
        'column_value',
    ];

    // Unmapped column value belongs to a principal (Enrollee)
    public function principal()
    {
        return $this->belongsTo(Enrollee::class, 'principal_id');
    }
}
