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
        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null'); // Si es reingreso
            $table->foreignId('applied_vacancy_id')->constrained('vacancies')->onDelete('cascade');
            $table->string('email');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('rfc')->nullable();
            $table->string('nss')->nullable();
            $table->string('birth_certificate_url')->nullable();
            $table->string('id_card_url')->nullable();
            $table->string('status')->default('prospect'); // prospect, induction, interview, training, evaluation, hired, rejected
            $table->integer('induction_score')->nullable();
            $table->json('supervisor_evaluation_json')->nullable();
            $table->text('hr_notes')->nullable();
            $table->boolean('is_ex_employee_fast_track')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('candidates');
    }
};
