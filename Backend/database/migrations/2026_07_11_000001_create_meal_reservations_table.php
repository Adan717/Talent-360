<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_reservations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('job_role_id')->nullable()->index();
            $table->date('reservation_date')->index();
            // Bloque de horario: "14:00" - "15:00"
            $table->time('slot_start');
            $table->time('slot_end');
            // Estado de la reserva
            $table->enum('status', ['reserved', 'confirmed', 'cancelled', 'swapped'])->default('reserved');
            // Si fue cedida/intercambiada, quién la tomó
            $table->unsignedBigInteger('swapped_to_user_id')->nullable();
            // Snapshot histórico (Directiva 2: Inmutabilidad Histórica)
            $table->string('employee_name_at_time')->nullable();
            $table->string('job_role_title_at_time')->nullable();
            // Justificación si se cancela (liberación reactiva)
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->softDeletes(); // Directiva 1: Soft Deletes

            // Garantizar que un empleado no tenga dos reservas en el mismo slot y día
            $table->unique(['user_id', 'reservation_date', 'slot_start', 'status']);
        });

        // Configuración de aforo del comedor por tenant
        Schema::create('meal_capacity_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->unique();
            // Capacidad máxima del comedor en simultáneo
            $table->unsignedInteger('max_capacity')->default(5);
            // Duración de cada slot en minutos
            $table->unsignedInteger('slot_duration_minutes')->default(60);
            // Slots del día en formato JSON: ["12:00", "13:00", "14:00", "15:00"]
            $table->json('available_slots')->nullable();
            // Tolerancia en minutos para llegada al comedor
            $table->unsignedInteger('late_tolerance_minutes')->default(10);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_capacity_settings');
        Schema::dropIfExists('meal_reservations');
    }
};
