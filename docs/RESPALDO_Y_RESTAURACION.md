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
- **Instalación**: desde 2026-09-05 **el propio `deploy-v2` instala el script y su línea de cron
  si faltan**, de forma idempotente. Antes los había puesto alguien a mano: un servidor
  reinstalado —o una segunda instancia— nacía sin respaldo y nadie se enteraba.

## La marca `ultimo.json` — cómo sabe la aplicación que hubo respaldo

Los dumps viven en `/root/respaldos/auto` del **host** y el contenedor sólo monta `./Backend`:
desde dentro de la aplicación no hay forma de verlos ni de contarlos. Por eso, al terminar bien
—dump validado con `pg_restore --list` y tar hecho, no antes—, el script deja un recibo en el
único terreno común:

```
Backend/storage/app/respaldo/ultimo.json
{"instancia":"v2","terminado_utc":"2026-09-05T02:45:11Z","dump_bytes":48210944}
```

Se escribe con `.tmp` + `mv` (nunca un JSON a medio escribir), con permisos **644** y el
directorio **755**: lo escribe `root` en el host y lo lee `www-data` dentro del contenedor. Va
gitignorado (`Backend/storage/app/.gitignore`), así que no dispara la guarda de "cambios sin
commitear" de `deploy_v2.sh`.

Quien lo lee es `App\Support\EstadoDelRespaldo`, y de él dependen dos cosas:

- **`GET /api/health`** responde **200 sólo si la base responde Y la marca tiene menos de 26
  horas** (24 del ciclo diario + 2 de margen); en cualquier otro caso **503**. Es lo que mira el
  vigilante externo: **[VIGILANTE_DEL_SERVIDOR.md](VIGILANTE_DEL_SERVIDOR.md)** — hoja para el
  dueño con qué teclear en UptimeRobot o Better Stack, qué significa cada fallo y el ensayo de
  alarma.
- **`php artisan reloj:preflight`** lo repite con la misma clase: marca ausente o ilegible =
  aviso; más de 26 h = fallo.

Regla dura, y es a propósito: **una marca ausente o ilegible cuenta como fallo**. Un respaldo que
la aplicación no puede confirmar no existe.

Dos consecuencias que conviene tener presentes:

- **Al estrenar esto, primero se sube y se corre el script a mano y DESPUÉS se despliega.** Al
  revés, la dirección estrena en 503 por marca ausente y parece que el despliegue rompió algo.
  Los pasos exactos, en la §5 de la hoja del vigilante.
- **Después de una restauración**, la marca que sale del tar es la del día de aquel respaldo: el
  health check dirá `viejo` hasta que corra el siguiente. Es honesto —esa instancia restaurada
  no se ha respaldado a sí misma todavía— pero conviene saberlo para no perseguir un fantasma.

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

## El respaldo ya se usó EN SERIO: incidente del 2026-08-13 (mismo día)

Horas después de la prueba, la suite Postgres corrió por error contra la BD viva de la V2
(dentro del contenedor, el env real le ganó al `<env force>` del phpunit.postgres.xml — detalle
en el encabezado de ese archivo) y `RefreshDatabase` la vació: 0 usuarios, 0 fichajes. **Se
restauró con el respaldo de esa madrugada** (`v2_db_20260813_013313.dump`, `pg_restore --clean
--if-exists`), se re-aplicó la migración pendiente, y se verificó entrando a la app viva con la
cuenta admin del tenant QA: datos completos (7 usuarios, 5 expedientes, 23 fichajes, 3
empresas). Pérdida real: cero (entre el respaldo y el borrado sólo hubo cambios de flags,
re-aplicables). Dos cerrojos nuevos salieron del incidente: el guardarraíl de
`tests/TestCase.php` (la suite se niega si la base no es de pruebas) y la invocación segura
documentada en `phpunit.postgres.xml`.

## Pendiente

- **Destino en la nube** (decisión del dueño, §B1). Mientras: servidor (14 días) + máquina de Adán.
- Avisar a las tres empresas que el respaldo automático existe desde el 2026-08-13 (antes no había
  ninguno) y que el correo saliente sigue apagado.
