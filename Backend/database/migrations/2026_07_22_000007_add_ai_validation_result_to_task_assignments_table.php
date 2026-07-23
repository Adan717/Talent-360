<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('task_assignments') && !Schema::hasColumn('task_assignments', 'ai_validation_result')) {
            Schema::table('task_assignments', function (Blueprint $table) {
                $table->json('ai_validation_result')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('task_assignments') && Schema::hasColumn('task_assignments', 'ai_validation_result')) {
            Schema::table('task_assignments', function (Blueprint $table) {
                $table->dropColumn('ai_validation_result');
            });
        }
    }
};
