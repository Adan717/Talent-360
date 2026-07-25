<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salida Doble Llave (spec:53-55): la salida del empleado queda en `pending_approval`
 * hasta que un supervisor la autoriza. Se gatea por tenant con `require_checkout_approval`
 * (default false = comportamiento previo intacto). El estado vive en una columna aparte
 * `check_out_status` en el propio check_out (NO en el `type`, para no romper el monitor ni
 * el CRON de huérfanos, que discriminan por `type='check_out'`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            // null = legacy/final; 'pending_approval'; 'approved'.
            $table->string('check_out_status')->nullable();
        });

        Schema::table('lft_settings', function (Blueprint $table) {
            $table->boolean('require_checkout_approval')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropColumn('check_out_status');
        });

        Schema::table('lft_settings', function (Blueprint $table) {
            $table->dropColumn('require_checkout_approval');
        });
    }
};
