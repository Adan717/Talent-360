<?php

use App\Support\JornadaExtraordinaria;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tope de tiempo extraordinario por semana, configurable por empresa (2026-09-05).
 *
 * Hasta hoy el producto no tenía tope de horas extra en ninguna parte: ni constante, ni ajuste, ni
 * aviso, ni contador semanal. Se nace en el TECHO DE LEY —art. 66 LFT: 3 horas diarias y no más de
 * 3 veces por semana, o sea 9 h = 540 min— para que ninguna empresa existente cambie de
 * comportamiento por esta migración: quien no configure nada queda exactamente en el máximo legal,
 * que es lo que hoy le aplica de hecho. Bajarlo es decisión de cada empresa; subirlo lo rechaza el
 * servidor (`LftSettingController::saveSettings`) y lo recorta el acumulador.
 *
 * Vive en `lft_settings` como el resto de la política laboral por tenant, mismo patrón que
 * `late_tolerance_minutes` y `punctuality_bonus_amount`. NO toca ninguna columna que lea el motor
 * de nómina: el tope avisa, no cobra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lft_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('overtime_weekly_cap_minutes')
                ->default(JornadaExtraordinaria::TECHO_LFT_MINUTOS_SEMANA);
        });
    }

    public function down(): void
    {
        Schema::table('lft_settings', function (Blueprint $table) {
            $table->dropColumn('overtime_weekly_cap_minutes');
        });
    }
};
