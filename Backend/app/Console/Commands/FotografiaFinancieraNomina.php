<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\WeeklyPayroll;
use App\Scopes\TenantScope;
use App\Services\ClockService;
use App\Support\SalarioDiario;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * FOTOGRAFÍA FINANCIERA — informe de SÓLO LECTURA (Fase 0, 2026-08-24).
 *
 * Es el guardarraíl que el consejo echó en falta: la diferencia entre "creo que el cambio no
 * movió dinero" y "el dinero no cambió, aquí está la resta". Hace dos cosas y ninguna escribe:
 *
 *   A) Recalcula con el motor de HOY cada nómina ya guardada y la compara, peso a peso, con lo
 *      que quedó registrado. Sirve para dos preguntas distintas: ¿alguien quedó pagado de menos
 *      por un defecto ya corregido (p. ej. las faltas anteriores al alta)?, y —corriéndolo antes
 *      y después de tocar el motor— ¿este cambio movió dinero de alguien?
 *
 *   B) Mide cuánto dinero mueve HOY la fórmula legada `base_salary / 6`. Ese /6 asume que el
 *      sueldo capturado es SEMANAL y lo reparte entre 6 días trabajados, cuando la práctica
 *      LFT reparte entre 7 (el séptimo día es descanso pagado, art. 69). El resultado es un
 *      salario diario inflado ~16.67% y, con él, todo el bruto del periodo. Esto NO se corrige
 *      aquí: se exhibe. La migración es por recaptura explícita del expediente, nunca por
 *      cambiar la fórmula debajo de pagos ya acordados.
 *
 * GARANTÍA DE SÓLO LECTURA: todo corre dentro de una transacción que SIEMPRE se revierte. El
 * motor de nómina tiene una escritura escondida (crea la política LFT del tenant si no existe),
 * y este informe no está autorizado a cambiar la configuración de nadie por el hecho de mirarla.
 */
#[Signature('nomina:fotografia {--tenant= : Sólo esta empresa} {--json : Salida en JSON para diffear entre corridas}')]
#[Description('Informe de sólo lectura: recalcula las nóminas guardadas y las compara en pesos, y mide el dinero que mueve la fórmula legada base_salary/6.')]
class FotografiaFinancieraNomina extends Command
{
    /** Umbral en pesos por debajo del cual una diferencia es ruido de redondeo. */
    private const CENTAVO = 0.005;

    /** @var array<int,string> nombre de cada empresa, para que la tabla se lea sin adivinar */
    private array $empresas = [];

