<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §53: comprobantes mensuales de cumplimiento del plan freemium (ej. compartir
     * publicaciones). El tenant sube su comprobante por periodo; Plataforma Talent360
     * lo revisa. v1 sin verificación automática por API de redes — auto-reporte +
     * revisión manual.
     */
    public function up(): void
    {
        Schema::create('freemium_compliance_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->string('period', 7); // "YYYY-MM"
            $table->string('status')->default('pending'); // pending, submitted, approved, rejected
            $table->text('proof_note')->nullable();
            $table->text('proof_url')->nullable(); // URL o data URI de la captura
            $table->unsignedBigInteger('reviewed_by')->nullable(); // platform_users.id
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freemium_compliance_checks');
    }
};
