<?php

namespace Modules\ClientMobileApp\App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MobilePopup extends Model
{
    protected $connection = 'mysql';

    protected $table = 'mobile_popups';

    protected $fillable = [
        'title',
        'is_active',
        'show_decline_button',
        'accept_label',
        'decline_label',
        'sort_order',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'show_decline_button' => 'boolean',
        'sort_order' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(MobilePopupMessage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function scopeCurrentlyActive(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }
}
