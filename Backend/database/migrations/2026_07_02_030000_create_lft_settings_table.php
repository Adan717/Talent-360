<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lft_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->integer('lates_per_absence')->default(3);
            $table->boolean('deduct_absence_day')->default(true);
            $table->integer('absences_for_warning')->default(3);
            $table->integer('absences_for_suspension')->default(4);
            $table->boolean('proportional_rest_day')->default(true);
            $table->integer('late_tolerance_minutes')->default(10);
            $table->integer('meal_tolerance_minutes')->default(15);
            $table->integer('rest_tolerance_minutes')->default(10);
            $table->string('late_action_mode')->default('deduct'); // deduct, extend_shift
            $table->boolean('paid_rest_day')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lft_settings');
    }
};
