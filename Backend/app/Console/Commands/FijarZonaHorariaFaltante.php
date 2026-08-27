<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deja escrita la zona horaria de las empresas que no la declaran (2026-08-26).
 *
 * De la zona horaria dependen los **retardos** y el **corte del día** en nómina: es la que decide
 * si alguien llegó tarde y a qué jornada pertenece un fichaje de la madrugada. Hoy esas empresas
 * funcionan porque `TenantTimezone::for()` cae a `America/Mexico_City` — pero es un supuesto que
 * no está escrito en ningún lado, y el preflight lo señala en cada despliegue.
 *
 * Escribir el valor que YA se está usando no cambia el comportamiento de nadie: convierte una
 * suposición silenciosa en un dato declarado, que es la diferencia entre "funciona por casualidad"
 * y "funciona porque alguien lo decidió". Y el día que un cliente sea de Tijuana o de Cancún, se
 * ve de un vistazo cuál está mal.
 *
 * COMANDO, NO MIGRACIÓN: una migración correría también en bases nuevas y en las de pruebas,
 * sembrando un ajuste que nadie pidió. Esto se corre a propósito, sobre las empresas que existen,
 * y es idempotente: a quien ya la tiene no se le toca.
 */
#[Signature('tenants:fijar-zona-horaria {--zona=America/Mexico_City : Zona a escribir en las que no la declaran} {--aplicar : Escribir (sin esto es un simulacro)}')]
#[Description('Escribe la zona horaria en las empresas que no la tienen declarada. Simulacro por defecto.')]
class FijarZonaHorariaFaltante extends Command
{
    public function handle(): int
    {
        $zona = (string) $this->option('zona');

        // Una zona inválida rompería Carbon en cada cálculo de jornada. Se valida antes de tocar.
        if (!in_array($zona, timezone_identifiers_list(), true)) {
            $this->error("'{$zona}' no es una zona horaria válida (se esperaba algo como America/Mexico_City).");

            return self::FAILURE;
        }

        $aplicar = (bool) $this->option('aplicar');
        $sinZona = [];

        foreach (Tenant::where('is_active', true)->orderBy('id')->get() as $tenant) {
            $tiene = DB::table('system_settings')
                ->where('tenant_id', $tenant->id)
                ->where('key', 'timezone')
                ->value('value');

            if ($tiene !== null && trim((string) $tiene) !== '') {
                continue;
            }

            $sinZona[] = $tenant;
        }

        if (empty($sinZona)) {
            $this->info('Todas las empresas activas ya declaran su zona horaria.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line($aplicar ? '── ESCRIBIENDO ──' : '── SIMULACRO (nada se escribe; use --aplicar) ──');
        $this->table(
            ['Empresa', 'Zona que se le escribe'],
            array_map(fn ($t) => [$t->id . ' · ' . $t->name, $zona], $sinZona)
        );

        if (!$aplicar) {
            $this->warn(count($sinZona) . ' empresa(s) quedarían con ' . $zona . '. Nada se escribió.');

            return self::SUCCESS;
        }

        foreach ($sinZona as $tenant) {
            DB::table('system_settings')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'key' => 'timezone'],
                [
                    // Se guarda como JSON porque así lo lee el resto del sistema
                    // (`TenantTimezone` hace json_decode del valor).
                    'value' => json_encode($zona),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $this->info(count($sinZona) . ' empresa(s) con su zona horaria ya declarada: ' . $zona . '.');
        $this->line('No cambia el comportamiento: es la misma zona que ya se usaba por defecto.');
        $this->line('Si alguna empresa es de otra zona (Tijuana, Cancún), corrígela en su Configuración.');

        return self::SUCCESS;
    }
}
