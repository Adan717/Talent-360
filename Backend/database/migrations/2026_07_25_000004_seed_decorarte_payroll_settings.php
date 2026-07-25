<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sección 2 #1 (decisión de Francisco): DecorArte (tenant 1, la empresa de prueba)
     * arranca con la semana laboral domingo→sábado y pago el sábado.
     *   payroll_week_start_day = 0 (domingo)
     *   payroll_pay_day        = 6 (sábado)
     * Solo se insertan si no existen ya (para no pisar una config que el admin haya
     * ajustado después). El resto de tenants usan los defaults globales (lunes/viernes).
     */
    public function up(): void
    {
        if (!Schema::hasTable('system_settings') || !Schema::hasTable('tenants')) {
            return;
        }
        if (!DB::table('tenants')->where('id', 1)->exists()) {
            return;
        }

        $defaults = [
            'payroll_week_start_day' => 0, // domingo
            'payroll_pay_day' => 6,        // sábado
        ];

        foreach ($defaults as $key => $value) {
            $exists = DB::table('system_settings')->where('tenant_id', 1)->where('key', $key)->exists();
            if (!$exists) {
                DB::table('system_settings')->insert([
                    'tenant_id' => 1,
                    'key' => $key,
                    'value' => json_encode($value),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('system_settings')) {
            DB::table('system_settings')->where('tenant_id', 1)
                ->whereIn('key', ['payroll_week_start_day', 'payroll_pay_day'])
                ->delete();
        }
    }
};
