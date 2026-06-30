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
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->integer('estimated_mins')->default(15);
            $table->string('priority')->default('normal'); // 'normal', 'bloqueante'
            $table->string('category')->default('operativo'); // 'operativo', 'administrativo'
            $table->string('target_type')->default('role'); // 'role', 'user', 'store'
            $table->integer('target_id')->nullable();
            $table->string('assistant_type')->default('ninguno'); // 'ninguno', 'evidencia_foto', 'captura_numero', 'texto'
            $table->text('assistant_prompt')->nullable();
            $table->boolean('is_auto_capture')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
