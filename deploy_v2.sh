#!/bin/bash
#
# Despliegue de la instancia V2 desde git.
#
# Se ejecuta EN EL SERVIDOR (46.225.153.115), en /var/www/talent360-v2.
# Requiere la deploy key `~/.ssh/deploy_talent360` registrada como *Deploy key* de solo lectura
# en Adan717/Talent-360.
#
# POR QUÉ EXISTE: hasta 2026-08-03 el despliegue se hacía copiando archivos por `scp`, porque el
# token de GitHub del repositorio anterior estaba caducado (y en texto plano en `.git/config`).
# Eso dejaba el árbol del servidor fuera de git: sin forma de saber qué versión corría y sin
# vuelta atrás si algo salía mal. Con el repo apuntando por SSH, un despliegue es un `push` más
# este script, y revertir es `git reset --hard HEAD~1` y volver a ejecutarlo.
#
#   Uso:  deploy-v2            → despliega origin/main
#         deploy-v2 <commit>   → despliega un commit concreto (para revertir)
#
# EJECUTAR SIEMPRE LA COPIA DE `/usr/local/bin/deploy-v2`, NO LA DEL REPOSITORIO.
#
# Motivo, encontrado probando la reversión: este script vive dentro del árbol que él mismo
# resetea. Al revertir a un commit anterior a su propia existencia, `git reset --hard` lo borra a
# mitad de ejecución y la vuelta atrás queda a medias — justo en el momento en que más falta hace.
# Por eso se instala fuera del árbol:
#
#     install -m 755 deploy_v2.sh /usr/local/bin/deploy-v2
#
# Al cambiar este archivo hay que volver a ejecutar esa línea para actualizar la copia instalada.
#
set -euo pipefail

RAIZ="/var/www/talent360-v2"
COMPOSE="docker compose -f ${RAIZ}/docker-compose.v2.yml"
OBJETIVO="${1:-origin/main}"

cd "$RAIZ"

# ── Guarda: nunca sobrescribir en silencio algo que alguien tocó a mano en el servidor ────────
# Es la lección de esta ronda de auditoría aplicada a la infraestructura: los cambios que no
# fallan pero pisan trabajo ajeno son los que más caro salen.
if [ -n "$(git status --porcelain)" ]; then
    echo "⚠  El árbol del servidor tiene cambios sin commitear:"
    git status --short
    echo ""
    echo "   Alguien editó archivos aquí directamente. Revísalos antes de desplegar."
    echo "   Si son descartables:  git reset --hard  &&  vuelve a ejecutar."
    exit 1
fi

ANTERIOR="$(git rev-parse --short HEAD)"
echo "▸ Versión actual: ${ANTERIOR}"

# ── Qué versión se desplegó BIEN la última vez ────────────────────────────────────────────────
# No basta con mirar en qué commit está el árbol. El árbol se mueve al principio, antes de
# construir; si algo revienta después (un `composer install` que se topa con un 502 de GitHub,
# una migración que falla, la comprobación final), el árbol se queda en la versión nueva pero los
# contenedores siguen corriendo la vieja. La siguiente ejecución veía "ya estás en ese commit" y
# se declaraba innecesaria: el despliegue quedaba a medias diciendo que no había nada que hacer.
# (Pasó el 2026-08-16 con el 504 de GitHub bajando symfony/mime.)
#
# La marca se escribe SÓLO cuando el despliegue termina bien, así que es la única fuente honesta.
#
# Vive FUERA del árbol, por lo mismo que este script: dentro, `git reset --hard` la borraría en
# cada despliegue y además la guarda de "cambios sin commitear" de arriba la vería como un
# archivo que alguien dejó a mano y se negaría a desplegar (probado: se negó).
MARCA="/var/lib/talent360-v2-ultimo-despliegue-ok"
ULTIMO_OK="$(cat "$MARCA" 2>/dev/null || true)"

echo "▸ Trayendo cambios…"
git fetch origin --prune

echo "▸ Situando el árbol en ${OBJETIVO}…"
git reset --hard "$OBJETIVO"

NUEVA="$(git rev-parse --short HEAD)"
if [ "$ANTERIOR" = "$NUEVA" ] && [ "$ULTIMO_OK" = "$NUEVA" ]; then
    echo "▸ Ya estaba en ${NUEVA} y su despliegue terminó bien: no hay nada que hacer."
    exit 0
fi

# Desde dónde se compara para el registro de cambios y para decidir si toca reconstruir el
# frontend: desde el último despliegue BUENO, no desde donde estaba el árbol. Si el intento
# anterior murió a mitad, el árbol ya estaba en la versión nueva y esta comparación salía vacía
# —así que el frontend no se reconstruía justo en el reintento que venía a arreglarlo—.
BASE="${ULTIMO_OK:-$ANTERIOR}"
if [ "$BASE" != "$NUEVA" ]; then
    echo "▸ ${BASE} → ${NUEVA}"
    git --no-pager log --oneline "${BASE}..${NUEVA}" | sed 's/^/    /'
