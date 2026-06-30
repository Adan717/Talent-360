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
        Schema::create('routines', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->integer('target_role_id')->nullable();
            $table->string('trigger')->default('apertura'); // 'apertura', 'cierre', 'hora_fija'
            $table->string('assign_mode')->default('equitativo'); // 'equitativo', 'banco', 'fijo'
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('routines');
    }
};
