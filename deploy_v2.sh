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

echo "▸ Trayendo cambios…"
git fetch origin --prune

echo "▸ Situando el árbol en ${OBJETIVO}…"
git reset --hard "$OBJETIVO"

NUEVA="$(git rev-parse --short HEAD)"
if [ "$ANTERIOR" = "$NUEVA" ]; then
    echo "▸ Ya estaba en ${NUEVA}: no hay nada que desplegar."
    exit 0
fi

echo "▸ ${ANTERIOR} → ${NUEVA}"
git --no-pager log --oneline "${ANTERIOR}..${NUEVA}" | sed 's/^/    /'

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
if git --no-pager diff --name-only "${ANTERIOR}..${NUEVA}" | grep -q '^Frontend/'; then
    echo "▸ El frontend cambió: reconstruyendo…"
    $COMPOSE up -d --build frontend >/dev/null
else
    echo "▸ El frontend no cambió: se omite la reconstrucción."
fi

# ── Comprobación ──────────────────────────────────────────────────────────────────────────────
echo "▸ Comprobando que responde…"
sleep 6
CODIGO="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 http://localhost:3002/ || echo 000)"

if [ "$CODIGO" = "200" ]; then
    echo ""
    echo "✔ Desplegado ${NUEVA} — la aplicación responde."
else
    echo ""
    echo "✘ La aplicación devolvió HTTP ${CODIGO} tras desplegar ${NUEVA}."
    echo "  Para volver a la versión anterior:"
    echo "      ./deploy_v2.sh ${ANTERIOR}"
    exit 1
fi
