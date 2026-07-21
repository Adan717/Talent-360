<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Distinto de `pin_code` (código de invitación de un solo uso, se pone en NULL al
     * activar la cuenta — ver OnboardingController). `security_pin` es un PIN recurrente
     * que el propio empleado configura y que se usa para autorizar acciones sensibles
     * (ej. co-validación de testigos en apertura de emergencia). Se guarda hasheado con
     * Hash::make(), por eso necesita más de los 6 caracteres que tiene pin_code.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('security_pin')->nullable()->after('pin_code');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('security_pin');
        });
    }
};
