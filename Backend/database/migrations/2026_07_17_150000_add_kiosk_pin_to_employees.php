<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ronda 54 (Reloj): PIN de kiosko en `employees`.
 *
 * DISTINTO del `pin_code` de onboarding (que es de un solo uso, plaintext, y se consume a null al
 * activar). Éste es persistente y de uso diario en la tableta compartida.
 *
 * Dos columnas para un PIN, a propósito:
 *  - `kiosk_pin_hash` (bcrypt): la verificación. El admin nunca lo ve, sólo lo resetea.
 *  - `kiosk_pin_lookup` (HMAC-SHA256 del PIN con el APP_KEY como pepper): un BLIND INDEX. Un hash
 *    bcrypt no se puede consultar ("¿quién tiene el PIN X?") porque el salt lo hace no determinista;
 *    el HMAC sí, así que da lookup O(1) y permite el unique. Un leak SÓLO de BD no lo revierte
 *    (falta la clave). Ver App\Models\Employee.
 *
 * Unique `(tenant_id, kiosk_pin_lookup)`: dentro de un tenant un PIN identifica a UN empleado. En
 * tenants distintos el mismo PIN convive (mismo lookup, distinto tenant_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('kiosk_pin_hash')->nullable();
            $table->string('kiosk_pin_lookup')->nullable();
            $table->unique(['tenant_id', 'kiosk_pin_lookup'], 'employees_tenant_kiosk_pin_unique');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique('employees_tenant_kiosk_pin_unique');
            $table->dropColumn(['kiosk_pin_hash', 'kiosk_pin_lookup']);
        });
    }
};
