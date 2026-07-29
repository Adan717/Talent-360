<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §67: foto de fichaje configurable + inmutabilidad del cálculo de puntualidad.
 *
 * B — método de verificación realmente usado y evidencia:
 *   - photo_url            : evidencia fotográfica del fichaje (nullable).
 *   - verification_method  : lo que OCURRIÓ (GEOFENCE_PIN_PHOTO / GEOFENCE_PIN / GEOFENCE_ONLY).
 *   - photo_skipped_reason : distingue "no se pidió" (not_required) de "se pidió y no se pudo"
 *                            (camera_unavailable / permission_denied). Sin esto ambos casos se
 *                            ven idénticos en reportes y la configuración deja de ser auditable.
 *   - flagged_for_review   : (C.2) el fichaje con foto omitida por falla de cámara queda VISIBLE
 *                            como incidencia para el supervisor, no enterrado en audit_logs.
 *
 * E.1 — Fotografía del momento (inmutabilidad de históricos): se guarda el cálculo de retardo
 *   tal como fue, para que cambiar la tolerancia (ej. 10→5 min) NO recalcule la puntualidad
 *   pasada de forma retroactiva. Mismo patrón que employee_name_at_time / base_salary_at_time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->string('photo_url')->nullable()->after('base_salary_at_time');
            $table->string('verification_method')->nullable()->after('photo_url');
            $table->string('photo_skipped_reason')->nullable()->after('verification_method');
            $table->boolean('flagged_for_review')->default(false)->after('photo_skipped_reason');
            // E.1 — snapshot inmutable del cálculo de puntualidad al momento del fichaje.
            $table->integer('tardiness_minutes_at_time')->nullable()->after('flagged_for_review');
            $table->integer('tolerance_mins_at_time')->nullable()->after('tardiness_minutes_at_time');
            $table->string('tolerance_version')->nullable()->after('tolerance_mins_at_time');

            // El monitor del supervisor consulta las incidencias pendientes por empresa.
            $table->index(['tenant_id', 'flagged_for_review']);
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'flagged_for_review']);
            $table->dropColumn([
                'photo_url', 'verification_method', 'photo_skipped_reason', 'flagged_for_review',
                'tardiness_minutes_at_time', 'tolerance_mins_at_time', 'tolerance_version',
            ]);
        });
    }
};
