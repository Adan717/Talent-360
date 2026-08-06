<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría N1: el timbrado devolvía un UUID que no se guardaba en NINGUNA tabla — no había
 * registro de qué se timbró ni forma de auditarlo. El recibo fiscal se sella sobre la nómina
 * autorizada: folio fiscal (UUID del SAT), id del recibo en el proveedor (Facturapi) y cuándo.
 * Una nómina con timbrada_at ya no se vuelve a timbrar (idempotencia).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weekly_payrolls', function (Blueprint $table) {
            if (!Schema::hasColumn('weekly_payrolls', 'cfdi_uuid')) {
                $table->string('cfdi_uuid')->nullable();
            }
            if (!Schema::hasColumn('weekly_payrolls', 'cfdi_receipt_id')) {
                $table->string('cfdi_receipt_id')->nullable();
            }
            if (!Schema::hasColumn('weekly_payrolls', 'timbrada_at')) {
                $table->timestamp('timbrada_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('weekly_payrolls', function (Blueprint $table) {
            $table->dropColumn(['cfdi_uuid', 'cfdi_receipt_id', 'timbrada_at']);
        });
    }
};
