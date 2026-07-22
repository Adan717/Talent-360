<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('task_assignments')) {
            Schema::table('task_assignments', function (Blueprint $table) {
                if (!Schema::hasColumn('task_assignments', 'task_cost')) {
                    $table->decimal('task_cost', 10, 2)->nullable()->after('accumulated_mins');
                }
                if (!Schema::hasColumn('task_assignments', 'score_percentage')) {
                    $table->integer('score_percentage')->default(100)->after('task_cost');
                }
                if (!Schema::hasColumn('task_assignments', 'coins_awarded')) {
                    $table->decimal('coins_awarded', 10, 2)->default(0.00)->after('score_percentage');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('task_assignments')) {
            Schema::table('task_assignments', function (Blueprint $table) {
                if (Schema::hasColumn('task_assignments', 'task_cost')) {
                    $table->dropColumn('task_cost');
                }
                if (Schema::hasColumn('task_assignments', 'score_percentage')) {
                    $table->dropColumn('score_percentage');
                }
                if (Schema::hasColumn('task_assignments', 'coins_awarded')) {
                    $table->dropColumn('coins_awarded');
                }
            });
        }
    }
};
