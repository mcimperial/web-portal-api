<?php

namespace Modules\ClientMobileApp\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobilePopupMessage extends Model
{
    protected $connection = 'mysql';

    protected $table = 'mobile_popup_messages';

    protected $fillable = [
        'mobile_popup_id',
        'title',
        'html_content',
        'image_url',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function popup(): BelongsTo
    {
        return $this->belongsTo(MobilePopup::class, 'mobile_popup_id');
    }
}
