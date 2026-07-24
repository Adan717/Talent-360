<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hora del día (HH:MM) a la que dispara una rutina con trigger='scheduled'
     * ("Horario Fijo / Programado"). Nullable: las rutinas on_checkin/apertura no la usan.
     */
    public function up(): void
    {
        Schema::table('routines', function (Blueprint $table) {
            $table->string('trigger_time')->nullable()->after('trigger');
        });
    }

    public function down(): void
    {
        Schema::table('routines', function (Blueprint $table) {
            $table->dropColumn('trigger_time');
        });
    }
};
