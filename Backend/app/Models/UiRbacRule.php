<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;

class UiRbacRule extends Model
{
    use Tenantable;

    protected $guarded = [];
}
