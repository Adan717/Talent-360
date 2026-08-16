<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desglose del recibo (2026-08-16). El motor YA calculaba todo esto —`deductions_breakdown`
 * y `bonus` del array que devuelve `ClockService::calculatePayrollForEmployee`— pero al
 * guardar sólo se conservaba el TOTAL de deducciones y el neto: el resto se tiraba. Por eso
 * no se podía decir "cuánto costaron las faltas" ni separar el costo por concepto.
 *
 * Todas nullable A PROPÓSITO: los recibos ya existentes NO tienen desglose y no se les
 * inventa uno. Un `null` significa "este recibo es anterior al desglose", que es distinto de
 * un 0 ("se calculó y no hubo"). El reporte de costo distingue las dos cosas.
 *
 * Nada de esto cambia el neto: son las partes que ya lo componían, ahora visibles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weekly_payrolls', function (Blueprint $table) {
            // Percepciones
            $table->decimal('gross_pay', 10, 2)->nullable()->after('base_salary_paid');
            $table->decimal('holiday_bonus_pay', 10, 2)->nullable()->after('gross_pay');
            $table->decimal('punctuality_bonus', 10, 2)->nullable()->after('holiday_bonus_pay');
            $table->decimal('opening_bonus', 10, 2)->nullable()->after('punctuality_bonus');
            // Deducciones por concepto (su suma es la columna `deductions` que ya existía)
            $table->decimal('deduction_absences', 10, 2)->nullable()->after('deductions');
            $table->decimal('deduction_rest_day', 10, 2)->nullable()->after('deduction_absences');
            $table->decimal('deduction_lates', 10, 2)->nullable()->after('deduction_rest_day');
            // Sueldo diario con el que se calculó (para reconstruir sin adivinar)
            $table->decimal('daily_salary', 10, 2)->nullable()->after('deduction_lates');
            // El puesto EN EL MOMENTO del recibo: agrupar el costo histórico por el puesto de
            // HOY movería el gasto de área cada vez que alguien cambia de puesto.
            $table->string('job_role_title_at_time')->nullable()->after('daily_salary');
            $table->string('job_role_area_at_time')->nullable()->after('job_role_title_at_time');
        });
    }

    public function down(): void
    {
        Schema::table('weekly_payrolls', function (Blueprint $table) {
            $table->dropColumn([
                'gross_pay', 'holiday_bonus_pay', 'punctuality_bonus', 'opening_bonus',
                'deduction_absences', 'deduction_rest_day', 'deduction_lates',
                'daily_salary', 'job_role_title_at_time', 'job_role_area_at_time',
            ]);
        });
    }
};
