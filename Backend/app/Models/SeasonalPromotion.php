<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SeasonalPromotion extends Model
{
    use SoftDeletes;

    protected $table = 'seasonal_promotions';

    protected $fillable = [
        'title',
        'subtitle',
        'badge_text',
        'discount_percentage',
        'target_plan',
        'banner_bg_color',
        'banner_text_color',
        'cta_label',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    protected $casts = [
        'discount_percentage' => 'float',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
    ];
}
