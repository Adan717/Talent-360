<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MealQueueEntry extends Model
{
    protected $fillable = ['round_id', 'employee_id', 'position', 'status', 'slot_start', 'slot_end'];

    public function round()
    {
        return $this->belongsTo(MealQueueRound::class, 'round_id');
    }
}
