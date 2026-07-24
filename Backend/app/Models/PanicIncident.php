<?php

namespace App\Models;

use App\Traits\Tenantable;
use Illuminate\Database\Eloquent\Model;

/**
 * Incidente del Botón de Pánico (Ronda 80). Registro histórico de una emergencia declarada desde el
 * reloj (categoría + geo). Un incidente `active` alerta a los mandos del tenant; se `resolved` cuando
 * el reportante o un mando desactiva el modo pánico.
 */
class PanicIncident extends Model
{
    use Tenantable;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'category',
        'description',
        'latitude',
        'longitude',
        'status',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'resolved_at' => 'datetime',
    ];
}
