# Runbook — cerrar la bitácora inmutable (paso 3 del RFC)

> **Estado: preparado en código, NO ejecutado en ningún servidor.**
> Ejecutarlo cambia la credencial con la que la aplicación entra a la base de datos en producción.
> Necesita una ventana de mantenimiento de ~10 minutos y el visto bueno del dueño.

## Por qué esto no era "una hora"

El RFC pedía revocarle a la aplicación el permiso de modificar `time_entries_historial`. Al ir a
hacerlo apareció que **no se puede**: la aplicación entra a Postgres como `postgres`, que es
**superusuario y dueño de todas las tablas**, y a un superusuario no se le revoca nada — se salta
la comprobación de permisos entera. Un `REVOKE` sobre él es una línea que se ejecuta sin error y
no cambia absolutamente nada.

Así que el arreglo no es SQL: es **con qué credencial se conecta la aplicación**. Eso arrastra tres
cosas más, y las tres están resueltas en este cambio:

| Lo que arrastra | Cómo queda resuelto |
|---|---|
| Si la aplicación no es dueña, `php artisan migrate` deja de funcionar | Conexión aparte `pgsql_migraciones` (`Backend/config/database.php`), que usa `DB_MIGRACIONES_USERNAME`. `deploy_v2.sh` migra por ahí. |
| Una tabla nueva nace sin permisos para el rol de la aplicación | `bitacora:candado` es idempotente y `deploy_v2.sh` lo corre después de cada `migrate`. |
| **El trigger escribe con los permisos de quien fichó** | La función pasa a `SECURITY DEFINER` (migración `2026_09_05_090000`). **Sin esto, el candado apaga el reloj checador**: al quitarle a la aplicación el INSERT sobre el historial, el INSERT del trigger se le niega igual y cada fichaje falla. |

Ese último renglón es el que hace peligroso improvisar este cambio, y por eso la primera prueba de
`CandadoDeLaBitacoraTest` no es sobre permisos: comprueba que la función es `SECURITY DEFINER`.

## Qué queda cerrado, exactamente

| Tabla | La aplicación podrá | Se le retira | Por qué |
|---|---|---|---|
| `time_entries_historial` | `SELECT` | INSERT, UPDATE, DELETE, TRUNCATE | Sólo lectura. El único camino hacia esa tabla es el trigger. |
| `asistencia_correcciones` | `SELECT`, `INSERT` | UPDATE, DELETE, TRUNCATE | Una póliza contable no se borra: se cancela con otra, y las dos se conservan. |
| `time_entries` | `SELECT`, `INSERT`, `UPDATE`, `DELETE` | TRUNCATE | El trigger es `FOR EACH ROW`: un `TRUNCATE` vaciaría la asistencia de **todas** las empresas sin dejar una sola fila en el historial. Era el agujero por el que se colaba justo el escenario que la bitácora existe para impedir. |

El resto del esquema no cambia: la aplicación conserva lectura y escritura sobre todo lo demás.

## Antes de empezar

1. **Respaldo del día verificado.** `ls -la /root/respaldos/auto | tail -3` y que el `.dump` de hoy
   exista y no sea `.tmp`. El runbook de restauración es `docs/RESPALDO_Y_RESTAURACION.md`.
2. **Hora de poco movimiento.** Nadie fichando: no hay turnos abiertos a punto de cerrar.
3. **Una contraseña nueva y larga para el rol de la aplicación.** La genera y la guarda el dueño;
   *este runbook no la contiene y ningún comando la inventa*.

## Los pasos

Todo se ejecuta en el servidor `46.225.153.115`. La instancia V2 vive en `/var/www/talent360-v2`.

### 1. Desplegar el código (todavía no cambia nada)

```bash
/usr/local/bin/deploy-v2
```

La migración pone la función en `SECURITY DEFINER` y `bitacora:candado` corre en modo diagnóstico
(sin `DB_APP_ROLE` no aplica nada). Debe imprimir el estado actual y decir, en su propia tabla, que
la aplicación entra como superusuario y que hoy el historial es escribible. **La aplicación sigue
funcionando exactamente igual.** Compruébelo entrando y fichando una vez.

### 2. Crear el rol de la aplicación

```bash
docker exec -i talent360_v2_postgres psql -U postgres -d talent360_v2_saas
```

```sql
CREATE ROLE talent360_app LOGIN PASSWORD '…la contraseña nueva…';
```

Nada más. Sin `SUPERUSER`, sin `CREATEDB`, sin `CREATEROLE`: si el rol fuera superusuario el
candado sería decorativo, y el comando se niega a aplicarlo en ese caso.

### 3. Conceder y retirar permisos, en simulacro primero

```bash
docker exec talent360-v2-backend php artisan bitacora:candado --rol=talent360_app
```

