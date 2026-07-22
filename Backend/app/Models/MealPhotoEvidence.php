<?php

namespace App\Models;

use App\Traits\Tenantable;
use Illuminate\Database\Eloquent\Model;

class MealPhotoEvidence extends Model
{
    use Tenantable;

    // "evidence" es incontable en inglés — el inflector de Laravel no le agrega la 's',
    // así que hay que fijar el nombre de tabla explícito (si no, busca meal_photo_evidence).
    protected $table = 'meal_photo_evidences';

    protected $fillable = ['tenant_id', 'employee_id', 'date', 'type', 'url', 'path'];

    // Sin cast 'date' a propósito — mismo criterio que PaseListaRating (ver ese modelo).
}
