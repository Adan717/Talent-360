#!/bin/bash
set -euo pipefail

RAIZ="/var/www/talent360-v2"
COMPOSE=(docker compose -f "${RAIZ}/docker-compose.v2.yml")
CONFIG="${RAIZ}/Backend/.env"
ROL_APP="talent360_app"
RESPALDO_DIR="/root/talent360-config-backups/$(date -u +%Y%m%d_%H%M%S)-rol-app"
TRANSICION_INICIADA=0
POSTGRES_ROTADO=0

if [ ! -f "$CONFIG" ]; then
    echo "No existe ${CONFIG}." >&2
    exit 1
fi

mkdir -p "$RESPALDO_DIR"
chmod 700 "$RESPALDO_DIR"
cp -p "$CONFIG" "${RESPALDO_DIR}/Backend.env.antes"
chmod 600 "${RESPALDO_DIR}/Backend.env.antes"

# Hexadecimal evita caracteres con significado especial para .env, Bash o SQL. Las claves no se
# escriben en stdout y sólo quedan en el .env protegido del servidor.
CLAVE_APP="$(openssl rand -hex 48)"
CLAVE_MIGRACIONES="$(openssl rand -hex 48)"
CLAVE_POSTGRES_ACTUAL="$(docker exec talent360-v2-backend sh -lc 'printf %s "$DB_PASSWORD"')"

if [ -z "$CLAVE_POSTGRES_ACTUAL" ]; then
    echo "El contenedor actual no expone DB_PASSWORD; no se puede preparar una reversión segura." >&2
    exit 1
fi

actualizar_env() {
    local usuario_app="$1"
    local clave_app="$2"
    local clave_migraciones="$3"

    USUARIO_APP="$usuario_app" CLAVE_APP_ENV="$clave_app" CLAVE_MIGRACIONES_ENV="$clave_migraciones" \
        python3 - "$CONFIG" <<'PY'
from pathlib import Path
import os
import sys

archivo = Path(sys.argv[1])
actualizaciones = {
    "DB_CONNECTION": "pgsql",
    "DB_HOST": "db",
    "DB_PORT": "5432",
    "DB_DATABASE": "talent360_v2_saas",
    "DB_USERNAME": os.environ["USUARIO_APP"],
    "DB_PASSWORD": os.environ["CLAVE_APP_ENV"],
    "DB_MIGRACIONES_USERNAME": "postgres",
    "DB_MIGRACIONES_PASSWORD": os.environ["CLAVE_MIGRACIONES_ENV"],
    "DB_APP_ROLE": "talent360_app",
    "POSTGRES_DB": "talent360_v2_saas",
    "POSTGRES_USER": "postgres",
    "POSTGRES_PASSWORD": os.environ["CLAVE_MIGRACIONES_ENV"],
}

lineas = archivo.read_text(encoding="utf-8").splitlines()
vistas = set()
salida = []
for linea in lineas:
    clave = linea.split("=", 1)[0] if "=" in linea and not linea.lstrip().startswith("#") else None
    if clave in actualizaciones:
        if clave not in vistas:
            salida.append(f"{clave}={actualizaciones[clave]}")
            vistas.add(clave)
    else:
        salida.append(linea)

for clave, valor in actualizaciones.items():
    if clave not in vistas:
        salida.append(f"{clave}={valor}")

temporal = archivo.with_suffix(".env.nuevo")
temporal.write_text("\n".join(salida) + "\n", encoding="utf-8")
temporal.chmod(0o600)
temporal.replace(archivo)
PY
}

esperar_postgres() {
    local intento
    for intento in $(seq 1 30); do
        if docker exec talent360_v2_postgres pg_isready -U postgres -d talent360_v2_saas >/dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done
    echo "PostgreSQL no quedó listo después de 30 segundos." >&2
    return 1
}

recrear_servicios() {
    "${COMPOSE[@]}" up -d --force-recreate db >/dev/null
    esperar_postgres
    "${COMPOSE[@]}" up -d --force-recreate backend reverb >/dev/null
    docker restart talent360-v2-backend-web >/dev/null
}

revertir_si_falla() {
    local codigo=$?
    if [ "$codigo" -eq 0 ] || [ "$TRANSICION_INICIADA" -eq 0 ]; then
        return
    fi

    trap - ERR
    set +e
    echo "Falló la transición; restaurando acceso operativo con el superusuario y la clave rotada…" >&2
    cp -p "${RESPALDO_DIR}/Backend.env.antes" "$CONFIG"
    local clave_admin="$CLAVE_POSTGRES_ACTUAL"
    if [ "$POSTGRES_ROTADO" -eq 1 ]; then
        clave_admin="$CLAVE_MIGRACIONES"
    fi
    actualizar_env postgres "$clave_admin" "$clave_admin"
    recrear_servicios
    docker exec talent360-v2-backend php artisan optimize:clear >/dev/null
    echo "Reversión operativa terminada. Respaldo: ${RESPALDO_DIR}" >&2
    exit "$codigo"
}
trap revertir_si_falla ERR

