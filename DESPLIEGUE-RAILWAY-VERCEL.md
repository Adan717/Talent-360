# Despliegue de PRUEBAS: Railway (backend) + Vercel (frontend)

**Objetivo (2026-07-28):** entorno de pruebas accesible por internet, sin administrar servidor.
NO es el plan de producción final (eso será AWS o el Hetzner del jefe — para esos vale
`deploy_seguro.py` y `DESPLIEGUE-HETZNER.md`). Repo: `Adan717/Talent-360`.

## Arquitectura

| Dónde | Servicio | Qué corre |
|---|---|---|
| Railway | **Postgres** | plugin de base de datos de Railway |
| Railway | **backend** | Laravel HTTP (`Dockerfile.railway`, root `Backend/`) |
| Railway | **worker** | scheduler + cola (misma imagen, otro Start Command) |
| Railway | **reverb** | websockets (misma imagen, otro Start Command) |
| Vercel | **frontend** | build de Vite (root `Frontend/`, `vercel.json` ya incluido) |

---

## 1. Railway — Postgres

New Project → **Deploy PostgreSQL**. Del panel de la BD copia `DATABASE_URL` o las 5 piezas
(`PGHOST`, `PGPORT`, `PGDATABASE`, `PGUSER`, `PGPASSWORD`).

## 2. Railway — servicio `backend`

New Service → **GitHub Repo** → `Adan717/Talent-360`.

- Settings → **Root Directory: `Backend`** · Builder: **Dockerfile** → `Dockerfile.railway`
  (el CMD ya migra y sirve; no configures Start Command aquí).
- Settings → **Networking → Generate Domain** (te da `https://<algo>.up.railway.app`).
- Settings → **Volumes → New Volume** montado en **`/var/www/storage/app`**
  (sin esto, las fotos de comida/evidencias se BORRAN en cada deploy).
- Variables (además de las de BD):

```
APP_ENV=production            # enciende los 3 gates de seguridad del Reloj
APP_DEBUG=false
APP_KEY=                      # genera una local: php artisan key:generate --show
APP_URL=https://<backend>.up.railway.app
CACHE_STORE=file
QUEUE_CONNECTION=database
SESSION_SECURE_COOKIE=true
AUTH_COOKIE_SAMESITE=None     # ← CLAVE: FE y BE viven en dominios distintos; con Lax el
                              #   navegador no manda la cookie httpOnly y nada autentica
FRONTEND_URL=https://<tu-app>.vercel.app   # ← el CORS del backend lee esta variable
DB_CONNECTION=pgsql
DB_HOST=${PGHOST}  DB_PORT=${PGPORT}  DB_DATABASE=${PGDATABASE}
DB_USERNAME=${PGUSER}  DB_PASSWORD=${PGPASSWORD}
REVERB_APP_ID=talent360
REVERB_APP_KEY=<inventa-una-key-larga>
REVERB_APP_SECRET=<inventa-un-secret-largo>
REVERB_HOST=<dominio-del-servicio-reverb>.up.railway.app
REVERB_PORT=443
REVERB_SCHEME=https
```

Opcionales que degradan con gracia si faltan: `GEMINI_API_KEY`, credenciales Firebase, SMTP.

## 3. Railway — servicio `worker`

Mismo repo y Root `Backend/` + `Dockerfile.railway`, **mismas Variables** (cópialas), SIN
dominio público, y con Settings → **Start Command**:

```
sh -c "php artisan schedule:work & exec php artisan queue:work --tries=3 --sleep=3"
```

Esto sustituye a los crons del host de la receta Hetzner: sin este servicio NO corren ni
`tasks:flag-unfinished` ni la pre-nómina, ni se procesan los jobs de la cola.

## 4. Railway — servicio `reverb`

Igual que el worker (mismas variables) pero CON dominio público generado y Start Command:

```
sh -c "php artisan reverb:start --host=0.0.0.0 --port=${PORT}"
```

Su dominio es el que va en `REVERB_HOST` del backend y en `VITE_REVERB_HOST` de Vercel.

## 5. Vercel — frontend

Import Git Repository → `Adan717/Talent-360`.

- **Root Directory: `Frontend`** (Vercel detecta Vite; `vercel.json` ya trae el rewrite de SPA).
- Environment Variables:

```
VITE_API_URL=https://<backend>.up.railway.app/api/v1
VITE_REVERB_APP_KEY=<la misma key del backend>
VITE_REVERB_HOST=<reverb>.up.railway.app
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

- Deploy. Si el dominio final de Vercel difiere del que pusiste en `FRONTEND_URL` del
  backend, actualízala (el CORS rechaza orígenes que no coincidan EXACTO).

## 6. Verificación (equivalente al preflight)

```bash
railway run php artisan reloj:preflight        # con el CLI de railway linkeado al backend
```

y el humo de siempre: login como colaborador → dial con nombre/puesto → fichar entrada →
verla en el monitor → completar una tarea → monedas suben UNA vez → login admin → paneles
de resolución visibles.

## Gotchas conocidos de este par de plataformas

1. **La cookie de login es lo primero que revisar si "no autentica"**: debe llegar con
   `SameSite=None; Secure` (DevTools → Application → Cookies). Si sale `Lax`, faltó
   `AUTH_COOKIE_SAMESITE=None` o `APP_ENV=production`.
2. **CORS**: `FRONTEND_URL` debe ser el origen EXACTO de Vercel (con https, sin barra final).
   Los previews de Vercel (`*-git-rama.vercel.app`) son ORÍGENES DISTINTOS — o pruebas solo
   con el dominio de producción de Vercel, o agregas el preview a `FRONTEND_URL` en Railway.
3. **Disco efímero**: solo lo montado en el Volume sobrevive deploys. El caché `file` se
   pierde (inofensivo); las fotos NO deben quedar fuera de `/var/www/storage/app`.
4. **Migraciones en el arranque**: `Dockerfile.railway` migra al arrancar (pragmático para
   pruebas). Para AWS/producción real, la migración vuelve a ser paso de release con
   respaldo previo (modelo `deploy_seguro.py`).
5. **Railway duerme/reinicia contenedores**: el worker se reinicia solo (Restart Policy
   default); si el scheduler muere, el reinicio lo revive junto con la cola.
