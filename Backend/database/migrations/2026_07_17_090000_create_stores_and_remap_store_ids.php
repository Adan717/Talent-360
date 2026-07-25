<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ronda 52 (Reloj): entidad `stores` + remap de `store_id` + FK real.
 *
 * ANTES no existía tabla de sucursales: `store_id` era un integer suelto en las 4 tablas
 * `store_opening_*`, sin FK, apuntando a una tabla inexistente.
 *
 * LA TRAMPA: `store_id` valía **1 en TODOS los tenants**. No era un id global — era "la tienda 1 de
 * cada empresa". Al crear `stores` con ids globales (uno por tenant), dejar las filas tal cual haría
 * que las del tenant 3 apuntaran a `store_id = 1`, que ahora es la sucursal del **tenant 1** →
 * corrupción cross-tenant, y encima la FK la aceptaría porque el id existe. Por eso hay que
 * REMAPEAR por tenant ANTES de poner la FK.
 *
 * Alcance MÍNIMO a propósito (decidido 2026-07-16): sólo `tenant_id` + `name`. La dirección, lat/lng
 * y el horario viven hoy en `system_settings` y los consumen la geocerca (R33/R35) y la ventana de
 * apertura; moverlos es repuntar código de seguridad y va en ronda aparte. No se crean columnas sin
 * consumidor (lección de `has_keys`, R50).
 */
return new class extends Migration
{
    private const TABLAS_CON_STORE_ID = [
        'store_opening_settings',
        'store_opening_assignments',
        'store_daily_opening_statuses',
        'store_opening_events',
    ];

    public function up(): void
    {
        // 1. La entidad. `unique(tenant_id, name)` no es decorativo: es lo que hace race-safe al
        //    resolver (`TenantStore::defaultIdFor` siembra con insertOrIgnore), además de una regla
        //    de negocio razonable.
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->string('name');
            $table->timestamps();

            $table->unique(['tenant_id', 'name'], 'stores_tenant_name_unique');
        });

        // 2. Una sucursal por tenant EXISTENTE, y el mapa viejo→nuevo.
        //    Se recorren los tenants que realmente tienen filas de apertura, más todos los tenants
        //    activos: un tenant sin filas también necesita su sucursal para no depender de que el
        //    resolver la cree en caliente.
        $tenantIds = DB::table('tenants')->pluck('id');
        $mapa = []; // tenant_id => store_id nuevo

        foreach ($tenantIds as $tenantId) {
            $mapa[$tenantId] = DB::table('stores')->insertGetId([
                'tenant_id' => $tenantId,
                'name' => 'Sucursal Principal',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 3. EL REMAP. Todo lo que existe hoy pertenece a la única sucursal de su tenant.
        //    SÍ se asume una sola sucursal por tenant, y es correcto asumirlo: hasta esta migración
        //    no existía la entidad, así que no había forma de tener dos (verificado en vivo: todas
        //    las filas traían store_id=1). Si un tenant tuviera dos `store_id` distintos, este
        //    update los colapsaría y chocaría contra el unique (tenant_id, store_id) de R51 → la
        //    migración fallaría ruidosamente y sin dejar nada a medias (PG tiene DDL transaccional
        //    y Laravel envuelve la migración), que es el comportamiento correcto para ese caso.
        foreach (self::TABLAS_CON_STORE_ID as $tabla) {
            foreach ($mapa as $tenantId => $storeId) {
                DB::table($tabla)
                    ->where('tenant_id', $tenantId)
                    ->update(['store_id' => $storeId]);
            }
        }

        // 4. Filas de un tenant que ya no existe: no pueden quedar, o la FK fallaría al no tener
        //    sucursal a la que apuntar. No debería haberlas (`tenant_id` es FK con cascade y NOT
        //    NULL en las 4 tablas), así que esto es defensa en profundidad para bases legacy.
        //    El guard `!empty($mapa)` NO es decorativo: `whereNotIn('tenant_id', [])` compila a
        //    `1 = 1` en Laravel, así que sin él una base sin tenants borraría TODAS las filas.
        if (!empty($mapa)) {
            foreach (self::TABLAS_CON_STORE_ID as $tabla) {
                DB::table($tabla)->whereNotIn('tenant_id', array_keys($mapa))->delete();
            }
        }

        // 5. La FK real, ya con los datos remapeados.
        //    OJO: la FK sola NO garantiza que la sucursal sea del MISMO tenant que la fila (un
        //    store_id de otro tenant existe y la FK lo aceptaría). Eso lo garantiza el resolver
        //    server-side (`TenantStore::defaultIdFor`) + que el cliente ya no puede elegir sucursal.
        //    Un CHECK cross-tabla no se puede expresar en SQL estándar; blindarlo exigiría una FK
        //    compuesta (tenant_id, store_id), que se deja para cuando exista multi-sucursal real.
        //
        //    `cascade` es lo correcto HOY: no hay ruta ni controlador para borrar una sucursal, y el
        //    borrado de un tenant ya cascadea por `tenant_id` de todas formas. Cuando exista
        //    multi-sucursal habrá que revisitarlo: borrar una sucursal se llevaría por delante
        //    `store_opening_events`, que es rastro de auditoría — ahí `restrict` es más defendible.
        foreach (self::TABLAS_CON_STORE_ID as $tabla) {
            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                $table->foreign('store_id', $tabla . '_store_id_foreign')
                    ->references('id')->on('stores')
                    ->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLAS_CON_STORE_ID as $tabla) {
            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                $table->dropForeign($tabla . '_store_id_foreign');
            });
        }

        // Se devuelve el valor legacy: 1 = "la tienda 1 de cada tenant".
        // CON PÉRDIDA a propósito y sólo válido mientras haya UNA sucursal por tenant (que es el
        // estado que esta migración congela). Si alguien crea una segunda sucursal y luego hace
        // rollback, este colapso choca contra el unique (tenant_id, store_id) de R51 — y así debe
        // ser: revertir la entidad no puede inventar a qué sucursal pertenecía cada fila.
        foreach (self::TABLAS_CON_STORE_ID as $tabla) {
            DB::table($tabla)->update(['store_id' => 1]);
        }

        Schema::dropIfExists('stores');
    }
};