echo "▸ Creando el rol limitado…"
if docker exec talent360_v2_postgres psql -U postgres -d talent360_v2_saas -Atc \
    "SELECT 1 FROM pg_roles WHERE rolname = '${ROL_APP}'" | grep -qx 1; then
    docker exec talent360_v2_postgres psql -U postgres -d talent360_v2_saas -v ON_ERROR_STOP=1 -c \
        "ALTER ROLE ${ROL_APP} LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD '${CLAVE_APP}'" >/dev/null
else
    docker exec talent360_v2_postgres psql -U postgres -d talent360_v2_saas -v ON_ERROR_STOP=1 -c \
        "CREATE ROLE ${ROL_APP} LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD '${CLAVE_APP}'" >/dev/null
fi
# Se conceden los permisos antes de cambiar el usuario. En este punto el backend todavía entra
# como postgres, por lo que incluso una versión anterior del comando puede ejecutar el cambio.
docker exec talent360-v2-backend php artisan bitacora:candado --rol="$ROL_APP" --aplicar >/dev/null

TRANSICION_INICIADA=1
actualizar_env "$ROL_APP" "$CLAVE_APP" "$CLAVE_POSTGRES_ACTUAL"
recrear_servicios
docker exec talent360-v2-backend php artisan optimize:clear >/dev/null

echo "▸ Comprobando el rol web antes de rotar la credencial de migraciones…"
docker exec talent360-v2-backend php artisan bitacora:candado --rol="$ROL_APP" --aplicar >/dev/null

echo "▸ Rotando la credencial de migraciones…"
docker exec talent360_v2_postgres psql -U postgres -d talent360_v2_saas -v ON_ERROR_STOP=1 -c \
    "ALTER ROLE postgres PASSWORD '${CLAVE_MIGRACIONES}'" >/dev/null
POSTGRES_ROTADO=1
actualizar_env "$ROL_APP" "$CLAVE_APP" "$CLAVE_MIGRACIONES"
"${COMPOSE[@]}" up -d --force-recreate db >/dev/null
esperar_postgres
docker exec talent360-v2-backend php artisan optimize:clear >/dev/null

echo "▸ Comprobando credencial web, credencial de migraciones y permisos…"
docker exec talent360-v2-backend php artisan migrate:status --database=pgsql_migraciones >/dev/null
docker exec talent360-v2-backend php artisan bitacora:candado --rol="$ROL_APP" --aplicar

# Prueba contra el servidor de hoy: la aplicación puede escribir asistencia y el trigger puede
# registrar historial, pero la misma conexión no puede modificar ese historial directamente.
docker exec -i talent360-v2-backend php <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$conexion = Illuminate\Support\Facades\DB::connection();
$usuario = (string) $conexion->selectOne('SELECT current_user AS u')->u;
$super = (bool) $conexion->selectOne(
    'SELECT rolsuper FROM pg_roles WHERE rolname = current_user'
)->rolsuper;
$migraciones = (string) Illuminate\Support\Facades\DB::connection('pgsql_migraciones')
    ->selectOne('SELECT current_user AS u')->u;

$antes = (int) $conexion->table('time_entries_historial')->count();
$entrada = $conexion->table('time_entries')->orderBy('id')->first();
if (!$entrada) {
    fwrite(STDERR, "No hay un fichaje con el que comprobar el trigger.\n");
    exit(1);
}

$conexion->beginTransaction();
$conexion->table('time_entries')->where('id', $entrada->id)->update(['updated_at' => now()]);
$trigger = (int) $conexion->table('time_entries_historial')->count() > $antes;
$conexion->rollBack();

$historialDenegado = false;
try {
    $conexion->statement('UPDATE time_entries_historial SET operacion = operacion WHERE false');
} catch (Throwable $e) {
    $historialDenegado = str_contains(strtolower($e->getMessage()), 'permission denied');
}

$resultado = [
    'usuario_app' => $usuario,
    'app_superusuario' => $super,
    'usuario_migraciones' => $migraciones,
    'trigger_escribe_historial' => $trigger,
    'app_no_modifica_historial' => $historialDenegado,
];
echo json_encode($resultado, JSON_UNESCAPED_SLASHES) . PHP_EOL;

if ($usuario !== 'talent360_app' || $super || $migraciones !== 'postgres' || !$trigger || !$historialDenegado) {
    exit(1);
}
PHP

CODIGO_WEB="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 http://localhost:3002/)"
CODIGO_API="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 -X POST -H 'Accept: application/json' http://localhost:3002/api/v1/login)"
if [ "$CODIGO_WEB" != "200" ] || { [ "$CODIGO_API" = "000" ] || [ "$CODIGO_API" = "502" ]; }; then
    echo "La aplicación no respondió correctamente: web=${CODIGO_WEB}, api=${CODIGO_API}." >&2
    false
fi

TRANSICION_INICIADA=0
trap - ERR
echo "✔ Base endurecida. Web ${CODIGO_WEB}, API ${CODIGO_API}. Respaldo: ${RESPALDO_DIR}"
