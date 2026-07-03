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
        Schema::table('weekly_payrolls', function (Blueprint $table) {
            $table->integer('meal_overtime_mins')->default(0);
            $table->integer('break_overtime_mins')->default(0);
            $table->integer('task_performance_pct')->default(100);
            $table->integer('performance_score')->default(100);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('weekly_payrolls', function (Blueprint $table) {
            $table->dropColumn(['meal_overtime_mins', 'break_overtime_mins', 'task_performance_pct', 'performance_score']);
        });
    }
};
