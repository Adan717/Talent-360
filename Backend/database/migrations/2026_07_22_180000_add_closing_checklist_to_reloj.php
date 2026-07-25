<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reloj R88 (Fase 3 / T3.3): Checklist Exprés de Cierre (luces / caja fuerte / alarma-cortina).
 *
 * Dos cambios:
 *  1. `lft_settings.require_closing_checklist` — flag por-tenant (default false, opt-in) que ENFORCEA
 *     los 3 ticks en el `check_out` server-side. Mismo patrón que `require_checkout_approval` (R75):
 *     off por defecto = comportamiento previo intacto.
 *  2. `time_entries.details` pasa de `string` (varchar 255) a `text`. El check_out ya guardaba ahí
 *     `{evalStars, delegatedKeysTo, note}` + gps; sumar el checklist de 3 ticks podía DESBORDAR los
 *     255 chars → JSON truncado = inválido = parseo roto (bug silencioso). `audit_logs.details` ya es
 *     `text`; alineamos. text ⊇ varchar(255): sin pérdida de datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lft_settings', function (Blueprint $table) {
            $table->boolean('require_closing_checklist')->default(false);
        });

        Schema::table('time_entries', function (Blueprint $table) {
            $table->text('details')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('lft_settings', function (Blueprint $table) {
            $table->dropColumn('require_closing_checklist');
        });

        Schema::table('time_entries', function (Blueprint $table) {
            $table->string('details')->nullable()->change();
        });
    }
};
