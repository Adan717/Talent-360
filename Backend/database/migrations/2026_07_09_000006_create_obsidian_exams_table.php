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
        // 1. Table obsidian_exams
        Schema::create('obsidian_exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('job_role_id')->constrained('job_roles')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('obsidian_users')->onDelete('cascade');
            $table->timestamps();
            $table->softDeletes();
        });

        // 2. Table obsidian_exam_questions
        Schema::create('obsidian_exam_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('obsidian_exams')->onDelete('cascade');
            $table->text('question_text');
            $table->json('options'); // [{key: 'A', text: '...'}, ...]
            $table->char('correct_option', 1); // 'A', 'B', 'C', 'D'
            $table->timestamps();
        });

        // 3. Table obsidian_exam_attempts
        Schema::create('obsidian_exam_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('obsidian_users')->onDelete('cascade');
            $table->foreignId('exam_id')->nullable()->constrained('obsidian_exams')->onDelete('set null');
            $table->string('job_role_title_at_time');
            $table->string('user_name_at_time');
            $table->integer('score');
            $table->integer('total_questions')->default(10);
            $table->boolean('passed')->default(false);
            $table->json('answers'); // [{question_id: 1, chosen: 'A', is_correct: true}]
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('obsidian_exam_attempts');
        Schema::dropIfExists('obsidian_exam_questions');
        Schema::dropIfExists('obsidian_exams');
    }
};
