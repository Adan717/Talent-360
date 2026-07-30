<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * H1 (prueba en vivo 2026-07-29): la pantalla de alta guardaba el sueldo en `salary` y dejaba
 * `base_salary` en NULL, pero el dinero lo calcula `base_salary`:
 *   - costo financiero de la tarea: `base_salary > 0 ? base_salary : 300.00`
 *   - snapshot inmutable del ponche: `base_salary_at_time`
 * Todo colaborador dado de alta desde esa pantalla se calculaba con $300/día.
 *
 * `EmployeeController` ya espeja ambas columnas al crear/editar; esta migración repara a los
 * que YA existen. Sólo toca filas donde `base_salary` está vacío y `salary` tiene un valor
 * útil: nunca pisa un `base_salary` ya capturado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('employees')
            || !Schema::hasColumn('employees', 'salary')
            || !Schema::hasColumn('employees', 'base_salary')) {
            return;
        }

        $reparados = DB::table('employees')
            ->whereNull('base_salary')
            ->whereNotNull('salary')
            ->where('salary', '>', 0)
            ->update(['base_salary' => DB::raw('salary')]);

        if ($reparados > 0) {
            \Illuminate\Support\Facades\Log::info(
                "backfill_base_salary: {$reparados} colaborador(es) tenían el sueldo sólo en `salary`; " .
                "se copió a `base_salary` para que el costo de tareas y la nómina dejen de usar el default de \$300."
            );
        }
    }

    public function down(): void
    {
        // Irreversible a propósito: no se puede distinguir un `base_salary` reparado por esta
        // migración de uno capturado a mano, y revertirlo devolvería el bug de los $300.
    }
};
