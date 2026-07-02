<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;

class LftSetting extends Model
{
    use Tenantable;

    protected $guarded = [];
}
