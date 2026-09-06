<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cobranza automática (2026-09-05).
 *
 * `billing_exempt` es la vía explícita de exención: la empresa de cortesía, el piloto o el
 * socio al que NO se le cobra. Marcada así, el barrido de mora no la mira nunca, aunque
 * tenga fecha de corte vencida. Nace en `false` para todas: declarar "a ésta no se le cobra"
 * es una decisión comercial del dueño, no algo que una migración deba inventar.
 *
 * `payment_warning_sent_at` es la marca de idempotencia del aviso: el barrido corre a diario
 * y sin ella avisaría todos los días del periodo de gracia. Se compara contra
 * `current_period_end`, así que se "reinicia" sola en cuanto la empresa paga y el ciclo
 * avanza — no hay que limpiarla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('billing_exempt')->default(false);
            $table->string('billing_exempt_reason')->nullable();
            $table->timestamp('payment_warning_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['billing_exempt', 'billing_exempt_reason', 'payment_warning_sent_at']);
        });
    }
};
