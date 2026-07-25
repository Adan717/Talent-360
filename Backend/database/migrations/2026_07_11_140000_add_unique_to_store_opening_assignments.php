<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * store_opening_assignments: a lo más UNA asignación de apertura por (tenant_id, employee_id).
 *
 * StoreOpeningController::createAssignment ya lo previene con un `exists` check (por
 * tenant_id + employee_id, sin store_id), pero una apertura concurrente podía insertar dos
 * filas para el mismo empleado y duplicarlo en la jerarquía de openers. El unique se alinea
 * con la invariante que ya impone el código. Agregar un índice único sobre tabla existente
 * no requiere ramificar por motor (a diferencia de dropear un PRIMARY KEY).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Dedupe: conservar MIN(id) por (tenant_id, employee_id). DELETE con subconsulta
        // sobre la misma tabla, soportado por Postgres y SQLite.
        DB::statement('
            DELETE FROM store_opening_assignments
            WHERE id NOT IN (
                SELECT MIN(id)
                FROM store_opening_assignments
                GROUP BY tenant_id, employee_id
            )
        ');

        Schema::table('store_opening_assignments', function (Blueprint $table) {
            $table->unique(['tenant_id', 'employee_id'], 'store_opening_assign_tenant_employee_unique');
        });
    }

    public function down(): void
    {
        Schema::table('store_opening_assignments', function (Blueprint $table) {
            $table->dropUnique('store_opening_assign_tenant_employee_unique');
        });
    }
};
