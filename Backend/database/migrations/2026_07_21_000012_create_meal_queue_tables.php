<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §24: modo 'queue' de reserva de comida — convive con la selección libre actual
     * (meal_reservations), no la reemplaza. Decisión de Francisco (2026-07-21).
     */
    public function up(): void
    {
        Schema::create('meal_queue_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->integer('store_id')->default(1);
            $table->date('date');
            $table->string('order_by')->default('arrival'); // arrival | random
            $table->string('status')->default('open'); // open | closed
            $table->timestamps();

            $table->unique(['tenant_id', 'store_id', 'date']);
        });

        Schema::create('meal_queue_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained('meal_queue_rounds')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('users')->onDelete('cascade');
            $table->integer('position');
            $table->string('status')->default('waiting'); // waiting | choosing | done
            $table->string('slot_start')->nullable();
            $table->string('slot_end')->nullable();
            $table->timestamps();

            $table->unique(['round_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_queue_entries');
        Schema::dropIfExists('meal_queue_rounds');
    }
};
