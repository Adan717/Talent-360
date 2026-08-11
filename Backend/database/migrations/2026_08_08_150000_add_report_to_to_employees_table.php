<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organigrama de colaboradores: la columna que nunca existió (2026-08-08).
 *
 * `EmployeeController::updateReportTo` está escrito completo —valida, detecta ciclos y
 * responde 200— y `OrganigramaInteractivo.tsx` deja arrastrar tarjetas para reasignar a quién
 * reporta cada quien. Pero `employees.report_to` NO EXISTE en el esquema: ninguna migración la
 * creó y tampoco estaba en `$fillable`, así que el `update(['report_to' => ...])` lo ignoraba
 * en silencio. La pantalla decía "guardado", el endpoint respondía 200 y al recargar el
 * organigrama volvía a salir plano.
 *
 * `nullOnDelete`: si se borra al jefe, quien le reportaba queda sin jefe (huérfano en la
 * raíz), no se borra en cascada con él.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('employees', 'report_to')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('report_to')
                ->nullable()
                ->after('job_role_id')
                ->constrained('employees')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('employees', 'report_to')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('report_to');
        });
    }
};
