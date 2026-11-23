<?php

namespace Modules\ClientMobileApp\App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileLegalDocument extends Model
{
    protected $connection = 'mysql';

    protected $table = 'mobile_legal_documents';

    protected $fillable = [
        'document_key',
        'title',
        'html_content',
        'version',
        'is_active',
    ];

    protected $casts = [
        'version' => 'integer',
        'is_active' => 'boolean',
    ];
}
