<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenancy de `system_settings`: `key` era PRIMARY KEY GLOBAL, así que solo
 * podía existir UNA fila por key en toda la BD → el segundo tenant en guardar
 * `time_mode` / `timezone` / `storeSchedule` / `active_modules` / `clockOpConfig`
 * chocaba con el PK y reventaba. El código de escritura ya matchea por (key, tenant_id);
 * esta migración alinea el esquema: quita el PK sobre `key` y agrega UNIQUE (tenant_id, key).
 *
 * Se ramifica por motor porque `dropPrimary()` no está soportado en SQLite (los tests
 * corren migrate:fresh sobre SQLite in-memory): en Postgres se hace in-place con ALTER;
 * en SQLite se reconstruye la tabla (mismo idioma DB::getDriverName() ya usado en
 * 2026_06_24_182200_change_id_in_task_assignments_table.php).
 *
 * Además crea un índice único PARCIAL sobre (key) WHERE tenant_id IS NULL para preservar
 * la unicidad de la config de PLATAFORMA (filas con tenant_id NULL): el UNIQUE compuesto
 * no la garantiza porque en Postgres/SQLite los NULL cuentan como distintos, y antes el
 * PK global sí impedía filas de plataforma duplicadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // In-place: preserva datos. El PK auto-nombrado por Postgres es <tabla>_pkey.
            DB::statement('ALTER TABLE system_settings DROP CONSTRAINT system_settings_pkey');
            Schema::table('system_settings', function (Blueprint $table) {
                $table->unique(['tenant_id', 'key'], 'system_settings_tenant_id_key_unique');
            });
        } else {
            // SQLite: reconstrucción (no se puede dropear un PRIMARY KEY con ALTER).
            // No hay duplicados que colapsar: `key` era único global, así que todo par
            // (tenant_id, key) ya es único.
            Schema::create('system_settings_new', function (Blueprint $table) {
                $table->foreignId('tenant_id')->nullable()->constrained('tenants')->onDelete('cascade');
                $table->string('key');
                $table->text('value');
                $table->timestamps();
                // Nombre EXPLÍCITO del índice: sin esto, al crearse sobre `system_settings_new`
                // SQLite lo nombraría `system_settings_new_..._unique` y ese nombre sobrevive
                // al rename → divergiría del nombre en Postgres y rompería un futuro dropUnique.
                $table->unique(['tenant_id', 'key'], 'system_settings_tenant_id_key_unique');
            });
            DB::statement('INSERT INTO system_settings_new (tenant_id, key, value, created_at, updated_at) SELECT tenant_id, key, value, created_at, updated_at FROM system_settings');
            Schema::drop('system_settings');
            Schema::rename('system_settings_new', 'system_settings');
        }

        // Índice único PARCIAL para la config de plataforma (tenant_id IS NULL). Sintaxis
        // soportada tanto por Postgres como por SQLite. Preserva la garantía que daba el
        // viejo PK global: una sola fila de plataforma por key (evita duplicados por
        // doble-submit/carrera en PlatformAdminController::saveBankConfig, etc.).
        DB::statement('CREATE UNIQUE INDEX system_settings_platform_key_unique ON system_settings (key) WHERE tenant_id IS NULL');
    }

    public function down(): void
    {
        // Índice parcial de plataforma primero (IF EXISTS: no-op si no está).
        DB::statement('DROP INDEX IF EXISTS system_settings_platform_key_unique');

        // Reversibilidad: restaura `key` como PRIMARY KEY global. Solo funciona si aún
        // no hay keys duplicadas entre tenants (si las hay, el revert es intencionalmente
        // imposible: la unicidad global ya no se puede garantizar tras uso multi-tenant).
        if (DB::getDriverName() === 'pgsql') {
            Schema::table('system_settings', function (Blueprint $table) {
                $table->dropUnique('system_settings_tenant_id_key_unique');
            });
            DB::statement('ALTER TABLE system_settings ADD PRIMARY KEY (key)');
        } else {
            Schema::create('system_settings_old', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->text('value');
                $table->timestamps();
                $table->foreignId('tenant_id')->nullable()->constrained('tenants')->onDelete('cascade');
            });
            DB::statement('INSERT INTO system_settings_old (key, value, created_at, updated_at, tenant_id) SELECT key, value, created_at, updated_at, tenant_id FROM system_settings');
            Schema::drop('system_settings');
            Schema::rename('system_settings_old', 'system_settings');
        }
    }
};
