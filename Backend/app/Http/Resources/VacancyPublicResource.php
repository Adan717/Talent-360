<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lo que el PÚBLICO ve de una vacante (Fase 3, 2026-08-24).
 *
 * El portal de empleos es una ruta sin sesión: cualquiera con el enlace ve el JSON crudo. Hasta
 * hoy se entregaba el modelo entero —`tenant_id`, `deleted_at`, `is_hidden`, `job_role_id` y las
 * marcas de auditoría— porque devolver `$vacancies` a secas serializa toda la fila. Nada de eso es
 * un secreto grave, pero un candidato no tiene por qué recibir la contabilidad interna de la
 * empresa, y el día que alguien agregue una columna con notas internas de reclutamiento se
 * publicaría sola. Un contrato explícito no tiene ese futuro: lo que no está aquí, no sale.
 *
 * Lista blanca, nunca lista negra. Se enumera lo que SE PUBLICA, no lo que se esconde: así una
 * columna nueva nace privada por omisión, que es la dirección segura.
 *
 * `salary_range` SÍ es público a propósito: es el rango anunciado en la oferta, lo que atrae al
 * candidato. No es el sueldo de nadie de la plantilla.
 */
class VacancyPublicResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'requirements' => $this->requisitos(),
            'image_url' => $this->image_url,
            'work_type' => $this->work_type,
            'schedule' => $this->schedule,
            'salary_range' => $this->salary_range,
            // El portal lo usa para su distintivo de "vacante abierta". El endpoint ya sólo
            // entrega vacantes activas, así que aquí siempre es true; se manda para no romper
            // esa pantalla y porque decir "está abierta" en la oferta es correcto.
            'is_active' => (bool) ($this->is_active ?? true),
        ];
    }

    /**
     * Los requisitos viven en la base de tres formas distintas, según de dónde nació la vacante:
     * JSON, texto con saltos de línea, o ya un arreglo. El portal pinta una lista, así que la
     * normalización se hace aquí —en la frontera— y no en la pantalla.
     */
    private function requisitos(): array
    {
        $crudo = $this->requirements;

        if (is_array($crudo)) {
            return array_values($crudo);
        }

        if (!is_string($crudo) || trim($crudo) === '') {
            return [];
        }

        $decodificado = json_decode($crudo, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decodificado)) {
            return array_values($decodificado);
        }

        return array_values(array_filter(array_map('trim', explode("\n", $crudo))));
    }
}
