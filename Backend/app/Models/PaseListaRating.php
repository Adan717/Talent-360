<?php

namespace App\Models;

use App\Traits\Tenantable;
use Illuminate\Database\Eloquent\Model;

class PaseListaRating extends Model
{
    use Tenantable;

    protected $fillable = [
        'tenant_id', 'store_id', 'employee_id', 'rated_by_employee_id',
        'date', 'presentacion', 'imagen', 'energia',
    ];

    // Sin cast 'date' a propósito: igual que TimeEntry/StoreLog en este mismo proyecto,
    // se maneja como string plano 'Y-m-d'. El cast 'date' de Eloquent lo serializa con
    // hora y puede desfasar un día en updateOrCreate() al comparar contra lo ya guardado.
}
