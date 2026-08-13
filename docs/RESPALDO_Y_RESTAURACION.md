# Respaldo y restauración — bloque 0 del plan, PROBADO el 2026-08-13

No es un diseño: es lo que ya corre y lo que ya se restauró una vez de verdad. Si algo de aquí
no coincide con el servidor, manda el servidor y hay que corregir este archivo.

## Qué corre y dónde

- **Script**: `/usr/local/bin/respaldo-talent360` en el servidor (46.225.153.115). Copia de
  referencia en el repo: `scripts/respaldo_talent360.sh`. Si se edita el repo, hay que volver a
  subirla (`scp` + `sed -i 's/\r$//'` + `chmod 700`).
- **Cron**: diario a las **02:45 UTC** (crontab de root), log en `/var/log/talent360-respaldo.log`.
- **Destino**: `/root/respaldos/auto/`, retención **14 días**, permisos 600 (los tars llevan `.env`
  con `APP_KEY` dentro: son secretos).
- **Qué guarda, por instancia** (V2 y producción del jefe):
  - `*_db_FECHA.dump` — Postgres completo (`pg_dump -Fc`, validado con `pg_restore --list` antes
    de darse por bueno; un dump que muere a medias se queda en `.tmp` y no engaña a nadie).
  - `*_files_FECHA.tar.gz` — lo que `pg_dump` NO toca: `storage/app` (expedientes, evidencia de
    comedor), `.env` (sin `APP_KEY` lo cifrado es irrecuperable) y `public/uploads` si existe
    (fotos de fichaje §67, evidencia vieja de comedor de prod).
  - El **código no va en el respaldo**: sale de git (`Adan717/Talent-360` y
    `pcmaster-prog/Talent-360-V2`).

## Copia fuera del servidor (INTERINA)

Tarea programada de Windows **"Talent360 respaldo pull"** en la máquina de Adán, diaria a las
09:00, corre `C:\Users\adanc\Respaldos-Talent360\pull.cmd` (scp de `/root/respaldos/auto` →
`C:\Users\adanc\Respaldos-Talent360\auto`). Limitación conocida: si la máquina está apagada a esa
hora, ese día no jala — por eso es interina. **El destino definitivo en la nube lo debe el dueño
(§B1 de `DECISIONES_PRODUCTO.md`).**

## Cómo restaurar (los pasos exactos que se probaron)

Sobre el servidor, sin tocar los contenedores vivos (todo con nombres `t360rt-*`):

```bash
# 1. Copia de trabajo: código de git (o del árbol), y los ARCHIVOS DEL RESPALDO encima
mkdir -p /root/restore-test && cd /root/restore-test
cp -a /var/www/talent360-v2/Backend BackendRestore
rm -rf BackendRestore/storage/app BackendRestore/.env BackendRestore/bootstrap/cache/config.php
tar -xzf /root/respaldos/auto/v2_files_FECHA.tar.gz -C BackendRestore

# 2. ⚠️ El .env del árbol vivo trae DB_DATABASE=talent360_v2_db (OBSOLETO): la config real la
#    inyecta docker-compose. En el restore hay que corregirlo a mano:
sed -i 's/^DB_DATABASE=.*/DB_DATABASE=talent360_v2_saas/' BackendRestore/.env

# 3. Postgres limpio en red aislada, con alias "db" para que el .env funcione tal cual
docker network create t360rt
docker run -d --name t360rt-db --network t360rt --network-alias db \
  -e POSTGRES_PASSWORD=Master -e POSTGRES_DB=talent360_v2_saas postgres:16-alpine
docker exec -i t360rt-db pg_restore -U postgres -d talent360_v2_saas --no-owner \
  < /root/respaldos/auto/v2_db_FECHA.dump

# 4. La app restaurada, SOLO en localhost (lleva datos reales: nunca exponerla a internet)
docker run -d --name t360rt-app --network t360rt -v /root/restore-test/BackendRestore:/var/www \
  -w /var/www -p 127.0.0.1:8090:8090 talent360-v2-backend \
  php artisan serve --host=0.0.0.0 --port=8090

# 5. Entrar y abrir un archivo (la prueba de verdad; credenciales del tenant QA)
#    POST /api/v1/login → token → GET /api/v1/admin/documentos/descargar/{id}?scope=empleado

# 6. Desmontar TODO al terminar
docker rm -f t360rt-app t360rt-db && docker network rm t360rt && rm -rf /root/restore-test
```

Para producción del jefe es igual con `prod_db_FECHA.dump`, BD `talent360_saas` y
`/var/www/talent360/Backend`.

## La prueba del 2026-08-13 (lo que se validó de verdad)

| Qué | Resultado |
|---|---|
| Login en la app restaurada (V2, tenant QA) | `Login exitoso`, token emitido |
| Descarga autenticada del expediente (`ine_prueba.pdf`) | HTTP 200, `%PDF-1.4`, 209 bytes exactos |
| La misma descarga sin token | 401 |
| Restore de producción del jefe (conteos restaurada vs viva) | users 13/13, companies 14/14, time_entries 10/10 |

**Nota honesta**: el criterio del plan decía "abrir una foto de fichaje". Hoy **no existe ninguna
foto de fichaje en ninguna instancia** — el flujo §67 no tiene endpoint de subida en el servidor,
así que ningún fichaje ha producido un archivo. Se probó con el único archivo privado real que
existe (el PDF del expediente), que ejercita exactamente la misma cadena: fila en BD → endpoint
autenticado → archivo físico salido del tar. El día que existan fotos de fichaje, viven en
`public/uploads/clock-photos/` y **ya van dentro del tar**.

## Pendiente

- **Destino en la nube** (decisión del dueño, §B1). Mientras: servidor (14 días) + máquina de Adán.
- Avisar a las tres empresas que el respaldo automático existe desde el 2026-08-13 (antes no había
  ninguno) y que el correo saliente sigue apagado.
