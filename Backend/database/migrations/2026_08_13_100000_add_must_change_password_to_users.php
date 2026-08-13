<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque 1 del plan (2026-08-13): cambio forzado de contraseña al siguiente login.
 * El consejo tumbó la decisión D4 ("las contraseñas viejas se quedan"): forzar el cambio
 * no deja fuera a nadie, porque la persona ya conoce su contraseña actual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false);
        });

        // Revisión adversarial: el platform admin nacía con `123456` LITERAL en
        // scripts_utilidad/create_admin.php — dejarlo fuera de la rotación era absurdo.
        Schema::table('platform_users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
        Schema::table('platform_users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