    public function handle(ClockService $clock): int
    {
        $tenantFiltro = $this->option('tenant') ? (int) $this->option('tenant') : null;
        $this->empresas = Tenant::pluck('name', 'id')->all();

        // Ver la nota de la clase: el motor crea la política LFT del tenant si falta. Una
        // fotografía no cambia lo fotografiado.
        DB::beginTransaction();

        try {
            $nominas = $this->recalcularNominasGuardadas($clock, $tenantFiltro);
            $divisor = $this->analizarDivisorSeis($tenantFiltro);
        } finally {
            DB::rollBack();
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'nominas' => $nominas,
                'divisor_seis' => $divisor,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->imprimirSeccionA($nominas);
        $this->imprimirSeccionB($divisor);

        return self::SUCCESS;
    }

    // ---------------------------------------------------------------- Sección A

    /** @return array<int,array<string,mixed>> */
    private function recalcularNominasGuardadas(ClockService $clock, ?int $tenantFiltro): array
    {
        $query = WeeklyPayroll::withoutGlobalScope(TenantScope::class)
            ->orderBy('tenant_id')->orderBy('start_date')->orderBy('employee_id');

        if ($tenantFiltro) {
            $query->where('tenant_id', $tenantFiltro);
        }

        $filas = [];

        foreach ($query->get() as $registro) {
            $employee = Employee::withoutGlobalScope(TenantScope::class)
                ->where('id', $registro->employee_id)
                ->first();

            $fila = [
                'payroll_id' => $registro->id,
                'tenant_id' => (int) $registro->tenant_id,
                'empresa' => $this->empresa((int) $registro->tenant_id),
                'employee_id' => (int) $registro->employee_id,
                'empleado' => $employee->name ?? '(expediente borrado)',
                'periodo' => $this->soloFecha($registro->start_date) . ' → ' . $this->soloFecha($registro->end_date),
                'estado' => (string) $registro->status,
                'firmada' => $this->estaFirmada((string) $registro->status),
                'guardado' => [
                    'base' => (float) $registro->base_salary_paid,
                    'faltas' => (int) $registro->absences_count,
                    'retardos' => (int) $registro->lates_count,
                    'deducciones' => (float) $registro->deductions,
                    'neto' => (float) $registro->net_pay,
                ],
            ];

            if (!$employee) {
                // Sin expediente no hay con qué recalcular. Se reporta, no se inventa.
                $fila['recalculado'] = null;
                $fila['diferencia_neto'] = null;
                $fila['nota'] = 'No se puede recalcular: el expediente ya no existe.';
                $filas[] = $fila;
                continue;
            }

            try {
                $hoy = $clock->calculatePayrollForEmployee(
                    $employee,
                    $this->soloFecha($registro->start_date),
                    $this->soloFecha($registro->end_date)
                );
            } catch (\Throwable $e) {
                $fila['recalculado'] = null;
                $fila['diferencia_neto'] = null;
                $fila['nota'] = 'El motor no pudo recalcular este periodo: ' . $e->getMessage();
                $filas[] = $fila;
                continue;
            }

            $fila['recalculado'] = [
                'base' => round((float) $hoy['salary']['base'], 2),
                'faltas' => (int) $hoy['incidents']['total_absences'],
                'retardos' => (int) $hoy['incidents']['lates'],
                'deducciones' => round((float) $hoy['deductions_breakdown']['total'], 2),
                'neto' => round((float) $hoy['salary']['net'], 2),
            ];

            $fila['diferencia_neto'] = round($fila['recalculado']['neto'] - $fila['guardado']['neto'], 2);
            $fila['diferencia_faltas'] = $fila['recalculado']['faltas'] - $fila['guardado']['faltas'];
            $filas[] = $fila;
        }

        return $filas;
    }

    private function imprimirSeccionA(array $filas): void
    {
        $this->newLine();
        $this->line('════════════════════════════════════════════════════════════════════════════');
        $this->line('  FOTOGRAFÍA FINANCIERA — informe de SÓLO LECTURA (nada se modificó)');
        $this->line('════════════════════════════════════════════════════════════════════════════');
        $this->newLine();
        $this->line('A) NÓMINAS GUARDADAS, RECALCULADAS CON EL MOTOR DE HOY');
        $this->newLine();

        if (empty($filas)) {
            $this->warn('   No hay ninguna nómina guardada todavía.');
            $this->newLine();

            return;
        }

        $tabla = [];
        $sumaDiferencias = 0.0;
        $conDiferencia = 0;
        $firmadasConDiferencia = [];
        $sinRecalcular = 0;

        foreach ($filas as $f) {
            if ($f['recalculado'] === null) {
                $sinRecalcular++;
                $tabla[] = [
                    $f['empresa'], $f['empleado'], $f['periodo'],
                    $this->etiquetaEstado($f), '—', '—', '—', 'no se pudo recalcular',
                ];
                continue;
            }

            $dif = (float) $f['diferencia_neto'];
            $sumaDiferencias += $dif;

            if (abs($dif) >= self::CENTAVO) {
                $conDiferencia++;
                if ($f['firmada']) {
                    $firmadasConDiferencia[] = $f;
                }
            }

            $tabla[] = [
                $f['empresa'],
                $f['empleado'],
                $f['periodo'],
                $this->etiquetaEstado($f),
                $this->pesos($f['guardado']['neto']),
                $this->pesos($f['recalculado']['neto']),
                $this->pesosConSigno($dif),
                $this->notaFaltas($f),
            ];
        }

        $this->table(
            ['Empresa', 'Colaborador', 'Periodo', 'Estado', 'Neto guardado', 'Neto hoy', 'Diferencia', 'Observación'],
            $tabla
        );

        $this->newLine();
        $this->line('   Nóminas revisadas: ' . count($filas)
            . '  ·  con diferencia: ' . $conDiferencia
            . '  ·  sin poder recalcular: ' . $sinRecalcular);
        $this->line('   Diferencia total (lo que el motor de hoy pagaría de más o de menos): '
            . $this->pesosConSigno($sumaDiferencias));
        $this->newLine();

        if (!empty($firmadasConDiferencia)) {
            $this->error('   ⚠  HAY NÓMINAS YA FIRMADAS CON DIFERENCIA:');
            foreach ($firmadasConDiferencia as $f) {
                $signo = $f['diferencia_neto'] > 0 ? 'de MENOS' : 'de MÁS';
                $this->line('      · ' . $f['empleado'] . ' — ' . $f['empresa']
                    . ' (' . $f['periodo'] . '): se le pagó '
                    . $signo . ' ' . $this->pesos(abs((float) $f['diferencia_neto']))
                    . $this->notaFaltasLarga($f));
            }
            $this->line('        Antes de mover un peso: comprobar si esa empresa es real o de pruebas.');
            $this->newLine();
        } elseif ($conDiferencia === 0) {
            $this->info('   ✔ Ninguna nómina guardada cambia con el motor de hoy.');
            $this->newLine();
        } else {
            $this->line('   Las diferencias están todas en nóminas en BORRADOR: aún no son dinero pagado.');
            $this->newLine();
        }
    }

    // ---------------------------------------------------------------- Sección B

    /** @return array<string,mixed> */
    private function analizarDivisorSeis(?int $tenantFiltro): array
    {
        $query = Employee::withoutGlobalScope(TenantScope::class)
            ->where('is_active_employee', '!=', false)
            ->orderBy('tenant_id')->orderBy('id');

        if ($tenantFiltro) {
            $query->where('tenant_id', $tenantFiltro);
        }

        $porLaLegada = [];
        $conDiarioDeclarado = 0;
        $sinSueldo = 0;
        $totalSemanal = 0.0;

        foreach ($query->get() as $employee) {
            // Mismo orden de precedencia que el motor, sin su default escondido: aquí interesa
            // saber quién NO tiene sueldo capturado, no taparlo con un número.
            // Se pregunta al MISMO calculador que usa la nomina: si el informe tuviera su propia
            // idea de como se resuelve un sueldo, mediria algo que no es lo que se paga.
            $resuelto = \App\Support\SalarioDiarioCalculator::para($employee);

            if ($resuelto['pendiente_sueldo']) {
                $sinSueldo++;
                continue;
            }

            if (!$resuelto['pendiente_periodicidad']) {
                $conDiarioDeclarado++;
                continue;
            }

            $base = (float) $resuelto['base'];
            $diarioLegado = $base / 6.0;                       // lo que el motor usa hoy
            $diarioSemanal = SalarioDiario::desde($base, 'semanal'); // base / 7, la práctica LFT
            $brutoLegado = $diarioLegado * 7;
            $brutoSemanal = $diarioSemanal * 7;
            $difSemana = $brutoLegado - $brutoSemanal;
            $totalSemanal += $difSemana;

            $porLaLegada[] = [
                'tenant_id' => (int) $employee->tenant_id,
                'empresa' => $this->empresa((int) $employee->tenant_id),
                'empleado' => $employee->name,
                'sueldo_capturado' => round($base, 2),
                'diario_hoy' => round($diarioLegado, 2),
                'diario_si_fuera_semanal' => round($diarioSemanal, 2),
                // El defecto de fondo NO es el 6 contra el 7: es que `base_salary` no declara
                // periodicidad y el motor SUPONE que es semanal. Si ese número era mensual, el
                // diario sale casi cinco veces más grande. Se muestran las tres lecturas para que
                // un humano diga cuál era la de verdad — el sistema no puede saberlo.
                'diario_si_fuera_quincenal' => SalarioDiario::desde($base, 'quincenal'),
                'diario_si_fuera_mensual' => SalarioDiario::desde($base, 'mensual'),
                'bruto_semana_hoy' => round($brutoLegado, 2),
                'bruto_semana_si_fuera_semanal' => round($brutoSemanal, 2),
                'diferencia_por_semana' => round($difSemana, 2),
            ];
        }

        return [
            'por_la_formula_legada' => $porLaLegada,
            'con_diario_declarado' => $conDiarioDeclarado,
            'sin_sueldo_capturado' => $sinSueldo,
            'diferencia_total_por_semana' => round($totalSemanal, 2),
            'diferencia_total_por_anio' => round($totalSemanal * 52, 2),
        ];
    }

    private function imprimirSeccionB(array $d): void
    {
        $this->line('────────────────────────────────────────────────────────────────────────────');
        $this->line('B) EXPEDIENTES SIN PERIODICIDAD DECLARADA (supuesto histórico /6)');
        $this->newLine();
        $this->line('   El motor reparte el sueldo capturado entre 6 días trabajados. La práctica');
        $this->line('   LFT lo reparte entre 7, porque el séptimo día es descanso PAGADO (art. 69).');
        $this->line('   Repartir entre 6 y pagar 7 infla el salario diario un 16.67%.');
        $this->newLine();

        $filas = $d['por_la_formula_legada'];

        $this->line('   Plantilla activa:  ' . (count($filas) + $d['con_diario_declarado'] + $d['sin_sueldo_capturado']) . ' colaboradores');
        $this->line('     · con periodicidad declarada (el diario es el real): ' . $d['con_diario_declarado']);
        $this->line('     · SIN declarar periodicidad — se les conserva el supuesto histórico /6: ' . count($filas));
        $this->line('     · sin sueldo capturado: ' . $d['sin_sueldo_capturado']
            . ($d['sin_sueldo_capturado'] > 0 ? '   ← los que hoy viajan con el $2,400 escondido' : ''));
        $this->newLine();

        if (empty($filas)) {
            $this->info('   ✔ Nadie de la plantilla activa pasa hoy por la fórmula /6.');
            $this->newLine();

            return;
        }

        $tabla = [];
        foreach ($filas as $f) {
            $tabla[] = [
                $f['empresa'],
                $f['empleado'],
                $this->pesos($f['sueldo_capturado']),
                $this->pesos($f['diario_hoy']),
                $this->pesos($f['diario_si_fuera_semanal']),
                $this->pesos($f['diario_si_fuera_quincenal']),
                $this->pesos($f['diario_si_fuera_mensual']),
                $this->pesosConSigno($f['diferencia_por_semana']),
            ];
        }

        $this->table(
            ['Empresa', 'Colaborador', 'Sueldo capturado', 'Diario HOY (/6)', 'si SEMANAL', 'si QUINCENAL', 'si MENSUAL', 'De más por semana'],
            $tabla
        );

        $this->newLine();
        $this->line('   Cómo se lee: el motor SUPONE hoy que el sueldo capturado es semanal. Las tres');
        $this->line('   columnas siguientes son el salario diario que saldría si ese mismo número');
        $this->line('   fuera semanal, quincenal o mensual. La última columna sólo compara /6 contra');
        $this->line('   /7 — pero si alguno de esos sueldos era MENSUAL, la distorsión real no es del');
        $this->line('   16.67%: es de casi cinco veces. Quién tiene qué periodicidad lo dice el');
        $this->line('   contrato de cada persona, no el sistema.');
        $this->newLine();
        $this->line('   Sobrepago por la fórmula /6:  ' . $this->pesos($d['diferencia_total_por_semana']) . ' por semana');
        $this->line('                                 ' . $this->pesos($d['diferencia_total_por_anio']) . ' al año (× 52 semanas)');
        $this->newLine();
        $this->warn('   Este informe NO corrige nada. El /6 se queda intacto: cambiarlo bajaría el');
        $this->warn('   sueldo de gente con un pago ya acordado. La migración es por recaptura');
        $this->warn('   explícita del expediente (capturando la periodicidad real), nunca por');
        $this->warn('   cambiar la fórmula debajo de quien ya cobra con ella.');
        $this->newLine();
    }

    // ---------------------------------------------------------------- utilería

    private function empresa(int $tenantId): string
    {
        return $this->empresas[$tenantId] ?? ('Empresa ' . $tenantId);
    }

    private function estaFirmada(string $status): bool
    {
        return in_array($status, ['approved_by_employee', 'approved_by_admin', 'finalized', 'paid'], true);
    }

    private function etiquetaEstado(array $f): string
    {
        return $f['firmada'] ? '🔒 ' . $f['estado'] : $f['estado'];
    }

    private function notaFaltas(array $f): string
    {
        $dif = $f['diferencia_faltas'] ?? 0;

        if ($dif === 0) {
            return '';
        }

        return $dif < 0
            ? abs($dif) . ' falta(s) de menos hoy'
            : $dif . ' falta(s) de más hoy';
    }

    private function notaFaltasLarga(array $f): string
    {
        $nota = $this->notaFaltas($f);

        return $nota === '' ? '' : ' (' . $nota . ')';
    }

    private function soloFecha($fecha): string
    {
        return substr((string) $fecha, 0, 10);
    }

    private function pesos(float $n): string
    {
        return '$' . number_format($n, 2);
    }

    private function pesosConSigno(float $n): string
    {
        if (abs($n) < self::CENTAVO) {
            return '$0.00';
        }

        return ($n > 0 ? '+' : '-') . '$' . number_format(abs($n), 2);
    }
}
