<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BITÁCORA INMUTABLE — el paso que hace posible el candado (2026-09-05).
 *
 * El RFC (docs/RFC_BITACORA_INMUTABLE.md, paso 3) pide quitarle a la aplicación el permiso de
 * modificar `time_entries_historial`. Hasta hoy no se podía: la aplicación entra a Postgres como
 * `postgres`, que es **superusuario y dueño de la tabla**, y a un superusuario no se le revoca
 * nada — se salta la comprobación de permisos entera.
 *
 * Al resolver eso (un rol de aplicación sin superusuario, ver `bitacora:candado`) aparece una
 * trampa que tumba el reloj entero si no se ve a tiempo:
 *
 *   **El trigger escribe CON LOS PERMISOS DE QUIEN DISPARA LA OPERACIÓN.** Una función plpgsql es
 *   `SECURITY INVOKER` por defecto. Si al rol de la aplicación se le quita `INSERT` sobre el
 *   historial, el `INSERT` que hace el trigger al fichar también se le niega — y como el trigger
 *   es `AFTER ... FOR EACH ROW` dentro de la misma transacción, **el fichaje falla**. Es decir: el
 *   candado, puesto sin esto, deja a la plantilla sin poder checar.
 *
 * Por eso la función pasa a `SECURITY DEFINER`: se ejecuta con los permisos de su DUEÑO (el rol
 * que corre esta migración, dueño de las tablas), no con los de quien fichó. Resultado:
 *
 *   · la aplicación NO puede escribir en el historial ni por error, ni por migración, ni por
 *     inyección — no tiene el permiso;
 *   · el trigger SÍ puede, porque no usa los permisos de la aplicación;
 *   · el único camino que existe hacia el historial es el trigger. Eso es lo que convierte la
 *     palabra *inmutable* en un hecho comprobable y no en una intención.
 *
 * `SET search_path = public, pg_temp` no es decoración: sin fijarlo, una función `SECURITY
 * DEFINER` es la vía clásica de escalada de privilegios (quien puede crear objetos en un esquema
 * anterior del search_path secuestra los nombres que la función resuelve). Se fija aquí y se
 * queda fijado.
 *
 * El cuerpo de la función es el MISMO de 2026_08_25_100100 — no se toca ni una línea de su
 * lógica. Lo único que cambia son los dos atributos del final. Se rehace entera con `CREATE OR
 * REPLACE` porque Postgres no permite cambiarlos de otra forma sin recrearla.
 *
 * SÓLO POSTGRES: en sqlite (la suite en memoria) no hay plpgsql y esta migración no hace nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION registrar_historial_time_entries()
            RETURNS trigger AS $$
            DECLARE
                v_actor      bigint;
                v_correccion bigint;
                v_origen     text;
            BEGIN
                -- El segundo argumento `true` evita el error cuando la variable no existe:
                -- un barrido automático no declara actor, y eso es un dato, no un fallo.
                v_actor      := NULLIF(current_setting('app.actor_id', true), '')::bigint;
                v_correccion := NULLIF(current_setting('app.correccion_id', true), '')::bigint;
                v_origen     := NULLIF(current_setting('app.origen', true), '');

                IF (TG_OP = 'DELETE') THEN
                    INSERT INTO time_entries_historial
                        (time_entry_id, tenant_id, operacion, fila_antes, fila_despues,
                         actor_id, correccion_id, origen, registrado_en)
                    VALUES
                        (OLD.id, OLD.tenant_id, TG_OP, to_jsonb(OLD), NULL,
                         v_actor, v_correccion, v_origen, now());
                    RETURN OLD;
                ELSIF (TG_OP = 'UPDATE') THEN
                    INSERT INTO time_entries_historial
                        (time_entry_id, tenant_id, operacion, fila_antes, fila_despues,
                         actor_id, correccion_id, origen, registrado_en)
                    VALUES
                        (NEW.id, NEW.tenant_id, TG_OP, to_jsonb(OLD), to_jsonb(NEW),
                         v_actor, v_correccion, v_origen, now());
                    RETURN NEW;
                ELSE
                    INSERT INTO time_entries_historial
                        (time_entry_id, tenant_id, operacion, fila_antes, fila_despues,
                         actor_id, correccion_id, origen, registrado_en)
                    VALUES
                        (NEW.id, NEW.tenant_id, TG_OP, NULL, to_jsonb(NEW),
                         v_actor, v_correccion, v_origen, now());
                    RETURN NEW;
                END IF;
            END;
            $$ LANGUAGE plpgsql
               SECURITY DEFINER
               SET search_path = public, pg_temp;
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Volver a SECURITY INVOKER. Ojo: si el candado (`bitacora:candado`) ya está puesto,
        // deshacer esto deja a la aplicación sin poder fichar. Se revierte primero el candado.
        DB::unprepared('ALTER FUNCTION registrar_historial_time_entries() SECURITY INVOKER;');
    }
};
