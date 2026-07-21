<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->integer('pre_shift_alarm_minutes')->nullable()
                ->comment('Minutos antes de shiftStart para notificación push local (15,30,45,60)');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pre_shift_alarm_minutes');
        });
    }
};
