<?php

namespace Modules\ClientThirdParty\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiUsageLog extends Model
{
    protected $table = 'ct_api_usage_logs';

    protected $fillable = [
        'api_credential_id',
        'api_key_last4',
        'process',
        'route_name',
        'method',
        'endpoint',
        'ip_address',
        'user_agent',
        'response_status',
        'status',
        'failure_reason',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'response_status' => 'integer',
    ];

    public function credential(): BelongsTo
    {
        return $this->belongsTo(ApiCredential::class, 'api_credential_id');
    }
}