else
    echo "▸ Reintentando ${NUEVA}: el despliegue anterior no llegó a terminar."
fi

# ── Backend ───────────────────────────────────────────────────────────────────────────────────
# `migrate --force` es obligatorio: sin él Laravel pide confirmación interactiva y el script se
# queda colgado esperando una respuesta que nadie va a teclear.
echo "▸ Migraciones…"
docker exec talent360-v2-backend php artisan migrate --force

echo "▸ Limpiando cachés…"
docker exec talent360-v2-backend php artisan optimize:clear

echo "▸ Reiniciando backend…"
$COMPOSE restart backend >/dev/null

# ── Frontend ──────────────────────────────────────────────────────────────────────────────────
# Sólo se reconstruye si el commit tocó el frontend: la reconstrucción tarda minutos y la mayoría
# de los despliegues son de backend.
# En un reintento (BASE == NUEVA) la comparación sale vacía y hay que reconstruir de todas formas:
# no se sabe hasta dónde llegó el intento que falló.
if [ "$BASE" = "$NUEVA" ] || git --no-pager diff --name-only "${BASE}..${NUEVA}" | grep -q '^Frontend/'; then
    echo "▸ El frontend cambió: reconstruyendo…"
    $COMPOSE up -d --build frontend >/dev/null

    # `up --build` puede RECREAR también el backend, y al recrearse cambia de IP dentro de la red
    # de Docker. Nginx resuelve el upstream una sola vez, al arrancar, así que se queda hablándole
    # a la IP vieja y TODA la API responde 502: la web carga pero no se puede ni iniciar sesión
    # (pasó el 2026-08-05 desplegando los cursos del catálogo). Reiniciar nginx lo obliga a
    # resolver de nuevo; es barato y no interrumpe nada más.
    echo "▸ Reiniciando nginx para que resuelva la IP nueva del backend…"
    $COMPOSE restart backend-web >/dev/null 2>&1 || docker restart talent360-v2-backend-web >/dev/null

    # Cada reconstrucción del frontend deja ~1-2 GB de caché de compilación que NADIE borra: el
    # 2026-08-13 el disco iba en 84% con 22 GB de puro caché (Postgres vive en el mismo disco;
    # si se llena, deja de escribir la base). Se conserva el caché de 24 h (los rebuilds del día
    # siguen siendo rápidos) y se suelta el resto. Solo borra caché: nunca imágenes ni datos.
    echo "▸ Limpiando caché de compilación viejo…"
    docker builder prune -af --filter 'until=24h' >/dev/null 2>&1 || true
else
    echo "▸ El frontend no cambió: se omite la reconstrucción."
fi

# ── Comprobación ──────────────────────────────────────────────────────────────────────────────
# Se comprueban las DOS mitades. Antes sólo se pedía `/`, que nginx sirve desde el disco: daba 200
# y el despliegue se declaraba bueno aunque la API entera estuviera caída detrás.
echo "▸ Comprobando que responde…"
sleep 6
CODIGO="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 http://localhost:3002/ || echo 000)"
# Sin credenciales esta ruta contesta 401/422: cualquiera de las dos demuestra que PHP está vivo.
# Un 502 (o un 000) significa que nginx no está alcanzando al backend.
CODIGO_API="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 -X POST \
    -H 'Accept: application/json' http://localhost:3002/api/v1/login || echo 000)"

if [ "$CODIGO" = "200" ] && [ "$CODIGO_API" != "502" ] && [ "$CODIGO_API" != "000" ]; then
    # ÚNICO sitio donde se escribe la marca: llegar hasta aquí es lo que significa "desplegado".
    echo "$NUEVA" > "$MARCA"
    echo ""
    echo "✔ Desplegado ${NUEVA} — la aplicación responde (web ${CODIGO}, API ${CODIGO_API})."
else
    echo ""
    echo "✘ Tras desplegar ${NUEVA}: la web devolvió HTTP ${CODIGO} y la API HTTP ${CODIGO_API}."
    if [ "$CODIGO_API" = "502" ] || [ "$CODIGO_API" = "000" ]; then
        echo "  Un 502 en la API suele ser nginx apuntando a la IP vieja del backend:"
        echo "      docker restart talent360-v2-backend-web"
    fi
    echo "  El árbol se queda en ${NUEVA} pero NO se marca como desplegado: al volver a ejecutar,"
    echo "  el script reintenta esta versión entera en vez de creer que ya está puesta."
    echo "  Para volver a la versión anterior:"
    echo "      /usr/local/bin/deploy-v2 ${BASE}"
    exit 1
fi
