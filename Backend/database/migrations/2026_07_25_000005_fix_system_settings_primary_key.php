<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BUGFIX (bloqueante): `system_settings` tenía `key` como PRIMARY KEY *global*
     * (migración 2026_06_09), no `(tenant_id, key)`. Al agregar `tenant_id` después
     * (2026_06_19) nunca se corrigió el PK. Consecuencia: solo podía existir UNA fila
     * por `key` en toda la base, sin importar el tenant. Cuando una empresa NUEVA se
     * registraba, el hook `Tenant::created` → `initializeSettingsForTenant()` intentaba
     * insertar sus settings por defecto (storeSchedule, leySillaConfig, ...), pero esas
     * llaves ya existían para el tenant 1 → violación de PK → transacción abortada →
     * el error se tragaba y la siguiente instrucción reventaba con SQLSTATE[25P02].
     * Resultado: ninguna empresa nueva podía registrarse.
     *
     * Fix: quitar el PK sobre `key` sola y poner unicidad correcta por `(tenant_id, key)`,
     * más un índice parcial para que las llaves de plataforma (tenant_id NULL) sigan
     * siendo únicas (Postgres trata cada NULL como distinto en un índice único normal).
     * No hay datos que deduplicar: con el PK viejo cada `key` aparecía una sola vez.
     */
    public function up(): void
    {
        if (!Schema::hasTable('system_settings')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE system_settings DROP CONSTRAINT IF EXISTS system_settings_pkey');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS system_settings_tenant_key_unique ON system_settings (tenant_id, key)');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS system_settings_platform_key_unique ON system_settings (key) WHERE tenant_id IS NULL');
            return;
        }

        // SQLite (tests) / otros: no se puede quitar un PK en sitio — se reconstruye la
        // tabla con el esquema correcto y se copian los datos.
        Schema::rename('system_settings', 'system_settings_old');
        Schema::create('system_settings', function (Blueprint $table) {
            $table->string('key');
            $table->text('value');
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
        });
        DB::statement('INSERT INTO system_settings (key, value, tenant_id, created_at, updated_at)
                       SELECT key, value, tenant_id, created_at, updated_at FROM system_settings_old');
        Schema::drop('system_settings_old');
    }

    public function down(): void
    {
        if (!Schema::hasTable('system_settings')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS system_settings_platform_key_unique');
            DB::statement('DROP INDEX IF EXISTS system_settings_tenant_key_unique');
            // Reponer el PK viejo (solo si no hay llaves duplicadas).
            DB::statement('ALTER TABLE system_settings ADD PRIMARY KEY (key)');
            return;
        }

        Schema::rename('system_settings', 'system_settings_new');
        Schema::create('system_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value');
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->timestamps();
        });
        DB::statement('INSERT INTO system_settings (key, value, tenant_id, created_at, updated_at)
                       SELECT key, value, tenant_id, created_at, updated_at FROM system_settings_new');
        Schema::drop('system_settings_new');
    }
};
