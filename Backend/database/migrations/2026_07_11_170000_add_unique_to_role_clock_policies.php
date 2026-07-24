<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * role_clock_policies: a lo más UNA política por (job_role_id, tenant_id).
 *
 * `ClockController::updateRolePolicy` hacía un check-then-insert no atómico: bajo carrera
 * dos requests podían insertar dos políticas para el mismo rol. El unique lo respalda y
 * habilita un `upsert` atómico (que además preserva el `policy_name` personalizado).
 * Agregar un índice único sobre tabla existente no requiere ramificar por motor.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Reconciliar filas legacy con tenant_id NULL (creadas antes de que 2026_06_19 agregara
        // la columna). En un unique compuesto los NULL son distintos, así que sin esto el
        // `upsert(... tenant_id ?? 1 ...)` crearía una fila paralela (job_role_id, 1) y dejaría
        // la NULL como dato muerto que el constraint jamás colapsaría.
        //   1) Si la fila NULL ya tiene hermana con tenant concreto, la concreta es la "viva"
        //      (los reads siempre filtran por tenant): se descarta la NULL.
        DB::statement('
            DELETE FROM role_clock_policies
            WHERE tenant_id IS NULL
              AND job_role_id IN (
                  SELECT job_role_id FROM role_clock_policies WHERE tenant_id IS NOT NULL
              )
        ');
        //   2) Las NULL huérfanas restantes se adoptan al tenant por defecto (1), consistente
        //      con el `?? 1` de la app. El paso 1 garantiza que esto no genera conflicto.
        DB::statement('UPDATE role_clock_policies SET tenant_id = 1 WHERE tenant_id IS NULL');

        // Dedupe: conservar MIN(id) por (job_role_id, tenant_id).
        DB::statement('
            DELETE FROM role_clock_policies
            WHERE id NOT IN (
                SELECT MIN(id)
                FROM role_clock_policies
                GROUP BY job_role_id, tenant_id
            )
        ');

        Schema::table('role_clock_policies', function (Blueprint $table) {
            $table->unique(['job_role_id', 'tenant_id'], 'role_clock_policies_job_tenant_unique');
        });
    }

    public function down(): void
    {
        Schema::table('role_clock_policies', function (Blueprint $table) {
            $table->dropUnique('role_clock_policies_job_tenant_unique');
        });
    }
};
