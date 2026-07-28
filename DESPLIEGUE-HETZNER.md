# Despliegue a Hetzner — checklist

Estado del código: **listo**. 820 tests en verde (incluye la remediación 10/10 de la auditoría de
Tareas/Rutinas del 2026-07-27), build de frontend limpio, migraciones probadas sobre una base con
datos reales, jornada completa verificada end-to-end.

Lo que sigue son los pasos que **no se pueden hacer desde el entorno de desarrollo** porque
dependen del servidor. Los tres primeros son bloqueantes.

---

## 🔴 Bloqueantes (hacer sí o sí antes de operar con datos reales)

### 1. Respaldar la base de datos ANTES de migrar

El despliegue aplica 28 migraciones sobre la base existente. Están probadas y conservan los datos
(lo verifiqué sobre una copia con esquema previo y ponches cargados), pero un respaldo es
irrenunciable cuando hay nómina de por medio.

```bash
docker exec talent360_postgres pg_dump -U postgres talent360_saas > backup_antes_del_reloj_$(date +%F).sql
```

Comprueba que el archivo pese algo razonable antes de continuar.

### 2. Configurar el `.env` del servidor

De `APP_ENV` dependen tres protecciones. Si queda en `local`, se reactivan en silencio: el cliente
podría fijar la hora de su propio ponche, se permitiría suplantar usuarios en la apertura de tienda,
y los endpoints de borrado de QA quedarían abiertos.

```
APP_ENV=production
APP_DEBUG=false
ALLOW_QA_RESET=false
SESSION_SECURE_COOKIE=true    # si el sitio va por HTTPS
```

`APP_DEBUG=true` en producción muestra la traza completa —con credenciales de base de datos— en
cada error 500.

### 3. Caché: el stack no tiene Redis

El `docker-compose.yml` levanta `db`, `backend`, `backend-web`, `frontend` y `reverb` — **no hay
servicio de Redis**. Si el `.env` del servidor trae `CACHE_STORE=redis`, la aplicación revienta con
`Class "Redis" not found` en el primer login.

Dos opciones: usar el sistema de archivos (más simple, suficiente para este tamaño) o añadir Redis
al compose.

```
CACHE_STORE=file
```

### 4. Scheduler y cola (sin esto, lo nocturno y los jobs NO corren)

Nada en el servidor ejecuta `php artisan schedule:run`, así que **nada de lo agendado corre**:
ni `tasks:flag-unfinished` (tareas inconclusas), ni `payroll:calculate-weekly` (pre-nómina
diaria), ni la purga de chat. Y con `QUEUE_CONNECTION=database`, los jobs
(`LogTaskValidationJob`, eventos de websocket) se encolan y nadie los procesa.

**`deploy_seguro.py` ya instala ambos crons (FASE 8, idempotente).** Si despliegas a mano,
estas son las líneas (crontab de root en el host):

```bash
* * * * * docker exec -u www-data talent360-backend php artisan schedule:run >> /var/log/talent360-schedule.log 2>&1
* * * * * flock -n /tmp/talent360-queue.lock docker exec -u www-data talent360-backend php artisan queue:work --stop-when-empty --max-time=50 --tries=3 >> /var/log/talent360-queue.log 2>&1
```

Verifica con `crontab -l` y revisa los logs en `/var/log/talent360-*.log` el primer día.

---

## ✅ Verificación posterior al despliegue

Después de `deploy.sh` (que ya corre `composer install`, `migrate --force` y `config:clear`):

```bash
docker exec talent360-backend php artisan reloj:preflight
```

Comprueba entorno, debug, clave de app, reseteo de QA, cookie segura, conexión a base de datos,
migraciones pendientes, passcodes de Wiki y zona horaria por empresa. Si sale con fallos, **no
operes con datos reales hasta resolverlos**.

Después, una comprobación manual de humo (5 minutos):

1. Entrar como colaborador y ver que el dial carga con su nombre y puesto.
2. Registrar una entrada y confirmar que aparece en el monitor.
3. Completar una tarea y ver que las monedas suben (una sola vez).
4. Entrar como administrador y ver que los paneles de resolución aparecen.

---

## ⚙️ Configuración por empresa (dentro de la app, no en el servidor)

- **Zona horaria** de cada empresa. De ella dependen los retardos y el corte del día en nómina;
  sin configurar se asume `America/Mexico_City`.
- **Horario de la tienda** y **encargados de apertura** con su permiso `can_open_store`. Si no hay
  nadie designado, el bloqueo por tienda cerrada no se aplica (por diseño: sin encargados no existe
  el concepto de "abierto").
- **Políticas LFT**: tolerancia de retardo, penalización por minuto, si el checklist de cierre es
  obligatorio, y si la salida requiere aprobación.
- **PINs de kiosko** si vas a usar la tablet compartida, y **PINs de seguridad** si vas a usar la
  apertura de emergencia con testigos.
- **Passcodes de la Wiki**: si no los fijas, la Wiki pública queda cerrada (comportamiento seguro,
  ya no hay contraseñas por defecto en el código).

Opcionales, degradan con gracia si no se configuran: `GEMINI_API_KEY` (sin ella, la validación por
IA manda la tarea a revisión humana en vez de fallar) y las credenciales de Firebase (las
notificaciones push se registran en el log).

---

## ⚠️ Lo que no pude verificar desde aquí

Para que sepas dónde mirar si algo falla:

- **El despliegue real** (`deploy.sh` / `deploy_to_hetzner.py`) nunca se ejecutó; solo se leyó.
- **El kiosko, el modo offline y los WebSockets** (reverb) no se probaron en el navegador. La lógica
  de servidor sí está cubierta por tests.
- **Volumen de datos**: las migraciones se probaron con pocos registros. Con miles de ponches, la
  que reasigna sucursales (`create_stores_and_remap_store_ids`) puede tardar más; conviene
  desplegar fuera del horario de trabajo.
- **La PWA en un móvil real** (instalación, notificaciones locales de la alarma de traslado).

---

## 📌 Decisión pendiente de tu jefe

El botón de **"tarea al vuelo"** del dial quedó bloqueado para empleados rasos, porque se conservó
su regla §31 (un empleado no puede crear tareas). El comentario en `TaskSyncController::sync()`
explica cómo reabrirlo de forma granular si lo quieren de vuelta.
