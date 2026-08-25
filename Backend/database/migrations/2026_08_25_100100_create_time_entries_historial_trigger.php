<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BITÁCORA INMUTABLE — capa 1: el trigger que la llena (2026-08-25).
 *
 * Lo escribe la BASE DE DATOS, no la aplicación, y ésa es toda la idea. Una bitácora que dependa
 * de que el programador se acuerde de registrar el cambio no sirve como prueba: basta un `UPDATE`
 * suelto, una migración descuidada o un `psql` en el servidor para que el rastro no exista y nadie
 * se entere. El trigger ve todo, venga de donde venga.
 *
 * QUIÉN Y POR QUÉ: el trigger sabe *qué* cambió, no *por qué*. La aplicación deja su intención en
 * variables de sesión de la transacción (`App\Support\BitacoraDeAsistencia`) y aquí se recogen con
 * `current_setting(..., true)` — el `true` devuelve NULL en vez de reventar cuando no están
 * puestas, que es el caso de todo proceso automático. **Que queden nulas también es información**:
 * significa que ese cambio no pasó por una corrección declarada.
 *
 * SÓLO POSTGRES. La suite de pruebas corre sobre sqlite en memoria, que no tiene plpgsql; ahí esta
 * migración no hace nada y las pruebas del trigger se saltan solas declarándolo. La verificación
 * de verdad se hace contra el Postgres real (`phpunit.postgres.xml`), que es donde vive el dato.
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
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared('DROP TRIGGER IF EXISTS trg_historial_time_entries ON time_entries;');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_historial_time_entries
            AFTER INSERT OR UPDATE OR DELETE ON time_entries
            FOR EACH ROW EXECUTE FUNCTION registrar_historial_time_entries();
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS trg_historial_time_entries ON time_entries;');
        DB::unprepared('DROP FUNCTION IF EXISTS registrar_historial_time_entries();');
    }
};
