<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;

class LftHoliday extends Model
{
    use Tenantable;

    protected $fillable = ['tenant_id', 'date', 'name', 'block_app'];
}
