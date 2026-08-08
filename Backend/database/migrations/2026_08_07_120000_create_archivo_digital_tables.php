<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archivo Digital (ronda 2026-08): el módulo era un mockup 100% frontend.
 *
 * GestorDocumentos.tsx fabricaba expedientes "validados" en el navegador, la barra de
 * upload era un setInterval que jamás enviaba el archivo (pérdida de datos real: subías
 * el INE y se perdía al refrescar) y el visor mostraba un sello "SAT" inventado. Estas
 * dos tablas son el motor real. Los archivos viven en el disco PRIVADO
 * (storage/app/private/...) con nombre uuid — nunca el nombre original (path traversal)
 * y nunca en public_path() — y solo salen por el endpoint autenticado de descarga.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            // Uno de la checklist fija de 6 (solicitud, acta_nacimiento, ine, curp,
            // rfc, comprobante_domicilio) o 'otro'.
            $table->string('doc_type');

            $table->string('original_name');
            $table->string('path');
            $table->string('mime');
            $table->unsignedBigInteger('size_bytes');

            $table->string('status')->default('pendiente'); // pendiente | validado | rechazado
            $table->text('rejection_reason')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'employee_id']);
        });

        Schema::create('company_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable();

            $table->string('name');
            $table->string('category');
            $table->string('original_name');
            $table->string('path');
            $table->string('mime');
            $table->unsignedBigInteger('size_bytes');

            // El vínculo manual→curso de Academia que el mock solo fingía con un alert.
            $table->foreignId('linked_course_id')->nullable()->constrained('academy_courses')->nullOnDelete();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_documents');
        Schema::dropIfExists('employee_documents');
    }
};
