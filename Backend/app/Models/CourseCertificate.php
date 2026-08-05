<?php

namespace App\Models;

use App\Traits\Tenantable;
use Illuminate\Database\Eloquent\Model;

/**
 * Certificado emitido a un colaborador por aprobar un curso de la Academia.
 *
 * Guarda una FOTO de los datos al emitir (nombre, curso, empresa): si el curso se renombra o el
 * colaborador se da de baja, el certificado ya entregado debe seguir diciendo lo mismo.
 */
class CourseCertificate extends Model
{
    use Tenantable;

    protected $guarded = [];

    protected $casts = [
        'issued_at' => 'datetime',
        'score' => 'integer',
    ];

    public function course()
    {
        return $this->belongsTo(AcademyCourse::class, 'course_id');
    }

    /**
     * Folio de verificación: `TAL-<año>-<8 caracteres>`.
     *
     * El alfabeto excluye 0/O y 1/I/L, que son las que se confunden al copiar un folio de un papel
     * impreso. Los 8 caracteres son aleatorios a propósito: el folio se consulta SIN sesión, así
     * que si fuera correlativo cualquiera podría recorrer los certificados de toda la plataforma.
     */
    public static function generarFolio(): string
    {
        $alfabeto = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $sufijo = '';

        for ($i = 0; $i < 8; $i++) {
            $sufijo .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }

        return 'TAL-' . now()->format('Y') . '-' . $sufijo;
    }
}
