<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * H12 (prueba en vivo 2026-07-29): el alta no sembraba `company_name`, así que quedaba NULL y
 * el frontend caía a un default hardcodeado — una empresa recién registrada saludaba con
 * "Bienvenido a DecorArte 360", el nombre de OTRA empresa, en su primera pantalla.
 *
 * `TenantInitializationService` ya lo siembra para las nuevas; esta migración repara a las que
 * YA existen. Sólo escribe donde falta la clave: nunca pisa el nombre comercial que una empresa
 * haya personalizado desde Configuración.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tenants') || !Schema::hasTable('system_settings')) {
            return;
        }

        $conNombre = DB::table('system_settings')
            ->where('key', 'company_name')
            ->pluck('tenant_id')
            ->filter()
            ->all();

        $pendientes = DB::table('tenants')
            ->whereNotIn('id', $conNombre ?: [0])
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->get(['id', 'name']);

        foreach ($pendientes as $tenant) {
            DB::table('system_settings')->insert([
                'tenant_id' => $tenant->id,
                'key' => 'company_name',
                'value' => json_encode($tenant->name),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($pendientes->count() > 0) {
            \Illuminate\Support\Facades\Log::info(
                "backfill_company_name: {$pendientes->count()} empresa(s) no tenían su nombre en " .
                "system_settings y veían el de otra empresa en la bienvenida."
            );
        }
    }

    public function down(): void
    {
        // Irreversible a propósito: no se puede distinguir un `company_name` sembrado por esta
        // migración de uno capturado por el cliente, y borrarlo devolvería el bug.
    }
};
