<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Traits\Tenantable;

class AnonymousFeedback extends Model
{
    use Tenantable;

    protected $table = 'anonymous_feedback';

    protected $fillable = [
        'tenant_id',
        'type',
        'content',
    ];
}
