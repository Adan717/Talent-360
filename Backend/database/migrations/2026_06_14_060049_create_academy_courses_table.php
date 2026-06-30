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
        Schema::create('academy_courses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('course_type', ['induction', 'training', 'promotion', 'recertification']);
            $table->foreignId('target_job_role_id')->nullable()->constrained('job_roles')->onDelete('cascade');
            $table->foreignId('prerequisite_course_id')->nullable()->constrained('academy_courses')->onDelete('set null');
            $table->integer('incentive_bonus_cents')->default(0);
            $table->string('video_url')->nullable();
            $table->json('quiz_data')->nullable(); // Array of questions and answers
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academy_courses');
    }
};