Enseña, tabla por tabla, qué se va a retirar, y la lista completa de órdenes SQL. Léala. Si está
conforme:

```bash
docker exec talent360-v2-backend php artisan bitacora:candado --rol=talent360_app --aplicar
```

Termina volviendo a preguntarle a Postgres si el candado quedó puesto: no lo da por hecho.

### 4. Cambiar la credencial de la aplicación

En `/var/www/talent360-v2/Backend/.env` **y** en `docker-compose.v2.yml` (los servicios `backend` y
`reverb` llevan sus `DB_*` como variables de entorno, y **el entorno del contenedor le gana al
`.env`** — ésa fue la causa del incidente del 2026-08-13):

```
DB_USERNAME=talent360_app
DB_PASSWORD=…la contraseña nueva…
DB_MIGRACIONES_USERNAME=postgres
DB_MIGRACIONES_PASSWORD=…la de postgres…
DB_APP_ROLE=talent360_app
```

`DB_APP_ROLE` es lo que hace que los despliegues siguientes vuelvan a aplicar el candado solos
sobre las tablas nuevas.

```bash
cd /var/www/talent360-v2
docker compose -f docker-compose.v2.yml up -d backend reverb
docker exec talent360-v2-backend php artisan optimize:clear
docker restart talent360-v2-backend-web   # nginx resuelve la IP nueva del backend
```

Ese último reinicio no es opcional: al recrear el contenedor cambia su IP dentro de la red de
Docker, nginx la tenía resuelta de antes y **toda la API responde 502** — la web carga y no se
puede ni iniciar sesión. Es la lección del 2026-08-05, anotada también en `deploy_v2.sh`.

### 5. Comprobar que quedó bien, y que el reloj sigue vivo

```bash
docker exec talent360-v2-backend php artisan bitacora:candado
```

Debe decir que la aplicación se conecta como `talent360_app`, que **no** es superusuario, y que la
función es `SECURITY DEFINER`.

Y la comprobación que de verdad importa, en este orden:

1. **Fichar de verdad** desde la aplicación con una cuenta real. Si el fichaje falla, el candado se
   puso sin el `SECURITY DEFINER`: vuelva atrás con el paso 6.
2. Que ese fichaje **dejó su fila en el historial**:
   ```sql
   SELECT id, operacion, origen, registrado_en FROM time_entries_historial ORDER BY id DESC LIMIT 3;
   ```
3. Que la aplicación **ya no puede tocarlo**:
   ```sql
   SET ROLE talent360_app;
   UPDATE time_entries_historial SET origen = 'x' WHERE id = (SELECT max(id) FROM time_entries_historial);
   -- debe responder: ERROR: permission denied for table time_entries_historial
   RESET ROLE;
   ```
4. `docker exec talent360-v2-backend php artisan reloj:preflight` sin fallos.

### 6. Cómo volver atrás (2 minutos)

Devolver `DB_USERNAME=postgres` y `DB_PASSWORD` a lo que estaban en el `.env` y en
`docker-compose.v2.yml`, quitar `DB_APP_ROLE`, y repetir el `up -d` + `optimize:clear` + el
reinicio de nginx del paso 4. El rol nuevo puede quedarse ahí sin usar; no estorba. Los permisos
que se le retiraron son suyos, no de `postgres`: la aplicación vuelve a poder todo.

## Lo que este cambio NO resuelve

- **La purga a cinco años** (renglón aparte) necesitará borrar filas del historial, y con el
  candado puesto la aplicación ya no podrá. Cuando se construya, el camino es una función
  `SECURITY DEFINER` propia que sólo borre lo vencido, no devolverle el permiso a la aplicación.
- **Quien entre al servidor como `postgres` sigue pudiendo todo.** El candado protege de la
  aplicación —que es la superficie expuesta a internet—, no de quien tiene la llave del servidor.
  Eso último se cubre con el control de acceso al servidor, no con permisos de base de datos.
- **La instancia de producción del dueño** (`/var/www/talent360`, base `talent360_saas`) no se toca
  en este runbook. Si quiere cerrarla igual, son los mismos pasos con sus nombres.

## Estado de las pruebas

`Backend/tests/Feature/CandadoDeLaBitacoraTest.php` cubre las tres cerraduras, el `SECURITY
DEFINER`, que el fichaje sigue escribiendo en el historial con el candado puesto, y que el comando
se niega a inventar credenciales. **Son pruebas de Postgres**: en la suite de sqlite se saltan
declarándolo. Se corren con `phpunit.postgres.xml` y **sólo** con la invocación segura que
documenta su cabecera (`docker exec -e DB_*` de pruebas) — el incidente del 2026-08-13 fue
exactamente por saltarse eso.
