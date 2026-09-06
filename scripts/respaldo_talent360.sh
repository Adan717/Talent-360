#!/bin/sh
# Respaldo diario Talent360 — bloque 0 del plan de trabajo (docs/PLAN_DE_TRABAJO.md).
#
# Qué guarda, por instancia (V2 y producción del jefe):
#   1. Postgres completo (pg_dump -Fc, validado con pg_restore --list antes de darlo por bueno).
#   2. Archivos que pg_dump NO toca y sin los cuales el restore devuelve recibos que apuntan
#      a la nada: storage/app (expedientes, evidencia de comedor), el .env (APP_KEY: sin ella
#      lo cifrado es irrecuperable) y public/uploads si existe (fotos de fichaje §67 y la
#      evidencia vieja de comedor de producción).
#
# Y deja una MARCA en Backend/storage/app/respaldo/ultimo.json (ver el final de respaldar()):
# es lo único que la aplicación puede ver del respaldo, y de ella depende que /api/health
# conteste 200 o 503. Si este script deja de correr, el vigilante externo lo grita en 26 h.
#
# Retención: 14 días en /root/respaldos/auto. La copia FUERA del servidor la jala la máquina
# de Adán (tarea programada de Windows) mientras el dueño decide el destino en la nube (§B1).
# Cómo restaurar y cómo se probó: docs/RESPALDO_Y_RESTAURACION.md.
#
# La copia autoritativa corre en el servidor: /usr/local/bin/respaldo-talent360
# (cron diario 02:45). Esta copia del repo es para que el conocimiento no viva en una
# sola cabeza; si se edita, hay que volver a subirla.
set -eu

DEST=/root/respaldos/auto
STAMP=$(date +%Y%m%d_%H%M%S)
mkdir -p "$DEST"
chmod 700 "$DEST"

respaldar() { # nombre  contenedor_pg  base_de_datos  dir_backend_en_host
  nombre=$1; pg=$2; bd=$3; dir=$4

  # Al .tmp primero: un pg_dump que muera a medias no deja un "respaldo" truncado con nombre bueno.
  docker exec "$pg" pg_dump -U postgres -Fc "$bd" > "$DEST/${nombre}_db_${STAMP}.dump.tmp"
  docker exec -i "$pg" pg_restore --list < "$DEST/${nombre}_db_${STAMP}.dump.tmp" > /dev/null
  mv "$DEST/${nombre}_db_${STAMP}.dump.tmp" "$DEST/${nombre}_db_${STAMP}.dump"

  extras=""
  [ -d "$dir/public/uploads" ] && extras="public/uploads"
  tar -czf "$DEST/${nombre}_files_${STAMP}.tar.gz" -C "$dir" storage/app .env $extras

  chmod 600 "$DEST/${nombre}_db_${STAMP}.dump" "$DEST/${nombre}_files_${STAMP}.tar.gz"

  # ── Marca para que la APLICACIÓN pueda decir si hubo respaldo ────────────────────────────
  # Los dumps viven en el host y el contenedor sólo monta ./Backend: desde dentro no hay forma
  # de verlos. storage/app es el único terreno común, así que aquí se deja el recibo que lee
  # App\Support\EstadoDelRespaldo y que /api/health convierte en 200 o en 503.
  #
  # Se escribe AQUÍ, al final, y no antes: sólo después de que el dump pasó el pg_restore --list
  # y de que el tar terminó. Una marca escrita al empezar diría "respaldo OK" de un respaldo que
  # murió a mitad — la mentira exacta que este archivo existe para impedir.
  #
  # 644 el archivo y 755 el directorio porque lo escribe root en el host y lo lee www-data
  # dentro del contenedor: con los permisos por defecto de root, el health check leería
  # 'ilegible' y daría 503 con el respaldo perfectamente hecho.
  bytes=$(wc -c < "$DEST/${nombre}_db_${STAMP}.dump" | tr -d ' ')
  marca="$dir/storage/app/respaldo"
  mkdir -p "$marca"
  chmod 755 "$marca"
  printf '{"instancia":"%s","terminado_utc":"%s","dump_bytes":%s}\n' \
    "$nombre" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$bytes" > "$marca/ultimo.json.tmp"
  mv "$marca/ultimo.json.tmp" "$marca/ultimo.json"   # .tmp + mv: nunca un JSON a medio escribir
  chmod 644 "$marca/ultimo.json"
}

respaldar v2   talent360_v2_postgres talent360_v2_saas /var/www/talent360-v2/Backend
respaldar prod talent360_postgres    talent360_saas    /var/www/talent360/Backend

# Retención: se conservan 14 días
find "$DEST" -type f -mtime +13 -delete

echo "$(date -Iseconds) respaldo OK ($STAMP): $(ls "$DEST" | grep -c "$STAMP") archivos"
