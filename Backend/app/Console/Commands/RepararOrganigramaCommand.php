<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reparación del ORGANIGRAMA de tenants configurados antes de que el asistente lo armara.
 *
 * `construirOrganigrama` nació el 2026-08-01 (H27). Las empresas que aplicaron su giro antes
 * tienen sus puestos completamente huérfanos: `reports_to_role_id`, `reports_to_role_ids` y
 * `org_parent_role_id` en nulo. No es que siguieran otra convención — es que no había ninguna,
 * simplemente nunca se dibujó. Verificado en la V2: los 7 puestos que el asistente creó en el
 * tenant 2 tienen los tres campos vacíos, mientras los 4 sembrados de origen sí tienen jerarquía.
 *
 * Eso deja dos cosas rotas río abajo:
 *  - `TaskValidationPolicy` concluye que nadie tiene supervisor y no exige ninguna firma.
 *  - El tablero de pendientes del encargado (`GET /supervisor/pendientes`) no encuentra a quién
 *    le toca cada caso, y TODO cae al admin — justo lo contrario de la decisión del jefe
 *    ("el que está en piso es quien puede acercarse, no el de arriba").
 *
 * También rellena el ARREGLO `reports_to_role_ids` donde sólo está el campo suelto: el
 * organigrama de Directorio > Puestos dibuja la línea punteada —la jerarquía operativa— leyendo
 * el arreglo, así que sin él la relación existe en la base pero no se ve ni se hereda.
 *
 * Misma convención que el asistente: cada puesto reporta al PRIMER puesto del nivel
 * (`jerarquiaLlaves`) inmediatamente superior que exista. Es una convención de arranque, no una
 * verdad: el admin la ajusta arrastrando en el organigrama, y por eso conviene revisar el
 * `--dry-run` antes de aplicar.
 *
 *   php artisan reloj:reparar-organigrama [--dry-run] [--tenant=]
 */
class RepararOrganigramaCommand extends Command
{
    protected $signature = 'reloj:reparar-organigrama {--dry-run : Solo mostrar qué haría} {--tenant= : Limitar a un tenant}';

    protected $description = 'Arma el organigrama de tenants configurados antes de que el asistente lo creara (H27)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $tenants = DB::table('job_roles')
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('tenant_id', $t))
            ->distinct()
            ->pluck('tenant_id');

        if ($tenants->isEmpty()) {
            $this->info('No hay puestos que revisar.');

            return self::SUCCESS;
        }

        $tocados = 0;

        foreach ($tenants as $tenantId) {
            $puestos = DB::table('job_roles')
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->orderBy('id')
                ->get();

            if ($puestos->isEmpty()) {
                continue;
            }

            $cambios = $this->calcularCambios($puestos);

            if (empty($cambios)) {
                continue;
            }

            $this->line("Tenant {$tenantId}:");
            $nombres = $puestos->pluck('name', 'id');

            foreach ($cambios as $puestoId => $cambio) {
                $jefe = $cambio['jefe'] ? ($nombres[$cambio['jefe']] ?? "#{$cambio['jefe']}") : '(nadie)';
                $this->line("  · {$nombres[$puestoId]} → reporta a {$jefe}   [{$cambio['motivo']}]");

                if (!$dryRun) {
                    DB::table('job_roles')->where('id', $puestoId)->update($cambio['datos']);
                }

                $tocados++;
            }
        }

        if ($tocados === 0) {
            $this->info('Todos los organigramas están completos: nada que reparar.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info($dryRun
            ? "{$tocados} puesto(s) quedarían conectados. Revisa el árbol de arriba y vuelve a correr sin --dry-run."
            : "{$tocados} puesto(s) conectados. Revisa el organigrama en Directorio > Puestos y ajusta lo que no cuadre.");

        return self::SUCCESS;
    }

    /**
     * Qué le falta a cada puesto. Dos casos distintos, a propósito separados:
     *
     *  - HUÉRFANO: no tiene jefe de ninguna forma. Se le calcula con la convención del asistente.
     *  - SIN ARREGLO: ya tiene jefe en el campo suelto, pero el arreglo está vacío, así que la
     *    línea punteada del organigrama no existe. Se copia; NO se recalcula nada, porque ese
     *    jefe pudo haberlo puesto una persona a mano y no se toca.
     */
    private function calcularCambios($puestos): array
    {
        // `jerarquiaLlaves` 0 o nulo significa "sin nivel declarado", no "el nivel más alto".
        // Son los puestos que quedaron sembrados al crear la empresa, antes de aplicar el giro.
        // Meterlos en el cálculo hacía que el puesto de MANDO (nivel 1) terminara reportándole a
        // un puesto viejo de nivel 0 — lo detectó el --dry-run contra los datos reales antes de
        // escribir nada. El asistente no tiene este problema porque sólo recorre los puestos del
        // catálogo que está creando; aquí se mira toda la empresa, así que hay que excluirlos.
        $conNivel = $puestos->filter(fn ($p) => (int) ($p->jerarquiaLlaves ?? 0) >= 1);

        $porNivel = [];
        foreach ($conNivel as $p) {
            $porNivel[(int) $p->jerarquiaLlaves][] = (int) $p->id;
        }

        $niveles = array_keys($porNivel);
        sort($niveles);

        $cambios = [];

        foreach ($puestos as $p) {
            $arreglo = $p->reports_to_role_ids ? json_decode($p->reports_to_role_ids, true) : null;
            $tieneArreglo = is_array($arreglo) && count($arreglo) > 0;

            if ($p->reports_to_role_id && !$tieneArreglo) {
                $cambios[(int) $p->id] = [
                    'jefe' => (int) $p->reports_to_role_id,
                    'motivo' => 'ya tenía jefe; le faltaba la línea punteada',
                    'datos' => [
                        'reports_to_role_ids' => json_encode([(int) $p->reports_to_role_id]),
                        'org_parent_role_id' => $p->org_parent_role_id ?: $p->reports_to_role_id,
                        'updated_at' => now(),
                    ],
                ];

                continue;
            }

            if ($p->reports_to_role_id || $tieneArreglo) {
                continue; // ya está conectado
            }

            $nivel = (int) ($p->jerarquiaLlaves ?? 0);

            if ($nivel < 1) {
                // Sin nivel declarado no hay dónde colgarlo: se deja como está y que lo acomode
                // quien conoce la empresa, arrastrándolo en el organigrama.
                continue;
            }

            $jefeId = null;

            foreach (array_reverse($niveles) as $candidato) {
                if ($candidato < $nivel && !empty($porNivel[$candidato])) {
                    $jefeId = $porNivel[$candidato][0];
                    break;
                }
            }

            if ($jefeId === null || $jefeId === (int) $p->id) {
                continue; // es la cabeza de la empresa: no reporta a nadie
            }

            $cambios[(int) $p->id] = [
                'jefe' => $jefeId,
                'motivo' => "huérfano; nivel {$nivel}",
                'datos' => [
                    'reports_to_role_id' => $jefeId,
                    'reports_to_role_ids' => json_encode([$jefeId]),
                    'org_parent_role_id' => $jefeId,
                    'nivel_mando' => $nivel,
                    'updated_at' => now(),
                ],
            ];
        }

        return $cambios;
    }
}
