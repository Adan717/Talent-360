<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;

class Candidate extends Model
{
    use Tenantable;
    protected $guarded = [];

    public function vacancy()
    {
        return $this->belongsTo(Vacancy::class, 'applied_vacancy_id');
    }
}
