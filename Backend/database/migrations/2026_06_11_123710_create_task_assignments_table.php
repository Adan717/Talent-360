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
        Schema::create('task_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->onDelete('cascade');
            $table->integer('user_id')->nullable(); // Nullable for Task Pool (Bolsa de Trabajo)
            $table->string('status')->default('pending'); // 'pending', 'in_progress', 'paused', 'completed', 'spilled'
            $table->integer('started_at_mins')->nullable();
            $table->integer('expected_end_time_mins')->nullable();
            $table->integer('completed_at_mins')->nullable();
            $table->integer('assigned_from_routine_id')->nullable();
            $table->json('assistant_data')->nullable(); // For photo evidence, text inputs, etc.
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_assignments');
    }
};
