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
        Schema::create('induction_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_role_id')->constrained('job_roles')->onDelete('cascade');
            $table->string('youtube_url');
            $table->json('quiz_json'); // Las 5 preguntas con opciones y respuesta correcta
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('induction_courses');
    }
};
