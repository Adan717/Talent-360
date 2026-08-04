<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Academia AC3 (auditoría 2026-08-04): los intentos fallidos del examen se contaban en una
 * variable del navegador (`failedAttempts` en Academia.tsx) que se reiniciaba con solo cerrar
 * y volver a abrir el curso — el "Vidas: 2/2" y el "tu curso ha sido bloqueado y se notificó a
 * tu Administrador" no tenían nada detrás. Ahora el conteo lo lleva el servidor, junto al
 * progreso, al calificar cada intento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_course_progress', function (Blueprint $table) {
            $table->unsignedInteger('failed_attempts')->default(0)->after('score');
        });
    }

    public function down(): void
    {
        Schema::table('user_course_progress', function (Blueprint $table) {
            $table->dropColumn('failed_attempts');
        });
    }
};
