<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ModuleAddon extends Model
{
    use SoftDeletes;

    protected $table = 'module_addons';

    protected $fillable = [
        'module_key',
        'name',
        'description',
        'price_per_employee',
        'min_monthly_price',
        'icon_name',
        'is_active',
    ];

    protected $casts = [
        'price_per_employee' => 'float',
        'min_monthly_price' => 'float',
        'is_active' => 'boolean',
    ];
}
