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
#[Signature('tenants:fijar-zona-horaria {--zona=America/Mexico_City : Zona a escribir} {--aplicar : Escribir (sin esto es un simulacro)} {--tenant= : Solo esta empresa; con --aplicar SOBRESCRIBE su zona (via de correccion para clientes de otro huso)}')]
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

            // (2026-08-27) Con `--tenant` explícito, la zona ya declarada SÍ se sobrescribe: es
            // la vía de corrección para un cliente de otro huso, ahora que toda empresa nace con
            // zona escrita y "no tenerla" dejó de existir. En modo masivo (sin --tenant) se
            // conserva la regla original: sólo se rellena a quien no declara nada — un barrido
            // nunca pisa una zona que alguien eligió.
            $esCorreccionDirigida = $this->option('tenant') !== null;

            if (!$esCorreccionDirigida && $tiene !== null && trim((string) $tiene) !== '') {
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
        // (2026-08-27) Corregido: este mensaje decía "corrígela en su Configuración" y esa
        // pantalla NO tiene campo de zona horaria — mandaba a la gente a buscar algo que no
        // existe. Hasta que exista el campo, la vía real es este mismo comando.
        $this->line('Si alguna empresa es de otra zona (Tijuana −8, Sonora/Sinaloa −7), corrígela con:');
        $this->line('  php artisan tenants:fijar-zona-horaria --zona=America/Mazatlan --tenant=N --aplicar');
        $this->line('(la pantalla de Configuración aún no tiene este campo; está anotado como pendiente)');

        return self::SUCCESS;
    }
}
