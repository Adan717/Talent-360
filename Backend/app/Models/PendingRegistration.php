<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingRegistration extends Model
{
    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';
}
