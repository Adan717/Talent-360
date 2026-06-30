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
        Schema::create('job_role_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('area');
            $table->string('industry'); // 'retail', 'restaurante', 'manufactura', 'oficina', 'salud', 'educacion'
            $table->string('default_schedule_start');
            $table->string('default_schedule_end');
            $table->integer('default_tolerance_mins')->default(10);
            $table->integer('default_meal_mins')->default(60);
            $table->boolean('is_opener')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_role_templates');
    }
};
