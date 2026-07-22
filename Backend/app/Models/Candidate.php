<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;
use Illuminate\Database\Eloquent\SoftDeletes;

class Candidate extends Model
{
    use Tenantable, SoftDeletes;
    protected $guarded = [];

    public function vacancy()
    {
        return $this->belongsTo(Vacancy::class, 'applied_vacancy_id');
    }
}
