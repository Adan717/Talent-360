<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ¿El examen de este curso lo aprobó su empresa? (Fase 2, 2026-08-24)
 *
 * Los 5 catálogos de giro siembran cada curso con UNA pregunta de relleno —"¿Cuál es el objetivo
 * principal de este protocolo?"— cuya respuesta correcta es siempre la primera opción. Con eso se
 * obtiene un certificado "100%" en un minuto, y uno de esos cursos es **Derechos Laborales y Ley
 * Federal del Trabajo**. Ese certificado lleva folio y se verifica **en público, sin sesión**: lo
 * va a leer un inspector o un abogado como constancia de capacitación de la empresa.
 *
 * Esta columna separa el examen que la empresa configuró de verdad del relleno del catálogo. Nace
 * NULA para todo lo existente a propósito: ningún examen ha sido aprobado nunca por nadie, y
 * suponer lo contrario sería exactamente la clase de mentira que esta ronda persigue. Se llena
 * sola en cuanto el administrador guarda el examen desde su pantalla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academy_courses', function (Blueprint $table) {
            $table->timestamp('quiz_approved_at')->nullable()->after('quiz_data');
            $table->unsignedBigInteger('quiz_approved_by')->nullable()->after('quiz_approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('academy_courses', function (Blueprint $table) {
            $table->dropColumn(['quiz_approved_at', 'quiz_approved_by']);
        });
    }
};
