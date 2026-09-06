<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LA ÚNICA PUERTA POR LA QUE SALE EL HISTORIAL DE ASISTENCIA (2026-09-05). Sólo Postgres.
 *
 * EL PROBLEMA: borrar filas de `time_entries` NO PURGA NADA. Hay un trigger
 * (`trg_historial_time_entries`) que en cada DELETE copia la fila ENTERA a
 * `time_entries_historial` — nombre, puesto, salario del día, foto, coordenadas. Una purga que
 * sólo borrara `time_entries` movería el dato personal de una tabla a otra y dejaría al dueño
 * creyendo que cumplió. El historial hay que borrarlo también, y hay que borrarlo DESPUÉS, cuando
 * el trigger ya escribió sus últimas filas.
 *
 * PERO el paso 3 del RFC (rama aparte, ya en camino) le quita a la aplicación el permiso de
 * INSERT/UPDATE/DELETE sobre `time_entries_historial` — que es justo el punto de una bitácora
 * inmutable: que ni el programador ni un `psql` descuidado puedan reescribir la evidencia. El
 * trigger sigue escribiendo porque su función pasa a `SECURITY DEFINER`.
 *
 * Cuando eso se aplique, `DB::table('time_entries_historial')->delete()` desde la aplicación
 * dejará de funcionar. **La salida NO es devolverle el permiso a la aplicación**: eso desharía la
 * inmutabilidad entera para poder ejercer un caso al año. La salida es ésta: una función
 * `SECURITY DEFINER` propia, estrecha, que corre con los privilegios de su dueño y que **no acepta
 * que le digan qué borrar**. Se le dice de QUIÉN, y ella misma comprueba contra la base que:
 *
 *   1. esa persona existe en esa empresa y está DADA DE BAJA con fecha,
 *   2. su baja tiene al menos cinco años —el piso del art. 804 LFT, que la función impone aunque
 *      el que llame pida menos: `LEAST(p_corte, hoy − 5 años)`—,
 *   3. NO tiene reserva legal (`legal_hold_at`), y
 *   4. cada fila que borra corresponde a un fichaje QUE YA NO EXISTE.
 *
 * Si algo de eso no se cumple, lanza excepción y aborta la transacción. Así, aunque cualquiera
 * pueda invocarla, lo único que consigue es borrar historial que de todos modos ya caducó y cuyo
 * fichaje ya no está. No hay forma de pedirle "bórrame la evidencia de este juicio".
 *
 * OJO AL DESPLEGAR: `SECURITY DEFINER` corre con los privilegios de QUIEN CREÓ la función. Si el
 * paso 3 del RFC deja el historial en manos de un rol distinto del que corre las migraciones, esta
 * función hay que volver a crearla con ESE rol (basta re-ejecutar este SQL conectado como él); si
 * no, seguirá sin poder borrar. El comando `datos:purgar-vencidos` lo detecta y lo grita en vez de
 * dar por purgado lo que no se purgó.
 *
 * `SET search_path` fijo: sin él, quien invoca la función puede anteponer un esquema propio con
 * una tabla `employees` falsa y hacer que una función con privilegios de dueño mire datos ajenos.
 * Es la trampa clásica de SECURITY DEFINER.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION purgar_historial_de_persona(
                p_tenant_id bigint,
                p_user_id   bigint,
                p_corte     date
            )
            RETURNS integer
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            DECLARE
                v_corte    date;
                v_ok       boolean;
                v_borradas integer;
            BEGIN
                -- El piso del art. 804 LFT lo pone la base, no quien llama: se puede pedir un
                -- corte MÁS viejo (más conservador), nunca uno más reciente.
                v_corte := LEAST(p_corte, (current_date - interval '5 years')::date);

                SELECT EXISTS (
                    SELECT 1
                      FROM employees e
                     WHERE e.tenant_id = p_tenant_id
                       AND e.user_id   = p_user_id
                       AND e.is_active_employee = false
                       AND e.termination_date IS NOT NULL
                       AND e.termination_date <= v_corte
                       AND e.legal_hold_at IS NULL
                ) INTO v_ok;

                IF NOT v_ok THEN
                    RAISE EXCEPTION
                        'purgar_historial_de_persona: el usuario % de la empresa % no cumple el plazo de retencion o tiene reserva legal; no se borra nada.',
                        p_user_id, p_tenant_id;
                END IF;

                -- Sólo historial de ESA empresa, de ESA persona, y sólo el de fichajes que la
                -- aplicación ya borró. Mientras el fichaje exista, su historial se queda: la
                -- bitácora nunca se adelanta al dato que describe.
                DELETE FROM time_entries_historial h
                 WHERE h.tenant_id = p_tenant_id
                   AND COALESCE(
                           NULLIF(h.fila_antes->>'user_id', ''),
                           NULLIF(h.fila_despues->>'user_id', '')
                       )::bigint = p_user_id
                   AND NOT EXISTS (
                           SELECT 1 FROM time_entries t WHERE t.id = h.time_entry_id
                       );

                GET DIAGNOSTICS v_borradas = ROW_COUNT;

                RETURN v_borradas;
            END;
            $$;
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP FUNCTION IF EXISTS purgar_historial_de_persona(bigint, bigint, date);');
    }
};
