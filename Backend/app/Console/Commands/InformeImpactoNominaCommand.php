<?php

namespace App\Console\Commands;

use App\Support\SalarioDiario;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Informe de impacto de la migración a salario diario.
 *
 * Compara, para cada colaborador con sueldo capturado, el diario que la nómina usa HOY
 * (base_salary/6, que asume semanal e infla el diario) contra el que usaría con la
 * conversión declarada (SalarioDiario::desde) — y proyecta el bruto semanal (diario × 7)
 * bajo ambos, que es la base de la que cuelgan proporcionales las deducciones por falta.
 *
 * NO ES UN TEST DE INTEGRACIÓN CONTINUA A PROPÓSITO: si el cálculo actual está mal, un test de
 * "que dé lo mismo que antes" fijaría el error. Este informe lo revisa QUIEN LLEVA LA NÓMINA,
 * y su visto bueno —no un verde de CI— es lo que autoriza recapturar sueldos. Decisión
 * documentada en docs/NOMINA_PERIODICIDAD_MULTIEMPRESA_2026-08-02.md.
 *
 *   php artisan nomina:informe-impacto                     → asume que lo capturado era semanal
 *   php artisan nomina:informe-impacto --como=mensual      → simula otra interpretación
 *   php artisan nomina:informe-impacto --tenant=2 --csv=/tmp/impacto.csv
 */
class InformeImpactoNominaCommand extends Command
{
    protected $signature = 'nomina:informe-impacto
        {--tenant= : Limitar a un tenant}
        {--como=semanal : Periodicidad con la que se INTERPRETA lo ya capturado (semanal|quincenal|mensual|diario)}
        {--csv= : Ruta donde dejar el CSV para quien revisa}';

    protected $description = 'Compara la nómina actual contra la fórmula de salario diario, colaborador por colaborador, para revisión humana';

    public function handle(): int
    {
        $como = strtolower((string) $this->option('como'));

        if (!SalarioDiario::esValida($como)) {
            $this->error("Periodicidad '{$como}' desconocida. Válidas: " . implode(', ', SalarioDiario::periodicidades()));

            return self::FAILURE;
        }

        $empleados = DB::table('employees')
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('users.tenant_id', $t))
            ->whereNotNull('employees.base_salary')
            ->where('employees.base_salary', '>', 0)
            ->orderBy('users.tenant_id')
            ->get([
                'users.tenant_id', 'employees.id', 'employees.name', 'employees.base_salary',
                'employees.salario_diario', 'employees.periodicidad_captura',
            ]);

        if ($empleados->isEmpty()) {
            $this->info('No hay colaboradores con sueldo capturado que comparar.');

            return self::SUCCESS;
        }

        $filas = $empleados->map(function ($e) use ($como) {
            // Lo que la nómina usa HOY para este expediente (la misma preferencia que ClockService):
            $diarioHoy = ($e->salario_diario !== null && (float) $e->salario_diario > 0)
                ? (float) $e->salario_diario
                : (float) $e->base_salary / 6.0;

            // Lo que usaría con la conversión declarada (o simulada vía --como):
            $periodicidad = $e->periodicidad_captura ?? $como;
            $diarioNuevo = SalarioDiario::desde((float) $e->base_salary, $periodicidad);

            $brutoHoy = $diarioHoy * 7;
            $brutoNuevo = $diarioNuevo * 7;

            return [
                'tenant' => $e->tenant_id,
                'colaborador' => $e->name,
                'capturado' => number_format((float) $e->base_salary, 2),
                'interpretado_como' => $periodicidad . ($e->periodicidad_captura ? '' : ' (supuesto)'),
                'diario_hoy' => number_format($diarioHoy, 2),
                'diario_nuevo' => number_format($diarioNuevo, 2),
                'bruto_semanal_hoy' => number_format($brutoHoy, 2),
                'bruto_semanal_nuevo' => number_format($brutoNuevo, 2),
                'diferencia_semanal' => number_format($brutoNuevo - $brutoHoy, 2),
            ];
        });

        $this->table(array_keys($filas->first()), $filas->toArray());
        $this->line('');
        $this->warn('Las deducciones por falta y prima dominical escalan proporcionales al diario: si el');
        $this->warn('diario cambia, cambian con él. Este informe lo revisa quien lleva la nómina; su visto');
        $this->warn('bueno autoriza recapturar sueldos con periodicidad — no migrar por fórmula.');

        if ($ruta = $this->option('csv')) {
            $f = fopen($ruta, 'w');
            fputcsv($f, array_keys($filas->first()));
            foreach ($filas as $fila) {
                fputcsv($f, $fila);
            }
            fclose($f);
            $this->info("CSV para revisión: {$ruta}");
        }

        return self::SUCCESS;
    }
}
