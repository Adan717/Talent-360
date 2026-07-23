<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §38: vincula una tarea con una lección de la Academia (academy_courses) para
     * que el colaborador vea el video antes de ejecutarla. Reutiliza video_url, que
     * ya existe en academy_courses — no se crea nada nuevo de video.
     */
    public function up(): void
    {
        if (Schema::hasTable('tasks') && !Schema::hasColumn('tasks', 'academy_lesson_id')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->foreignId('academy_lesson_id')->nullable()->constrained('academy_courses')->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tasks') && Schema::hasColumn('tasks', 'academy_lesson_id')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->dropForeign(['academy_lesson_id']);
                $table->dropColumn('academy_lesson_id');
            });
        }
    }
};
