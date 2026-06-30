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
        Schema::create('performance_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluator_user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('evaluated_user_id')->constrained('users')->onDelete('cascade');
            $table->integer('teamwork_score')->default(5); // 1 to 5
            $table->integer('performance_score')->default(5); // 1 to 5
            $table->integer('attitude_score')->default(5); // 1 to 5
            $table->text('comments')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_evaluations');
    }
};
