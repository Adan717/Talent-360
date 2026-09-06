<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UNA SOLA FUENTE DE VERDAD PARA LOS PRECIOS (2026-09-05).
 *
 * Antes de esto el producto tenía CUATRO respuestas distintas a "¿cuánto cuesta el plan PRO?",
 * y ninguna sabía de las otras:
 *
 *   1. Lo que el backend COBRA (`SubscriptionController`, duplicado en dos bloques idénticos):
 *      $29/colaborador/mes, o $24/colaborador/mes facturado al año. Enterprise $69 / $55.
 *   2. La landing: las mismas tarifas, pero pintadas con un 20% inventado a mano, de modo que
 *      el MISMO plan tenía dos precios anuales según qué interruptor mirara el cliente
 *      (29×12×0.8 = $278.40 contra 24×12 = $288 por colaborador).
 *   3. La pantalla del cliente que YA PAGÓ: $12 y $499 planos — números que no existen en
 *      ningún cobro.
 *   4. El panel de plataforma (MRR e historial): $199 y $499 planos, también inventados.
 *
 * La tabla `billing_plans` ya existía, ya la relacionaban `Tenant::billingPlan` y
 * `TenantSubscriptionHistory`... y estaba VACÍA (0 filas en producción). Su esquema además era
 * de plan PLANO (una columna `price` + `billing_interval`), que NO representa el modelo real:
 * aquí se cobra POR COLABORADOR.
 *
 * Esta migración le da a la tabla el modelo real y la siembra con LO QUE EL BACKEND COBRABA
 * al 2026-09-05. Las filas nacen marcadas `is_provisional = true`: no son el tabulador oficial,
 * son la foto de lo que el código cobraba. El tabulador oficial lo declara el dueño y se cambia
 * en base de datos, sin recompilar nada.
 *
 * La columna vieja `price` se conserva (hay filas ajenas que la referencian por FK) pero deja de
 * gobernar: `App\Support\Tarifario` es el único lector de precios y no la mira.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_plans', function (Blueprint $table) {
            // El modelo real: tarifa POR COLABORADOR AL MES. La segunda columna es la misma
            // tarifa mensual cuando la factura se emite por el año completo — no es el total
            // anual. El total anual y el % de descuento se DERIVAN de estas dos (Tarifario),
            // nunca se escriben a mano: ahí nacía la contradicción de la landing.
            $table->decimal('price_per_user_monthly', 10, 2)->default(0)->after('price');
            $table->decimal('price_per_user_yearly', 10, 2)->default(0)->after('price_per_user_monthly');
            // NULL = sin tope. Hoy nada bloquea por este número (criterio del dueño: "nada
            // bloquea, todo avisa"); sirve para AVISAR al admin de la empresa y al panel.
            $table->integer('max_users')->nullable()->after('price_per_user_yearly');
            // Una fila provisional es la foto de lo que el código cobraba, no una decisión
            // comercial. Se marca para que ninguna pantalla la presente como oficial.
            $table->boolean('is_provisional')->default(true)->after('is_active');
        });

        // El cupo del freemium vivía en `system_settings.freemium_max_users` (fila global) y
        // NADIE lo escribía desde ninguna pantalla; si alguna instalación lo tiene puesto a
        // mano, se respeta trayéndolo aquí antes de sembrar, y la llave vieja se retira para
        // que no queden dos números que puedan divergir.
        $override = DB::table('system_settings')
            ->whereNull('tenant_id')
            ->where('key', 'freemium_max_users')
            ->value('value');
        $topeFreemium = ((int) $override) > 0 ? (int) $override : 10;

        $ahora = now();

        // LO QUE EL BACKEND COBRABA AL 2026-09-05. No se inventa ni un peso.
        $filas = [
            [
                'code' => 'freemium',
                'name' => 'Plan Gratuito',
                'price_per_user_monthly' => 0.00,
                'price_per_user_yearly' => 0.00,
                'max_users' => $topeFreemium,
            ],
            [
                'code' => 'pro',
                'name' => 'Plan Profesional',
                'price_per_user_monthly' => 29.00,
                'price_per_user_yearly' => 24.00,
                'max_users' => null,
            ],
            [
                'code' => 'enterprise',
                'name' => 'Plan Enterprise',
                'price_per_user_monthly' => 69.00,
                'price_per_user_yearly' => 55.00,
                'max_users' => null,
            ],
        ];

        foreach ($filas as $fila) {
            $comunes = [
                'name' => $fila['name'],
                'price_per_user_monthly' => $fila['price_per_user_monthly'],
                'price_per_user_yearly' => $fila['price_per_user_yearly'],
                'max_users' => $fila['max_users'],
                // MXN es lo que de verdad se cobra: la preferencia de MercadoPago viaja con
                // `currency_id = 'MXN'`. El default 'USD' de la tabla nunca fue cierto.
                'currency' => 'MXN',
                'is_active' => true,
                'is_provisional' => true,
                'updated_at' => $ahora,
            ];

            $existe = DB::table('billing_plans')->where('code', $fila['code'])->exists();
            if ($existe) {
                DB::table('billing_plans')->where('code', $fila['code'])->update($comunes);
            } else {
                DB::table('billing_plans')->insert($comunes + [
                    'code' => $fila['code'],
                    'price' => 0,
                    'billing_interval' => 'month',
                    'created_at' => $ahora,
                ]);
            }
        }

        DB::table('system_settings')
            ->whereNull('tenant_id')
            ->where('key', 'freemium_max_users')
            ->delete();
    }

    public function down(): void
    {
        // Se devuelve la llave vieja con el cupo que quedó, para no perder el número.
        $tope = DB::table('billing_plans')->where('code', 'freemium')->value('max_users');
        if ($tope) {
            DB::table('system_settings')->insert([
                'tenant_id' => null,
                'key' => 'freemium_max_users',
                'value' => (string) $tope,
            ]);
        }

        Schema::table('billing_plans', function (Blueprint $table) {
            $table->dropColumn([
                'price_per_user_monthly',
                'price_per_user_yearly',
                'max_users',
                'is_provisional',
            ]);
        });
    }
};
