<?php

namespace App\Models;

use App\Traits\Tenantable;
use Illuminate\Database\Eloquent\Model;

class MealQueueRound extends Model
{
    use Tenantable;

    protected $fillable = ['tenant_id', 'store_id', 'date', 'order_by', 'status'];

    public function entries()
    {
        return $this->hasMany(MealQueueEntry::class, 'round_id')->orderBy('position');
    }
}
