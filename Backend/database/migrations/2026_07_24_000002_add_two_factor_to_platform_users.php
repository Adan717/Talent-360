<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §48 (preparación): las cuentas de plataforma (super-admin/soporte) son las que
     * Francisco pidió reforzar con 2FA, pero hoy solo `users` tiene los campos 2FA,
     * no `platform_users`. Se agregan aquí para que el flujo TOTP quede listo para
     * conectar en cuanto se instale `pragmarx/google2fa` (el único paso que falta;
     * ver nota en §48 del contrato). Las columnas no se usan todavía — son inertes
     * hasta que exista el endpoint de verificación.
     */
    public function up(): void
    {
        if (Schema::hasTable('platform_users')) {
            Schema::table('platform_users', function (Blueprint $table) {
                if (!Schema::hasColumn('platform_users', 'two_factor_enabled')) {
                    $table->boolean('two_factor_enabled')->default(false);
                }
                if (!Schema::hasColumn('platform_users', 'two_factor_secret')) {
                    $table->text('two_factor_secret')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('platform_users')) {
            Schema::table('platform_users', function (Blueprint $table) {
                foreach (['two_factor_enabled', 'two_factor_secret'] as $col) {
                    if (Schema::hasColumn('platform_users', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
