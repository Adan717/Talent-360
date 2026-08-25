<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Tenantable;

class AcademyCourse extends Model
{
    use Tenantable, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'quiz_data' => 'array',
        // Sello de "el examen lo configuro la empresa" (Fase 2). Nulo = sigue el relleno del
        // catalogo y NO se expiden folios verificables sobre el.
        'quiz_approved_at' => 'datetime',
    ];
}
