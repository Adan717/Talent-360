<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El sueldo pasa a almacenarse en DIARIO (unidad LFT), con memoria de cómo se capturó.
 *
 * - `salario_diario`: la unidad única que la nómina nueva consume. NULL = expediente legado
 *   sin migrar; ClockService cae a la fórmula histórica (base_salary/6) para no cambiar ni un
 *   peso de nadie hasta que el informe de impacto se revise y cada sueldo se recapture.
 * - `periodicidad_captura`: con qué periodicidad se capturó el monto original (diario/semanal/
 *   quincenal/mensual). Se guarda porque la migración de datos viejos NO puede inferirse del
 *   número — hay que registrar con cuál se capturó cada sueldo (decisión del doc de nómina).
 *
 * A PROPÓSITO no se rellena nada aquí: adivinar la periodicidad de lo ya capturado es
 * exactamente el error que este cambio viene a eliminar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('salario_diario', 10, 2)->nullable()->after('base_salary');
            $table->string('periodicidad_captura', 10)->nullable()->after('salario_diario');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['salario_diario', 'periodicidad_captura']);
        });
    }
};
