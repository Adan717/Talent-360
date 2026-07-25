# Contrato de Interfaces Backend — Reloj Checador (Fase Auditoría Jul 2026)

Este documento es el **contrato congelado** entre Frontend (Cowork/Claude) y Backend (Claude Code).
Backend implementa exactamente estas rutas, tablas y payloads. Frontend consume exactamente esto.
Si alguna de las dos partes necesita cambiar algo, se edita ESTE archivo primero y se avisa en el chat — no se improvisa contra el otro lado.

**Ámbito de archivos (para evitar conflictos de edición simultánea):**

| Zona | Propiedad | No tocar |
|---|---|---|
| `Backend/app/**`, `Backend/database/migrations/**`, `Backend/routes/api.php`, `Backend/tests/**` | Claude Code | Frontend no debe tocar backend |
| `Frontend/src/components/reloj/**`, `Frontend/src/lib/**`, `Frontend/src/store/**` | Cowork (Claude/Sonnet) | Backend no debe tocar frontend |
| `docs/BACKEND_INTERFACES.md` | Compartido — solo se edita para actualizar el contrato, avisando en chat | — |

Convenciones generales: todas las rutas van bajo el grupo autenticado existente (`auth:sanctum` + tenant), igual que las rutas actuales de `/clock/*` y `/sync/*` en `routes/api.php`. Todas las respuestas de error usan el formato ya establecido: `{"success": false, "message": "..."}`.

---

## 📋 Pendientes para Claude Code (checklist rápido)

Cuando Francisco diga la palabra clave **"revisa pendientes del contrato"**, ve directo a esta tabla — no hace falta releer todo el documento. Marca cada fila ✅ cuando termines de implementarla y agrega, justo debajo de la sección correspondiente, un bloque `## ✅ Implementado (fecha) — ...` con el resumen de lo que hiciste (igual que ya se ha hecho en §12, §13 y §19), para que quede el historial de decisiones.

| Sección | Qué falta | Estado |
|---|---|---|
| §14.1 | Poblar `date` y `points_awarded` en `TaskAssignment` (3 puntos del código citados) | ✅ Implementado (2026-07-21) |
| §14.2 | Filtro `tenant_id` + `date` en `DashboardMonitorController::getMonitorData()` | ✅ Implementado (2026-07-21) |
| §14.3 | Aceptar `pool`/`department` en `target_type` de `DashboardMonitorController::createTask()` | ✅ Implementado (2026-07-21) |
| §15 | Validación de secuencia de eventos en `ClockService::processPunch()` (tabla de prerequisitos) | ✅ Implementado (2026-07-21) |
| §16 | Rate limiting en `/clock/punch`, `/clock/punch-batch` y validación de PIN de testigos en `/clock/emergency-open` | ✅ Implementado (2026-07-21) |
| §20 | Nuevo evento `TimeEntryRecorded` (broadcast) emitido desde `ClockService::processPunch()` | ✅ Implementado (2026-07-21) |
| §21 | Validación de ciclos para `reports_to_role_ids` en `JobRoleController::update()` (hoy solo existe para `org_parent_role_id`) | ✅ Implementado (2026-07-21) |
| §22 | Calificación en Pase de Lista (tabla `pase_lista_ratings` + `POST /clock/pase-lista/ratings`) — estado #8 | ✅ Implementado (2026-07-21) |
| §23 | Evidencia fotográfica de comedor (tabla `meal_photo_evidences` + `POST /clock/meal-photo`) — estados #17/#18b | ✅ Implementado (2026-07-21) |
| §24 | Cola secuencial de reserva de comida (`GET/POST /meal-reservations/queue`) — estado #16b — ⚠️ decisión de producto abierta (reemplaza vs. convive) | ✅ Implementado (2026-07-21) — Francisco decidió: **convive** |
| §25 | Ley Silla: aprobación de supervisor + control de aforo (tabla `silla_requests`, tipos `silla_start/end`, endpoints `/clock/silla/*`) — estado #19 | ✅ Implementado (2026-07-21) |
| §26 | Aviso "Enviar Mensaje" empleado en puerta (`POST /clock/door-notice`) — estados #7/#11 — ⚠️ verificar si hay push server-side | ✅ Implementado (2026-07-21) — sí existe (Firebase/FCM vía `NotificationService`) |
| §25b | Falta `GET /clock/silla/requests?status=pending` para que el supervisor liste y apruebe solicitudes de silla en la app (hoy solo se puede aprobar con el request_id que llega por push) | ✅ Implementado (2026-07-21) |
| §27 | Migrar los 4 eventos del canal del reloj (`StoreOpened`, `TimeEntryRecorded`, `DoorNoticeCreated`, `MealQueueTurnChanged`) de `Channel` público a `PrivateChannel` — ver spec abajo. **Urgente (Hallazgo 2 de seguridad):** hoy cualquiera puede escuchar fichajes de otro tenant sin loguearse. | ✅ Implementado (2026-07-21) — **desplegado, listo para que Cowork active `.private()` en el frontend** |
| §28 | Bug: `StoreOpeningService::openStoreAndClockIn()` línea 157 rechaza a `platform_admin` al presionar "Abrir Tienda" — ver detalle abajo. **Urgente:** bloquea el uso normal de la Matrix. | ✅ Implementado (2026-07-21) |
| §29 | `GET /store-opening/assignments` sigue devolviendo `employee_id` como `employees.id` (post-migración del 7-jul), pero 26 sitios del frontend (RRHH, Matrix, useStoreOpening, useKeyholderDelegation, MealQueue, RelojVisual) comparan ese valor contra `users.id` — mismo bug de raíz que §28, sin corregir en este endpoint. Ver detalle abajo. | ✅ Implementado (2026-07-22) — campo `resolved_user_id` agregado |
| §30 | `POST /store-opening/assignments` — al crear un encargado nuevo desde `CompanySettingsPanel.tsx`, el frontend envía `employee_id: (userObj.employee_id \|\| userObj.id)`. Ninguno de los dos es el `employees.id` real: `userObj.employee_id` es un campo de texto libre (código/gafete, ej. "EMP-0004", no garantizado numérico ni único) y `userObj.id` es `users.id`. El frontend no tiene forma de conocer el `employees.id` real de un usuario — no viene hidratado en `globalUsers`. Ver detalle abajo. | ✅ Implementado (2026-07-22) — **Opción A, cambio de contrato: ver nota para Cowork abajo** |
| §31 | **Seguridad:** `POST /sync/tasks` (crear/editar Tareas y Rutinas, no las asignaciones operativas) no valida el rol de quien llama. Ver detalle abajo — **ya preparé el lado frontend para que el fix de 3 líneas sea seguro de aplicar sin romper nada operativo.** | ✅ Implementado (2026-07-22) |
| §32 | Tarea placeholder "Monitoreo de seguridad desde silla" (Ley Silla) usa `taskId: 9999` inventado, sin registro real en `tasks`; falta operador null-safe en `TaskSyncController::sync()` línea ~227. Ver detalle abajo. | ✅ Implementado (2026-07-22) |
| §33 | Sugerencia de arquitectura (no urgente): el módulo de Tareas sincroniza reenviando el estado completo en cada clic en vez de por fila. Ver detalle abajo para el plan completo de migración. | ✅ Punto 1 implementado (2026-07-22) — **listo para que Cowork empiece el punto 3 (frontend)** |
| §34 | Nuevo endpoint `POST /task-assignments/{id}/omit` — avisar al supervisor cuando un empleado omite una tarea (hoy solo cambia el estado local, nadie se entera). Ver detalle abajo. | ✅ Implementado (2026-07-22) |
| §35 | Nuevo modo de validación `'ai_comparison'` para Tareas: el admin sube 3-5 imágenes de referencia + tolerancia por tarea; la IA (Gemini, ya integrado en `GeminiAIService.php` pero sin usar) compara la evidencia del empleado. Además, subir evidencia de foto real en el módulo de Tareas (hoy es un stub sin cámara real). Ver detalle abajo. | ✅ Implementado (backend 2026-07-22, Cowork 2026-07-23) — **completo en ambos lados** |
| §36 | Exponer `hire_date`/antigüedad por empleado en `DashboardMonitorController::getMonitorData()` — hoy solo se usa internamente en otros controladores, no llega al monitor. Ver detalle abajo. | ✅ Implementado (2026-07-22) |
| §37 | Modo Kiosco: login por PIN en tablet compartida reutilizando `employees.security_pin` (ya existe, no crear campo nuevo). Ver detalle abajo. | ✅ Implementado (2026-07-22) |
| §38 | Vincular Tareas con lecciones de la Academia (`academy_lesson_id` en `tasks`, reutilizando `video_url` que ya existe) + preferencia por empleado `academy_assistant_enabled` (reutilizando `employees.clock_preferences`, ya existe pero sin usar). Ver detalle abajo. | ✅ Implementado (2026-07-22) |
| §39 | Cadena de pedidos compras→producción→ventas con notificaciones entre puestos. Ver detalle abajo. | ✅ Implementado (2026-07-23) — **versión completa y configurable (Francisco decidió), ver nota para Cowork abajo** |
| §40 | Plan de trabajo diario: campo `origin` en `task_assignments` (planned/carried_over/extra/routine) para poder armar el reporte de cierre del día. Ver detalle abajo. | ✅ Implementado (2026-07-22) |
| §41 | Nuevo endpoint `POST /task-assignments/{id}/validate-with-pin` — validar una tarea con el PIN de un supervisor sin que ese supervisor tenga que iniciar sesión en el dispositivo (reutiliza `employees.security_pin`, ya existe). Ver detalle abajo. | ✅ Implementado (2026-07-22) |
| §42 | Nuevo endpoint de IA que sugiere el plan de trabajo del día ("Armar Plan de Hoy") usando quién asistió + tareas pendientes + el vault Obsidian (organigrama/manual operativo) como contexto. Ver detalle abajo. | ✅ Implementado (2026-07-23) |
| §43 | Migrar autenticación de Bearer token en `localStorage` a cookie de sesión `httpOnly` vía Sanctum SPA (Hallazgo 3 de seguridad, no urgente hoy pero conviene resolver la causa raíz). Ver detalle abajo. | ⏳ Diferido — grande, no urgente, toca impersonación; recomiendo pasada dedicada (ver nota) |
| §44 | **Urgente (seguridad):** sanitizar `ObsidianDocument.content` (y el HTML del asistente de contratos) al guardar en el servidor — hoy se renderiza sin sanitizar en una página **pública sin sesión** (`/organizacion/:tenantSlug/:docSlug`). Cowork ya mitigó del lado del cliente, esto es la segunda capa. Ver detalle abajo. | ✅ Implementado (2026-07-24) — sanitizador nativo en el servidor |
| §45 | Rendimiento: agregar índices compuestos `(tenant_id, date)` / `(tenant_id, created_at)` en `time_entries`, `store_logs`, `contingencies`, `internal_messages`, `audit_logs` — hoy solo tienen el índice simple de `tenant_id` que trae el `foreignId()`, pero `ClockController::getState()` filtra siempre por los dos campos juntos. Ver detalle abajo. | ✅ Implementado (2026-07-24) |
| §46 | Rendimiento: optimizar `ClockController::getState()` (el endpoint `/sync/state`, llamado cada 60s por cada sesión activa) — cachear datos casi estáticos (`job_roles`, `permissions`, `role_permissions`, `ui_rbac_rules`, `role_clock_policies`), corregir el N+1 de `routines`→`routine_task`, y quitar la consulta duplicada de `role_permissions`. Ver detalle abajo. | ✅ Parcial (2026-07-24): N+1 y consulta duplicada corregidos. **Caché diferido** (el propio contrato ofrece esta división) — ver nota |
| §47 | **Urgente (seguridad):** `DatabaseSeeder.php` inserta 3 cuentas de `platform_users` con contraseñas hardcodeadas en texto plano en el repo (`Master`, `Master`, `Support123`), una de ellas con el correo real de Francisco. Ver detalle abajo. | ✅ Implementado (2026-07-24) — contraseñas fuera del repo (env) + cuenta genérica eliminada |
| §48 | Completar el flujo de 2FA (existe el campo `two_factor_enabled`/`two_factor_secret` y el flag `requires_2fa` en la respuesta de login, pero no hay endpoint que valide el código — y hoy excluye explícitamente a `platform_users`). Ver detalle abajo. | ⏳ Bloqueado — requiere el paquete `pragmarx/google2fa`, no instalado y sin red para agregarlo. Ver nota |
| §49 | Separar estrictamente `platform_admin`/`support_agent` de la tabla `users` (hoy el enum `UserRole` y `TenantScope` todavía contemplan que una fila de `users` tenga esos roles) + endpoint de revocación masiva de sesiones de `platform_users` ("botón de pánico"). Ver detalle abajo. | ✅ Parcial (2026-07-24): botón de pánico (`revoke-all-sessions`) hecho. **Separación de rol + cambio a `TenantScope` diferidos** — requieren auditoría de la BD de producción (ver nota) |
| §50 | **Urgente (seguridad/integridad):** `SubscriptionController::provisionTenant()` (rama "Standard creation flow", sin autenticación) puede reasignar el `tenant_id` de un usuario YA existente (dueño de otra empresa) a una empresa nueva si alguien manda su `admin_email` en el checkout — le "roba" el admin a su empresa original sin que esa persona haya iniciado sesión. Confirma la regla de negocio "1 cuenta Google = 1 empresa" que pidió Francisco, pero expone un hueco real. Ver detalle abajo. | ✅ Implementado (2026-07-24) — rechaza con 409 en vez de reasignar |
| §51 | Credenciales de las cuentas de prueba (Francisco las pidió directamente): rotar las 3 hardcodeadas del seeder (§47) a contraseñas fuertes concretas, y renombrar/crear cuentas de DecorArte 360 y de plataforma con el convenio `nombre@decorarte360.com` / `nombre@talent360.mx` que confirmó. Ver detalle abajo — incluye las contraseñas propuestas para que las apliquen tal cual o las ajusten. | ⏳ Mecanismo listo (env-driven vía §47) — **aplicar las contraseñas concretas es paso de ops + necesita el ID real de DecorArte; ver nota** |

Si terminaste todo lo de arriba y no queda nada pendiente, contesta simplemente "sin pendientes" cuando te pregunten con la palabra clave.

---

## 1. `/clock/punch-batch` — Sincronización Offline en Transacción Única

Reemplaza el loop actual de `syncOfflineQueue()` en `useClockEngine.tsx` que llama a `/clock/punch` una vez por ítem. En su lugar, todo el lote se procesa en una sola petición.

### Request

```
POST /api/clock/punch-batch
```

```json
{
  "punches": [
    {
      "user_id": 11,
      "type": "check_in",
      "time": "08:32:00",
      "details": { "note": "Sin luz en sucursal", "offline": true },
      "gps": { "latitude": 19.4326, "longitude": -99.1332 },
      "client_timestamp": "2026-07-20T08:32:00-06:00",
      "offline_stamp": "a3f9c2...64-char-hex-hmac"
    }
  ]
}
```

**Campo `offline_stamp`** — ver sección 5 (Firma Criptográfica). El backend recalcula el HMAC con el secreto compartido del tenant y compara contra el enviado. Si no coincide, ese ítem individual se marca `rejected` con motivo `invalid_signature`, pero **no aborta el resto del batch**.

### Comportamiento

- Todo el array se procesa dentro de **un único `DB::transaction()`**.
- Si el HMAC de un ítem falla, ese ítem se excluye de la transacción pero el resto continúa (no todo-o-nada a nivel de firma; sí todo-o-nada a nivel de escritura en BD si hay un error de sistema).
- Reutiliza `ClockService::processPunch()` internamente, no dupliques la lógica de negocio (retardos, IP lock, holiday block, etc.) — ese servicio ya existe y funciona, solo se envuelve en batch.
- Orden de procesamiento: por `client_timestamp` ascendente (para que `check_in` antes de `meal_start` respete la secuencia aunque lleguen desordenados).

### Response

```json
{
  "success": true,
  "processed": 3,
  "rejected": 1,
  "results": [
    { "index": 0, "success": true, "entry_id": 4821, "type": "check_in" },
    { "index": 1, "success": false, "reason": "invalid_signature" },
    { "index": 2, "success": true, "entry_id": 4822, "type": "meal_start" }
  ]
}
```

Frontend usa `results[].index` para saber qué ítems borrar de `IndexedDB` (`offlineDb.deletePunch`) — solo se borran los `success: true`. Los `rejected` quedan en cola para revisión manual (no se reintenta automático, porque una firma inválida es indicio de manipulación, no de un fallo de red).

---

## 2. Firma Criptográfica Offline (`offline_stamp`)

### Frontend (Cowork implementa)

En `Frontend/src/lib/offlineDb.ts`, al guardar un punch offline:

```ts
offline_stamp = HMAC_SHA256(
  secret = tenant_offline_secret,   // ver abajo cómo se obtiene
  message = `${user_id}|${type}|${time}|${client_timestamp}`
)
```

El secreto **no se hardcodea en el bundle JS** (sería trivial de extraer). Se obtiene una vez al iniciar sesión desde un endpoint autenticado y se guarda en memoria (no en localStorage, para reducir superficie de robo):

### Endpoint nuevo: `GET /clock/offline-secret`

```json
{ "success": true, "secret": "base64-random-32-bytes", "issued_at": "2026-07-20T08:00:00Z" }
```

- El secreto es **por tenant**, no por usuario — todos los empleados de la misma sucursal firman con la misma clave, porque la validación es "este punch vino de un dispositivo autenticado de este tenant", no de identidad individual (para eso ya está `user_id` + Sanctum).
- Se rota cada 24h (columna `expires_at` en la tabla nueva `tenant_offline_secrets`).
- Backend valida el HMAC recalculándolo con el secreto vigente (o el anterior, con una ventana de gracia de 1h, por si el dispositivo estuvo offline al momento de la rotación).

### Migración nueva sugerida

```php
Schema::create('tenant_offline_secrets', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
    $table->string('secret'); // encriptado con Crypt::encryptString()
    $table->timestamp('expires_at');
    $table->timestamps();
});
```

**Nota de riesgo aceptado:** esto no es firma asimétrica (no hay clave privada por dispositivo), es HMAC simétrico por tenant. Es una mejora real sobre "texto plano editable en DevTools" pero no es criptografía de no-repudio individual. Si más adelante se necesita blindaje legal más fuerte (para litigios laborales), la siguiente iteración sería un par de claves por dispositivo/usuario. Lo dejamos anotado para no venderlo como más fuerte de lo que es.

---

## 3. Apertura de Emergencia con 2 Testigos

Implementa el estado **#9** de la matriz (`docs/funcionamiento_del_dial.md`).

### Endpoint: `POST /clock/emergency-open`

```json
{
  "requester_id": 12,
  "witness_1_id": 3,
  "witness_1_pin": "4821",
  "witness_2_id": 7,
  "witness_2_pin": "9034",
  "store_id": 1
}
```

### Comportamiento

1. Verifica que `requester_id` sea suplente activo (`store_opening_assignments.has_keys = true`).
2. Verifica que ambos testigos existan, pertenezcan al mismo tenant, y que su PIN coincida (reutiliza el mismo mecanismo de PIN que ya exista para supervisores — si no existe columna `pin` en `users`, se necesita migración `add_pin_to_users_table`, columna `pin` string nullable, hasheada con `Hash::make`).
3. Los dos testigos deben ser distintos entre sí y distintos del `requester_id`.
4. Si todo valida: marca `store_daily_opening_statuses.status = 'opened'`, `opened_by_employee_id = requester_id`, dispara evento `StoreOpened` (ya existe, se reutiliza — Frontend ya escucha `.App\Events\StoreOpened` vía Reverb).
5. Inserta en `store_opening_events` con `event_type = 'emergency_open'`, `metadata_json` incluyendo los IDs de ambos testigos (para auditoría — nunca el PIN).
6. Dispara alerta prioritaria a RRHH — reutiliza el mecanismo existente de `addMatrixEvent` / notificaciones que ya usa `report-absence`.

### Response éxito

```json
{ "success": true, "message": "Apertura de emergencia autorizada.", "status": { "...": "mismo shape que /store-opening/today" } }
```

### Response error (ejemplos)

```json
{ "success": false, "message": "PIN de testigo incorrecto." }
{ "success": false, "message": "Los testigos deben ser dos empleados distintos presentes en sucursal." }
```

---

## 4. Declaración de Contingencia (Sin Luz / Sin Internet)

Implementa el estado **#10** y **#15** de la matriz.

### Endpoint: `POST /clock/declare-contingency`

```json
{
  "user_id": 12,
  "reason": "no_power",
  "declared_at": "2026-07-20T08:32:00-06:00",
  "offline_stamp": "..."
}
```

`reason` es un enum: `no_power` | `no_internet` | `no_power_and_internet`.

### Comportamiento

1. Crea (o reutiliza si ya existe una abierta hoy para el tenant/store) un registro en tabla nueva `contingency_declarations`.
2. Marca en `time_entries.details` (`lft_incident.type = 'contingency'`) para que el motor de nómina (`ClockService::calculatePayrollForEmployee`) excluya estos días de retardos/faltas — actualmente ese método no sabe nada de contingencias, hay que agregarle el filtro.
3. Efecto legal: cuando `contingency_declarations` tiene un registro activo para ese `tenant_id + date`, `ClockService::processPunch()` debe **saltarse el cálculo de `isLate`** para ese usuario ese día (100% salario garantizado, como dice `docs/funcionamiento_del_dial.md` sección 1.1).

### Migración nueva sugerida

```php
Schema::create('contingency_declarations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
    $table->integer('store_id')->default(1);
    $table->foreignId('declared_by_user_id')->constrained('users');
    $table->date('date');
    $table->string('reason'); // no_power | no_internet | no_power_and_internet
    $table->timestamp('declared_at');
    $table->timestamp('resolved_at')->nullable();
    $table->timestamps();

    $table->index(['tenant_id', 'date']);
});
```

### Response

```json
{ "success": true, "message": "Contingencia declarada. Jornada protegida al 100% conforme LFT.", "contingency_id": 55 }
```

---

## 5. Alarma Pre-Turno (Perfil de Usuario)

Referenciado en `docs/funcionamiento_del_dial.md` sección 3 y 6: *"Configura tu alarma"* — falta por completo, incluyendo la migración que la propia doc ya especifica.

### Migración (ya nombrada en la doc, respetar el nombre)

```php
// add_pre_shift_alarm_to_users_table
Schema::table('users', function (Blueprint $table) {
    $table->integer('pre_shift_alarm_minutes')->nullable()
        ->comment('Minutos antes de shiftStart para notificación push local (15,30,45,60)');
});
```

### Endpoint: `PUT /me/pre-shift-alarm`

```json
{ "minutes": 45 }
```

Valores permitidos: `null, 15, 30, 45, 60`. Guarda en `users.pre_shift_alarm_minutes`. La notificación en sí es push local del navegador/PWA (Frontend la dispara con `Notification` API comparando `shiftStart - minutes` contra la hora actual) — el backend solo persiste la preferencia, no dispara nada por su cuenta.

### Response

```json
{ "success": true, "pre_shift_alarm_minutes": 45 }
```

---

## 6. Checklist de Cierre Seguro

Estado **#22** de la matriz: 3 ticks (luces, caja fuerte, alarma) antes de `check_out`. Ya existe el patrón equivalente para apertura (`require_opening_checklist` en `store_opening_settings` + modal en frontend) — este es el espejo para cierre.

### Migración: agregar columna a tabla existente

```php
Schema::table('store_opening_settings', function (Blueprint $table) {
    $table->boolean('require_closing_checklist')->default(true)->after('require_opening_checklist');
});
```

### Endpoint: `POST /store-opening/closing-checklist`

```json
{
  "user_id": 12,
  "checks": {
    "lights_off": true,
    "safe_secured": true,
    "alarm_activated": true
  }
}
```

### Comportamiento

Si `require_closing_checklist` está activo y los 3 checks no son `true`, el endpoint `/clock/punch` con `type=check_out` debe rechazar con `400` y mensaje `"Completa el checklist de cierre antes de registrar salida."`. Esto requiere un pequeño ajuste en `ClockService::processPunch()`: antes de aceptar `check_out`, verificar si existe un registro de `closing-checklist` completo para ese `user_id + date`. Sigue el mismo patrón que ya usa para `store_daily_opening_statuses`.

Guarda el resultado en tabla nueva `store_closing_checklists` (mismo shape que `store_opening_events`, o se puede reutilizar `store_opening_events` con `event_type = 'closing_checklist'` y `metadata_json` con los 3 booleanos — preferible reutilizar para no crear tabla extra, decisión de Claude Code).

---

## 7. Coordenadas de Sucursal — Sacar del Hardcode

**Esto no es una feature nueva, es una corrección urgente.** `Frontend/src/components/reloj/useClockEngine.tsx` líneas 637-639 tiene:

```js
const STORE_LAT = 19.4326;
const STORE_LNG = -99.1332;
const ALLOWED_RADIUS_METERS = 50;
```

El backend YA tiene la fuente correcta: `clockOpConfig.store_latitude` / `store_longitude` / `geo_radius_meters` dentro de `system_settings` (se ve usado en `ClockService.php` líneas 143-170). Frontend debe leer esos mismos valores desde `systemSettings.clockOpConfig` (que ya se trae en `fetchState()` — no hace falta endpoint nuevo). Este punto es solo aviso de coordinación: **Cowork hace el cambio en frontend, Backend no necesita tocar nada aquí**, solo confirmar que `clockOpConfig.store_latitude/longitude/geo_radius_meters` siempre vienen poblados (hoy son opcionales con `?? null`, deberían tener default sensato en el seeder/migration para tenants nuevos).

---

## 8. Resumen de Endpoints Nuevos (checklist para Claude Code)

| Método | Ruta | Prioridad | Estado |
|---|---|---|---|
| `POST` | `/clock/punch-batch` | 🔴 Crítica — base legal de offline-first | ✅ Implementado (2026-07-20) |
| `GET` | `/clock/offline-secret` | 🔴 Crítica — requerido por punch-batch | ✅ Implementado (2026-07-20) |
| `POST` | `/clock/emergency-open` | 🟠 Alta — estado #9 | ✅ Implementado (2026-07-21) |
| `POST` | `/clock/declare-contingency` | 🟠 Alta — estado #10/#15 | ✅ Implementado (2026-07-21) |
| `PUT` | `/me/pre-shift-alarm` | 🟡 Media — estado #1 perfil | ✅ Implementado (2026-07-21) |
| `POST` | `/store-opening/closing-checklist` | 🟡 Media — estado #22 | ✅ Implementado (2026-07-21) |
| `GET` | `/me/punctuality-status` | 🟢 Baja — estado #1 (ver §12, requiere decisión de negocio antes de codear) | ⏳ Propuesta, no implementada |

### Notas de implementación — Backend (2026-07-20)

Frontend ya puede integrar contra `/clock/punch-batch` y `GET /clock/offline-secret` tal como están descritos en las secciones 1 y 2. Ningún contrato de request/response cambió respecto a lo aquí escrito. Dos detalles internos que no rompen el contrato pero vale la pena que Cowork conozca:

- **`ClockService::processPunch()` gana un flag interno `details['offline_sync'] = true`**, que el nuevo `punchBatch()` agrega automáticamente a cada ítem antes de llamar al servicio. Con ese flag, `processPunch` usa el `time` recibido como la hora real del fichaje en vez de la hora del servidor al momento de sincronizar — sin esto, un `check_in` de las 08:05 que sincroniza a las 14:00 se hubiera guardado como si hubiera ocurrido a las 14:00. No requiere ningún cambio en el payload que manda el frontend, es transparente.
- **Límite conocido, no resuelto en este pase:** la columna `date` de `time_entries` sigue tomando la fecha real del servidor al sincronizar, no la fecha del `client_timestamp`. Si un dispositivo queda offline toda la noche y sincroniza al día siguiente, un fichaje de las 11:50pm quedará fechado el día en que sincronizó, no el día real del evento. No estaba en el alcance de esta ronda (solo los dos endpoints 🔴); si es importante para el corte de nómina, avisar para priorizarlo.
- **`ClockService::ALLOWED_TYPES` ya incluye `temp_exit_start` y `temp_exit_end`** (el bug independiente que señala la sección 9 de este documento). El fix ya está aplicado, no depende de que Cowork cambie nada.

### Notas de implementación — Backend (2026-07-21)

`/clock/emergency-open` y `/clock/declare-contingency` ya están implementados. La ruta de `emergency-open` la maneja `StoreOpeningController::emergencyOpen` (aunque vive bajo el prefijo `/clock/*` tal como pide el contrato) y `declare-contingency` vive en `TimeEntryController`. Puntos donde interpreté algo que el contrato no dejaba 100% explícito — avisando en vez de improvisar en silencio:

- **`emergency-open` también hace `check_in` del solicitante**, no solo marca la tienda como abierta. El contrato (paso 1-6) no lo lista explícitamente, pero la Matriz de 23 Estados dice que el siguiente estado es `active`, y dejar la tienda "abierta" sin fichar a quien la abrió es un hueco funcional obvio (mismo patrón que ya usa `openStoreAndClockIn`). La respuesta sigue teniendo exactamente el shape `{success, message, status}` del contrato — el fichaje ocurre server-side, no se agregó ningún campo nuevo a la respuesta. Si prefieren que NO se dispare el check-in automático, avisen y lo separo en dos pasos.
- **No existe todavía ningún endpoint para que un usuario configure su propio `pin`.** La migración `add_pin_to_users_table` y la validación en `emergency-open` (`Hash::check` contra `users.pin`) ya están listas, pero hasta que exista un `PUT /me/pin` (o se semille manualmente), ningún testigo tendrá PIN configurado y la validación siempre fallará con "PIN de testigo incorrecto." Lo dejo señalado aquí en vez de inventar ese endpoint sin que esté en el contrato.
- **Hallazgo colateral, no corregido en este pase:** una migración anterior (`2026_06_26_010708_migrate_existing_users_to_employees_table`) movió `shiftStart`, `shiftEnd`, `mealMinutes`, `restDay`, etc. de `users` a `employees`, pero `ClockService::processPunch()` sigue leyendo `$user->shiftStart` (línea ~203) — esa columna ya no existe en `users`, así que siempre cae al default `'09:00:00'` sin importar el horario real del empleado. Esto es anterior a mi trabajo y no lo toqué (afecta el cálculo de retardos en general, no solo a lo que pide este contrato), pero es importante que lo sepan porque probablemente está afectando retardos mal calculados en producción ahora mismo.
- **`ClockService::calculatePayrollForEmployee`** ahora excluye de retardos y de faltas físicas cualquier fecha con una `contingency_declarations` activa (sin `resolved_at`), tal como pide el punto 2 de la sección 4. También protege los días sin ningún fichaje (corte total de energía) para que no cuenten como falta.

### Notas de implementación — Backend (2026-07-21, ronda Media)

`PUT /me/pre-shift-alarm` (en `AuthController`) y `POST /store-opening/closing-checklist` (en `StoreOpeningController` → `StoreOpeningService::submitClosingChecklist`) ya están implementados. El checklist de cierre reutiliza `store_opening_events` con `event_type='closing_checklist'` como sugería el contrato, sin tabla nueva.

- **El bloqueo de `check_out` sin checklist completo solo aplica a tenants con el módulo `store_opening` activo** (`FeatureAccessService::tenantHasFeature($tenantId, 'store_opening')`), no a todos los tenants incondicionalmente. El contrato solo menciona la bandera `require_closing_checklist`, pero esa bandera vive en `store_opening_settings`, una tabla que hoy es 100% del módulo premium de apertura de tienda — aplicar el bloqueo sin ese filtro habría bloqueado el `check_out` de cualquier tenant Free que nunca configuró nada de esto (la migración pone el default en `true`). Usé el mismo criterio de activación que ya usa el checklist de apertura equivalente en el frontend. Avisen si en realidad lo querían incondicional.
- El checklist se puede reenviar el mismo día (por ejemplo si el usuario corrige un tick): reutiliza el mismo registro de `store_opening_events` de esa fecha en vez de crear uno nuevo cada vez.

Migraciones nuevas: `tenant_offline_secrets`, `contingency_declarations`, `add_pin_to_users_table` (si no existe ya — verificar primero, es posible que `supervisor_qr_tokens` ya cubra un caso similar y se pueda reutilizar patrón), `add_pre_shift_alarm_to_users_table`, `add_require_closing_checklist_to_store_opening_settings`.

Tests sugeridos (mínimo viable, no exhaustivo): `punch-batch` con firma válida/inválida mezcladas en un mismo batch; `emergency-open` con PIN correcto/incorrecto/testigo duplicado; `declare-contingency` verificando que un `check_in` posterior en el mismo día no compute `is_late`.

---

## 10. Dos Correcciones Pendientes (avisadas por Cowork, 2026-07-21) — ✅ Resueltas (2026-07-21)

Encontradas al revisar el estado del backend antes de seguir con el frontend. Ninguna de las dos rompe el contrato de las secciones 1-8, pero conviene resolverlas antes de que alguien intente probar Apertura de Emergencia o de que se sigan calculando retardos mal en producción.

### Resumen para Cowork — qué cambia del lado de contrato

**Nada en el request/response de `POST /clock/emergency-open` cambió.** Los testigos siguen mandando `witness_1_pin`/`witness_2_pin` como string plano en el body, igual que antes — el modal que ya construiste contra el contrato actual no necesita tocarse.

Lo único nuevo que el frontend necesita para que ese modal sirva de algo en la práctica es una pantalla de perfil donde el usuario configure su PIN, porque **antes de esta corrección nadie tenía PIN guardado en ningún lado**:

### Endpoint nuevo: `PUT /me/security-pin`

```json
{ "current_password": "la_contraseña_de_su_cuenta", "pin": "4821" }
```

- `pin`: 4 a 6 dígitos numéricos (`^\d{4,6}$`).
- Requiere la contraseña actual de la cuenta (mismo criterio que `changePassword`) porque este PIN autoriza acciones con peso legal/de nómina (co-validación de apertura de emergencia).
- Response éxito: `{ "success": true, "message": "PIN de seguridad actualizado." }`.
- Response error contraseña incorrecta: `422 { "success": false, "message": "La contraseña actual es incorrecta." }`.

### Decisión sobre dónde vive el PIN (por qué no se reutilizó `employees.pin_code`)

Revisé `pin_code` antes de decidir. **No se puede reutilizar tal cual** por dos razones técnicas, no solo de estilo:

1. `OnboardingController` hace `Employee::where('pin_code', $pin)->first()` — necesita buscar al empleado *por el valor del PIN* durante la activación de cuenta. Eso exige que el valor esté en texto plano (o sea determinísticamente reversible); un hash bcrypt (que es no-determinístico, la misma entrada produce un hash distinto cada vez) rompe esa búsqueda por completo.
2. `OnboardingController` pone `'pin_code' => null` al consumir el PIN (línea ~150, "Consumir el PIN") — es decir, **se borra la primera vez que el empleado activa su cuenta**. Cualquier empleado que ya haya hecho onboarding tiene `pin_code = NULL` hoy. Si `emergency-open` hubiera validado contra esa columna, literalmente nadie con cuenta activa habría podido ser testigo nunca.

`pin_code` es un código de invitación de un solo uso (como el link que manda WhatsApp), no un secreto recurrente. Son dos conceptos de seguridad distintos aunque ambos se llamen "pin".

**Decisión final:** nueva columna `employees.security_pin` (no `users.pin` como se había hecho en la ronda anterior — se corrigió para vivir en `employees`, consistente con dónde vive todo lo demás del empleado desde la migración de julio). Se guarda con `Hash::make()`, se valida con `Hash::check()`, oculta en `Employee::$hidden`. `StoreOpeningService::emergencyOpenWithWitnesses` ya quedó apuntando a `$witness->employee->security_pin`.

### 10.1 `ClockService::processPunch()` sigue leyendo `$user->shiftStart` (bug real, afecta producción hoy)

`Backend/app/Services/ClockService.php` línea ~232:

```php
$expectedTimeStr = $user->shiftStart ?? '09:00:00';
```

La migración `2026_06_26_010708_migrate_existing_users_to_employees_table` movió `shiftStart`, `shiftEnd`, `mealMinutes`, `restDay`, `portadorLlaves` de `users` a `employees`. La columna `users.shiftStart` ya no existe, así que esa línea siempre cae al default `'09:00:00'`, sin importar el horario real del empleado — todos los retardos se calculan mal.

**Fix:** el modelo `User` ya tiene la relación `employee()` (`hasOne(Employee::class, 'user_id')`, `app/Models/User.php` línea 34-36). Cambiar a `$user->employee?->shiftStart ?? '09:00:00'`. Revisar si hay más lecturas de `$user->shiftEnd`, `$user->mealMinutes`, `$user->restDay`, `$user->portadorLlaves` en `ClockService.php` y en cualquier otro controller/servicio que quedaron apuntando a `users` en vez de `users->employee` tras esa migración — este archivo es el que se detectó, pero conviene un grep general de esos cuatro nombres de columna contra `$user->` en `app/`.

### 10.2 Migración `add_pin_to_users_table` duplica una columna que ya existía

`Backend/database/migrations/2026_07_21_000000_add_pin_to_users_table.php` crea `users.pin`, pero `employees` ya tenía `pin_code` (string, 6 caracteres) desde `2026_06_26_010654_create_employees_table`, migración anterior a esta ronda de trabajo — visible también en `$fillable` de `app/Models/Employee.php`.

Dos sistemas de PIN separados sobre el mismo concepto es una fuente segura de bugs (¿cuál valida `emergency-open`? ¿cuál se actualiza si se agrega `PUT /me/pin`?). Además `employees.pin_code` es `string(6)` — un hash bcrypt no cabe en 6 caracteres, lo que sugiere que el diseño original era un PIN corto en texto plano (o con un algoritmo de hash corto tipo CRC, no `Hash::make()`), mientras que la nueva columna `users.pin` sí se valida con `Hash::check()`. Antes de que ninguna se use en producción, decidir cuál es la fuente de verdad — probablemente conviene reusar `employees.pin_code` (ya existe, ya está en el modelo) en vez de mantener `users.pin`, y hashearlo correctamente si el plan es guardarlo seguro (lo cual exigiría ampliar la columna más allá de 6 caracteres, o aceptar que un PIN de 4-6 dígitos con rate-limiting es suficiente sin hash, como suelen hacerlo los kioscos de reloj checador).

**Falta además** el endpoint para que el usuario configure su propio PIN — no existe ni `PUT /me/pin` ni `PUT /me/pin-code`. Sin esto, `emergency-open` seguirá rechazando todo con "PIN de testigo incorrecto" porque nadie tiene PIN guardado.

### Notas de implementación — Backend (2026-07-21, correcciones de Cowork)

- **10.1 resuelto:** `ClockService::processPunch()` ahora usa `$user->employee?->shiftStart ?? '09:00:00'`.
- **Grep solicitado (`shiftEnd`, `mealMinutes`, `restDay`, `portadorLlaves` contra `$user->`) encontró un bug adicional, ya corregido:** `KeyTransferController.php` (las 3 acciones — `store`, `pending`, `respond`) leía y escribía `portadorLlaves` directamente sobre instancias de `User` en vez de `Employee`. En la práctica esto significaba que **nadie podía crear una solicitud de transferencia de llaves** — la validación `$user->portadorLlaves` siempre daba `null`, así que el guard "No posees permisos de portador de llaves" se disparaba para todos, sin importar su rol real. Se corrigió para leer/escribir sobre `$user->employee->portadorLlaves`, incluyendo el eager-load de `pending()` que seleccionaba esa columna directamente de `users` (habría tirado error SQL de columna inexistente). `calculatePayrollForEmployee` y el `update()` de `ClockController` ya estaban bien (usan `$employee`, no `$user`) — no se tocaron.
- **10.2 resuelto** — ver el resumen al inicio de esta sección para la decisión final y el endpoint nuevo `PUT /me/security-pin`.
- 8 tests nuevos/actualizados cubriendo ambas correcciones (`SecurityPinAndKeyTransferTest`, ajustes en `ClockEmergencyContingencyTest`). Suite completa: 63/63 verde.

### 10.3 Dos correcciones más, backend-only, de la auditoría original (2026-07-21) — ✅ Resueltas

No forman parte del contrato de las secciones 1-8, no afectan ningún request/response que Cowork consuma — documentadas aquí solo para llevar registro.

- **Anti-duplicados real:** `ClockService::processPunch()` ahora rechaza (`400`, `"Fichaje Denegado: ya existe un registro de tipo '{type}' para hoy. No se puede duplicar."`) cualquier segundo fichaje del mismo `type` el mismo día para el mismo usuario. Antes no había ninguna validación — un doble clic o un reintento de red podía crear filas duplicadas.
- **✅ Cerrado también a nivel de base de datos (2026-07-21, con autorización explícita para el `DELETE`):** migración `2026_07_21_000005_deduplicate_and_add_unique_constraint_to_time_entries` deduplica filas históricas (conserva la de `id` más antiguo por cada `user_id+date+type`, que es la que refleja el fichaje real) y agrega `UNIQUE(user_id, date, type)`. `processPunch()` captura `UniqueConstraintViolationException` y devuelve el mismo mensaje amigable en vez de un error de SQL crudo, para el caso residual de dos peticiones casi simultáneas. **Importante:** esta migración solo se corrió contra la BD de pruebas (SQLite en memoria); no se ha ejecutado contra ningún ambiente con datos reales — correr `php artisan migrate` ahí aplicará el `DELETE` de duplicados antes de crear el índice, revisen el log de Laravel (`Log::warning`) para ver cuántos grupos duplicados se limpiaron.
- **Snapshot de nómina reparado:** `TimeEntry::$fillable` ya incluye `employee_name_at_time`, `job_role_title_at_time`, `base_salary_at_time` (antes Eloquent los descartaba en silencio, siempre quedaban `NULL`). De paso until otro bug relacionado: `ClockService` leía `$jobRole?->title`, pero la tabla `job_roles` no tiene columna `title` — el nombre del puesto vive en `name`. Se corrigió también, si no el snapshot del puesto habría seguido guardándose vacío aunque el `$fillable` ya estuviera bien.

---

## 9. Estados de la Matriz — Mapeo a Este Documento

| # Estado | Nombre | Cubierto por sección |
|---|---|---|
| 1 | Fichaje Bloqueado | §12 — ✅ Implementado (`GET /me/punctuality-status`) |
| 5 | Llamar a Suplente | Frontend-only, no requiere backend nuevo |
| 6 | En Camino a Sucursal | Frontend-only (geofencing progresivo con las mismas coords de §7) |
| 9 | Apertura de Emergencia | §3 |
| 10 | Declarar Eventualidad | §4 |
| 14 | Registrar Reingreso | Ya existe `temp_exit_start`/`temp_exit_end` en frontend, sin endpoint dedicado — reutiliza `/clock/punch` con esos types (verificar que estén en `ClockService::ALLOWED_TYPES`, hoy **no lo están** — agregar `temp_exit_start`, `temp_exit_end` a la constante) |
| 15 | Modo Contingencia Activo | §4 (mismo mecanismo, es el estado visual mientras la contingencia sigue abierta) |
| 22 | Checklist de Cierre | §6 |
| Perfil | Configura tu alarma | §5 |

**Nota aparte para Claude Code:** ~~`ClockService::ALLOWED_TYPES` (línea 20-23) no incluye `temp_exit_start` ni `temp_exit_end`~~ — ✅ corregido el 2026-07-20 junto con `/clock/punch-batch`. Ambos tipos ya están en la constante.

---

## 12. Estado #1 — Fichaje Bloqueado por 3 Retardos (propuesta, aún no implementada)

**Diagnóstico:** hoy `useClockEngine.tsx` (líneas ~3469 y ~3649) lleva el contador de retardos y el flag de bloqueo enteramente en `localStorage` (`user_retardos_<id>`). Cualquiera lo evade borrando el storage del navegador o entrando desde otro dispositivo, no se sincroniza entre equipos, y no tiene relación real con si el curso de la Academia fue completado (el botón "🎓 Ir a la Academia" no verifica nada del lado servidor).

Lo importante: **el dato real de retardos ya existe en el backend.** `ClockService::calculatePayrollForEmployee()` y el cálculo de nómina semanal ya leen `TimeEntry::where('is_late', true)` por periodo, descontando fechas con `ContingencyDeclaration` activa (mismo criterio que ya usa el resto del sistema). No hay que inventar una fuente de verdad nueva, solo exponerla.

También existe `AcademyCourse` + `UserCourseProgress` (`AcademyController.php`), pero el enum `course_type` actual es `induction|training|promotion|recertification` — no hay un tipo `punctuality` ni ningún mecanismo que ligue "3 retardos" a "debe completar este curso específico".

### Propuesta de contrato

```
GET /api/me/punctuality-status
```

```json
{
  "success": true,
  "blocked": true,
  "lates_count": 3,
  "period_start": "2026-07-01",
  "period_end": "2026-07-07",
  "required_course_id": 4,
  "course_completed": false
}
```

- `lates_count`: mismo query que ya usa `calculatePayrollForEmployee` (TimeEntry.is_late=true en el periodo vigente, excluyendo fechas con contingencia activa).
- `blocked`: `lates_count >= 3 && !course_completed`.
- `required_course_id`: id del curso que el tenant marcó como "curso de puntualidad" — requiere una nueva columna en `system_settings` (ej. `punctuality_course_id`) o un nuevo `course_type = 'punctuality'` en el enum de `academy_courses`. Cualquiera de las dos funciona; lo decide quien lo implemente.
- `course_completed`: `UserCourseProgress` del usuario para ese curso con `status = 'completed'`.

**Decisión de negocio pendiente (no la puedo tomar yo desde frontend):** ¿el contador de retardos se reinicia cuando empieza un nuevo periodo de nómina (consistente con cómo ya se calculan las faltas-por-retardos para nómina), o el bloqueo persiste indefinidamente hasta completar el curso sin importar que ya haya pasado a un periodo nuevo? Recomiendo lo segundo (el bloqueo es un tema de conducta/capacitación, no de nómina), pero es una regla de negocio que debería confirmar Francisco o decidirla Claude Code con una justificación.

Frontend, una vez exista el endpoint, reemplaza el `localStorage` actual por una llamada a `GET /me/punctuality-status` al montar el Dialer, cachea la respuesta en memoria (no en localStorage) y usa `blocked` para el gate del estado #1. El botón "Ir a la Academia" navega al curso `required_course_id` existente en el módulo de Academia (ya implementado, solo falta la navegación).

---

## ✅ Implementado (2026-07-21) — Decisiones tomadas

`GET /me/punctuality-status` ya existe (`AuthController::punctualityStatus` → `ClockService::getPunctualityStatus`), mismo shape de respuesta que la propuesta. Las dos decisiones pendientes:

### 1. `system_settings.punctuality_course_id`, no `course_type = 'punctuality'`

`academy_courses.course_type` es un `enum` estricto en la BD (`induction|training|promotion|recertification`) — agregar un valor nuevo exige alterar el tipo enum en Postgres, una migración más invasiva de lo necesario. Además conceptualmente son cosas distintas: `course_type` clasifica el *contenido* del curso; lo que necesitábamos era una *referencia* de configuración ("qué curso usa este tenant para destrabar el bloqueo"), que es exactamente el patrón que `system_settings` ya usa en todo el proyecto (`clockOpConfig`, `time_mode`, etc.).

**No hace falta ningún endpoint nuevo para configurarlo** — `POST /sync/settings` (ya existe, `ClockController::syncSettings`) acepta `{ "key": "punctuality_course_id", "value": 4 }` y lo guarda tal cual. Un tenant incluso puede reutilizar un curso `induction`/`training` que ya tenga (el curso semilla "LFT: Derechos y Límites" ya cubre "regulaciones sobre retardos" — candidato natural).

*Aviso aparte, no se tocó:* `POST /sync/settings` hoy vive en el grupo de middleware `role:empleado,employee,admin,supervisor,platform_admin` — cualquier colaborador autenticado podría reescribir configuración de todo el tenant (incluyendo `punctuality_course_id`), no solo administradores. Es un problema de permisos preexistente, no introducido por esta ronda; lo señalo por si quieren priorizarlo en algún momento, no lo corregí ahora porque no era el pedido.

### 2. El contador NO se reinicia por periodo de nómina

Se implementó la recomendación del documento: el bloqueo es un tema de conducta/capacitación, no de nómina, así que reiniciar cada semana lo volvería trivial de evadir (esperar al lunes). El contador solo se reinicia cuando el empleado completa (o **vuelve a completar**, si retoma el mismo curso) el curso de puntualidad configurado — `period_start` en la respuesta es la fecha de esa última finalización (`null` si nunca lo ha completado, es decir cuenta desde siempre). `course_completed` refleja el hecho histórico de si ya lo completó alguna vez; `blocked`/`lates_count` se recalculan solo sobre retardos posteriores a esa fecha, así que un empleado puede completar el curso, volver a acumular 3 retardos después, y quedar bloqueado de nuevo (tiene que retomar el curso otra vez — `user_course_progress` tiene `unique(user_id, course_id)`, así que "retomar" es actualizar el mismo registro con un `completed_at` nuevo, no crear uno segundo).

Se excluyen del conteo las fechas con `ContingencyDeclaration` activa, mismo criterio que ya usa `calculatePayrollForEmployee`.

Tests: `PunctualityStatusTest` (5 casos — bloqueo a los 3 retardos, no bloqueo con menos, desbloqueo al completar el curso, re-bloqueo tras nuevos retardos post-finalización, exclusión por contingencia). Suite completa: 72/72 verde.

### Pedido de Francisco (2026-07-21): elegir el curso desde Configuración del Reloj Checador

El curso de puntualidad vive en el módulo de Academia, así que debe poder elegirse desde las configuraciones del Reloj Checador dentro del ecosistema — no como una llamada de API suelta. **Buenas noticias: no hace falta ningún endpoint nuevo, ya existen los 3 que se necesitan.** Receta completa para el selector en el panel de configuración:

1. **Listar cursos disponibles** (para el `<select>`/dropdown): `GET /academy/courses` (ya existe, `AcademyController::getCourses`) → `response.courses` trae `{id, title, course_type, ...}` de todos los cursos del tenant. No filtres por `course_type` — cualquier curso existente (incluso uno de inducción) es válido como curso de puntualidad, ver la nota de la sección anterior.
2. **Leer el valor actual** (para preseleccionar la opción): ya viene incluido en `system_settings` dentro de la respuesta de `GET /sync/state` que el dialer ya consume — es la llave `punctuality_course_id`. Si no está presente, no hay curso configurado todavía (mostrar el selector vacío/"Sin configurar").
3. **Guardar la selección**: `POST /sync/settings` con body `{ "key": "punctuality_course_id", "value": <course_id> }`.

**Cambio de permisos que acompaña este pedido:** `/sync/settings` vivía en el grupo de middleware de "cualquier colaborador autenticado" (`role:empleado,employee,admin,supervisor,platform_admin`) — cualquier empleado podía reescribir la configuración de todo el tenant, no solo quien administra. Ya que este pedido lo convierte en una función de configuración administrativa real, lo moví al grupo `role:admin,supervisor` (mismo grupo donde ya viven `/sync/rbac` y `/sync/role-policies/{id}`). Revisé los 2 usos actuales de este endpoint en el frontend (`useAppStore.ts` función genérica de settings, y los toggles de adopción de módulos ATS/Academia/Reportes en `DashboardTalent360.tsx`) — ambos ya se llaman solo desde paneles de administración, así que este cambio no debería romper ningún flujo existente. Si el selector de curso de puntualidad se va a colocar en una pantalla que un rol `supervisor` no debería tocar (solo `admin`), avisen y lo hago aún más estricto.

Test nuevo: `SyncSettingsPermissionTest` (empleado normal → 403, admin → 200). Suite completa: 74/74 verde.

**Ajuste fino (2026-07-21):** Francisco confirmó que el selector de curso de puntualidad debe ser exclusivo de `admin`, sin incluir a `supervisor` (que sí conserva acceso al resto de `/sync/settings`, ej. `clockOpConfig`, `timezone`, adopción de módulos). En vez de crear un endpoint paralelo, `ClockController::syncSettings` ahora tiene una lista `ADMIN_ONLY_SETTING_KEYS = ['punctuality_course_id']`: si la llave está en esa lista y quien llama no es `admin`/`platform_admin`, responde `403` sin importar que el rol tenga acceso general a la ruta. También valida que el `course_id` enviado exista y pertenezca al tenant (`422` si no). Cowork no necesita cambiar nada del lado de la llamada — sigue siendo el mismo `POST /sync/settings { key: 'punctuality_course_id', value }`, solo que ahora el backend es más estricto sobre quién puede tocar esa llave específica.

Tests actualizados en `SyncSettingsPermissionTest`: supervisor puede escribir otras llaves pero no `punctuality_course_id` (403), admin no puede apuntar a un curso inexistente (422). Suite completa: 76/76 verde.

---

## 13. Simulador Matrix — Aislamiento de Datos de Prueba (Sesiones de Simulación)

**Contexto (2026-07-21, pedido de Francisco):** el Simulador Matrix (`PanelSimulador.tsx`) genera fichajes de prueba para varios "celulares" simulados. Hoy esos fichajes se guardan exactamente igual que un fichaje real — mismas tablas, mismo `tenant_id`, sin ninguna marca que los distinga — y por eso existe el botón "Limpiar" (`ClockController::resetDb`), que hoy borra `TRUNCATE` de 5 tablas **sin filtrar ni por tenant ni por origen del dato**.

Esto es un problema en dos niveles: (1) borra datos de todas las empresas de la plataforma, no solo la que se está probando (ya señalado en `routes/api.php` línea 118); (2) incluso acotado por tenant, si el mismo tenant tiene empleados reales fichando Y alguien corre el simulador ahí (como hace Francisco hoy con su propia empresa), un `DELETE WHERE tenant_id = X` seguiría borrando fichajes reales junto con los de prueba. Necesitamos distinguir **origen del dato**, no solo empresa.

Requisitos confirmados por Francisco:
- Los datos del simulador deben **prevalecer** (no autoborrarse) para poder usarse en reportes de prueba — hasta que él decida purgarlos manualmente o deshabilitar el módulo.
- Cada vez que se abre una nueva sesión de la Matrix, debe poder seguir generando fichajes sin chocar con la restricción `UNIQUE(user_id, date, type)` (hoy choca porque todo se guarda con la fecha real del servidor, sin importar cuántas veces se corra el simulador el mismo día).
- Los datos simulados **nunca** deben mezclarse con los reportes reales de nómina/asistencia — pero sí deben poder verse en algún tipo de "reporte de prueba" para que Francisco pueda validar que el módulo de reportes funciona bien con datos simulados.
- El mecanismo debe funcionar igual para Francisco probando su propia empresa que para un tenant futuro que rente la plataforma y quiera un simulador para probarla — o sea, aislado por tenant desde el diseño, no un hack de un solo uso.

### Propuesta: tabla `simulator_sessions` + `simulation_session_id`

```php
Schema::create('simulator_sessions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
    $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->date('simulated_date'); // la fecha "de mentiras" que usa esta sesión, no la fecha real
    $table->enum('status', ['active', 'closed'])->default('active');
    $table->timestamps();
});
```

Agregar `simulation_session_id` (nullable, FK a `simulator_sessions`) a `time_entries`, `store_logs`, `contingencies`, `internal_messages` y `audit_logs`. `NULL` significa "dato real"; cualquier valor no nulo significa "dato de una sesión de simulación específica". Se prefiere esto sobre un simple booleano `is_simulator` porque agrupa todos los registros de una misma corrida (permite purgar una sesión completa, o consultar "solo la última corrida") y porque ahí mismo vive el avance de fecha que pide Francisco.

### Lógica de sesión

Al abrir la Matrix (`PanelSimulador.tsx` al montar, o al presionar "Iniciar Nueva Sesión" — reemplaza al botón "Limpiar" actual):
1. Buscar la sesión `active` del tenant. Si existe, reutilizarla (mismo `simulated_date` para toda la sesión en curso).
2. Si no existe, crear una nueva: `simulated_date = (última sesión del tenant, si existe)->simulated_date + 1 día`, o la fecha real de hoy si es la primera sesión de ese tenant en toda su historia.
3. Todo fichaje/registro que el simulador dispare durante esa sesión (vía `/clock/punch`, `/clock/punch-batch`, `/store-opening/*`, etc., cuando `details.is_simulator === true`) se guarda con `simulation_session_id` = el de la sesión activa, y usando `simulated_date` como el valor de la columna `date` — no la fecha real del servidor. Esto es lo que evita el choque con `UNIQUE(user_id, date, type)` entre sesiones sucesivas, sin borrar nada.
4. `ClockService::processPunch()` ya tiene la bandera `$isSimulator` (línea ~186) — solo hace falta que además de saltar la validación de GPS, también fije `date` a `simulated_date` de la sesión activa y guarde `simulation_session_id` en el `INSERT`.

### Reportes reales vs. reportes de prueba

- **Reportes reales de nómina/asistencia** (los que ya existen): agregar `whereNull('simulation_session_id')` como filtro estructural — idealmente como *global scope* en los modelos `TimeEntry`, `StoreLog`, `Contingency`, no como un `WHERE` que cada reporte nuevo tenga que acordarse de poner. Así es imposible que un reporte futuro filtre por accidente y mezcle datos de prueba con nómina real.
- **"Reportes de Prueba" (nuevo, solo visible dentro del propio Matrix/panel de QA):** la misma lógica de reporte existente, pero parametrizada para incluir explícitamente una `simulation_session_id` (`withoutGlobalScope` + filtro `where('simulation_session_id', $id)`). Con esto Francisco puede verificar que el cálculo de retardos/faltas/bonos funciona bien usando datos simulados, sin que eso jamás toque un número real. Sugiero reutilizar el mismo endpoint de reportes que ya exista, agregando un parámetro opcional `simulation_session_id` que solo acepten roles `platform_admin`/`admin` en contexto de simulador.

### Purga

- **Purgar una sesión:** `DELETE ... WHERE simulation_session_id = X` en las 5 tablas — seguro por construcción, nunca toca filas con `simulation_session_id IS NULL`.
- **Purgar todo el histórico de simulador de un tenant:** mismo criterio con `WHERE simulation_session_id IN (SELECT id FROM simulator_sessions WHERE tenant_id = X)`.
- El botón "Limpiar" actual (`ClockController::resetDb`) puede reescribirse para hacer exactamente esto en vez de `TRUNCATE`, y renombrarse en el frontend a algo como "Purgar Datos de Prueba" (acción explícita y poco frecuente) separado de "Iniciar Nueva Sesión" (la acción normal de cada vez que se usa la Matrix, que ya no necesita borrar nada).

### Fuera de alcance de esta sección (anotado, no se pide ahora)

Francisco también mencionó que cambios de configuración que un usuario esté probando no deberían reflejarse en el PWA de empleados reales "hasta que se guarden/apliquen". Eso es un tema distinto (staging/borrador de `system_settings` vs. configuración publicada), no relacionado con el aislamiento de fichajes del simulador — lo dejamos anotado como posible trabajo futuro, no se diseña en esta sección.

---

## ✅ Implementado (2026-07-21) — Contrato final para PanelSimulador.tsx

Todo lo diseñado arriba ya está construido tal cual se propuso: tabla `simulator_sessions`, columna `simulation_session_id` en las 5 tablas, `ExcludeSimulationScope` (global scope vía trait `ExcludesSimulationData`, aplicado a `TimeEntry`, `StoreLog`, `Contingency`, `InternalMessage`, `AuditLog`), avance de `simulated_date` por sesión, y purga acotada reemplazando el `TRUNCATE` de `resetDb`.

### 1. Marcar `is_simulator=true` — sin cambios de payload

`ClockService::processPunch()` ya leía `details.is_simulator` (para el bypass de GPS). Ahora, además, cuando ese flag viene en `true`:
- Resuelve (o crea) la sesión activa del tenant.
- Usa `simulated_date` de esa sesión como el valor de `date` del registro — **no** la fecha real del servidor.
- Guarda `simulation_session_id` en el `INSERT` de `time_entries` y `audit_logs`.

**No cambia nada del payload que ya manda el frontend** — `POST /clock/punch` y `/clock/punch-batch` siguen recibiendo exactamente `{ user_id, type, time, details: { is_simulator: true, ... } }` como ya lo hacen hoy. Todo el enlace a la sesión ocurre server-side.

### 2. Endpoints de sesión (nuevos)

```
GET /api/v1/matrix/session/active
```
Devuelve la sesión activa del tenant, **creándola si no existe**. Es lo que `PanelSimulador.tsx` debe llamar al montar.
```json
{ "success": true, "session_id": 7, "simulated_date": "2026-07-22", "status": "active" }
```

```
POST /api/v1/matrix/session/new
```
Cierra la sesión activa (si existe) y crea una nueva con `simulated_date + 1 día`. **Reemplaza al botón "Limpiar" actual** — nunca borra nada, solo avanza de día para que la Matrix pueda seguir generando fichajes sin chocar con `UNIQUE(user_id, date, type)`. Mismo shape de respuesta que el anterior.

Ambos requieren rol `admin`, `supervisor` o `platform_admin` (`tenant.active`).

### 3. Purga — `POST /api/v1/sync/reset` (reescrito)

Ya no es un `TRUNCATE`. Body opcional:
```json
{ "session_id": 7 }
```
Sin `session_id`, purga **todas** las sesiones de simulador del tenant. Con `session_id`, purga solo esa. Por construcción nunca toca filas con `simulation_session_id NULL` (datos reales) — es el botón que en frontend debería renombrarse a **"Purgar Datos de Prueba"**, separado de "Iniciar Nueva Sesión".
```json
{ "success": true, "message": "Datos de simulación purgados correctamente.", "purged_sessions": 1, "deleted_rows": 12 }
```

**Cambio de permisos:** antes vivía en el grupo `platform_admin`-only junto a `/sync/init` (con razón: hacía `TRUNCATE` de 5 tablas sin filtrar por tenant, afectando a toda la plataforma). Ahora que está scopeado por `simulation_session_id` + tenant, es seguro para `admin`/`supervisor` de la propia empresa — que es justo el caso de uso de Francisco probando su propia empresa. También dejó de deshabilitarse en `APP_ENV=production`, porque ya no hay nada peligroso que deshabilitar. **`/sync/init` no se tocó** — sigue siendo `platform_admin`-only y deshabilitado en producción, porque hace `TRUNCATE` de `employees`/`job_roles`/`permissions` sin relación con el simulador; es un problema aparte que esta sección no resuelve.

### 4. Aislamiento de reportes reales — qué quedó cubierto y qué no

- **Cubierto automáticamente** (Eloquent con `ExcludeSimulationScope`): cualquier código que use `TimeEntry::`, `StoreLog::`, `Contingency::`, `InternalMessage::`, `AuditLog::` — incluye `ClockService::calculatePayrollForEmployee` (nómina real).
- **Parchado manualmente** (consultas `DB::table()` crudas, que el scope de Eloquent no cubre): `ClockController::getState` (el "todo en uno" que alimenta el dialer y monitor en vivo — 6 queries), `DashboardController::getStats`, `DashboardMonitorController::getMonitorData` (feed de actividad, chat). Todas ahora llevan `whereNull('simulation_session_id')`.
- **Fuera de alcance, no parchado (aviso explícito, no silencioso):** `PlatformAdminController` (estadísticas agregadas de toda la plataforma, solo para `platform_admin`, no son "reportes de la empresa") y `MigrateLegacySqlite.php` (comando de consola de migración única, no un reporte). Si en algún momento importa que estas también excluyan datos simulados, avisen y lo agrego.

### 5. "Reportes de Prueba"

Reutiliza el endpoint de nómina que ya existe en vez de crear uno nuevo: `GET /admin/payroll?simulation_session_id=7` (mismo endpoint de siempre, parámetro nuevo opcional). Sin el parámetro, se comporta exactamente igual que hoy (datos reales). Con él, **exclusivo para `admin`/`platform_admin`** (403 para cualquier otro rol, incluido `supervisor`), calcula la nómina usando *solo* los fichajes de esa sesión de simulador.

### Tests

`SimulatorSessionIsolationTest` (7 casos: sesión se crea y reutiliza, nueva sesión cierra la anterior y avanza fecha, fichaje simulado queda ligado a la sesión activa, sesiones sucesivas nunca chocan contra el índice único, el scope excluye datos simulados por default, la purga borra solo lo simulado, nómina real vs. nómina de prueba). Más ajustes en `RoleMiddlewareTest` reflejando el nuevo comportamiento de `/sync/reset`. Suite completa: 85/85 verde.

---

## 14. Módulo de Tareas — Correcciones de Fase 1 (Auditoría Jul 2026)

**Contexto:** Francisco pidió una auditoría completa del módulo de Tareas antes de rediseñar el frontend (formulario de creación, pestaña de tareas del colaborador, centro de mando). Se encontraron 3 bugs concretos del lado del servidor que bloquean o distorsionan ese rediseño. El frontend (yo) ya está listo para consumir las correcciones — no hace falta coordinar nada más con Frontend, solo aplicar esto.

### 14.1 Poblar `date` y `points_awarded` en `TaskAssignment` (columnas ya existen, nunca se llenan)

La migración `2026_06_30_190000_add_date_and_points_to_task_assignments_table.php` ya agregó `date` (date, nullable) y `points_awarded` (integer, default 0) a `task_assignments`. Ningún código las está poblando hoy:

- `TaskSyncController::sync()`, el array `$mappedData` (líneas ~137-149): falta `'date' => ...` (usar la fecha de la sesión/hoy, `Carbon::today()->toDateString()`, salvo que ya traigan una fecha explícita del payload) y `'points_awarded' => ...` cuando `status` pase a `'completed'` (tomar `Task::points` en ese momento, salvo que haya alguna bonificación de bolsa de trabajo que deba sumarse aparte).
- `DashboardMonitorController::createTask()`, el `TaskAssignment::create([...])` en línea ~387-393: falta `'date' => Carbon::today()->toDateString()`.
- Revisar también `DashboardMonitorController::assignTask()` por el mismo patrón.

Motivo: sin `date`, el historial de "tareas completadas hoy" del colaborador no se puede acotar a hoy (hoy muestra todo el histórico sin límite) y las rutinas recurrentes no pueden distinguir bien entre ejecuciones de distintos días. El frontend (`TaskAssignment` en `useTaskStore.ts`) ya tiene los campos `date?` y `pointsAwarded?` como opcionales — tolera perfectamente que vengan `undefined` en filas viejas, así que esto se puede desplegar sin migración de datos históricos.

### 14.2 Filtro `tenant_id` + `date` faltante en `DashboardMonitorController::getMonitorData()`

Línea ~46-49:
```php
$activeAssignments = TaskAssignment::with('task')
    ->whereIn('status', ['in_progress', 'paused'])
    ->get()
    ->groupBy('user_id');
```
No filtra por tenant ni por fecha — en un entorno multi-tenant esto puede traer al monitor en tiempo real de una empresa tareas activas de OTRA empresa, y además nunca se acota a "hoy". Justo arriba, la consulta `$completedStats` (línea ~52-64) sí hace bien este patrón (join contra `tasks` + `where('tasks.tenant_id', $userTenantId)` + `whereDate(...)`). Pedimos replicar exactamente ese patrón en `$activeAssignments`: join con `tasks`, filtrar `tasks.tenant_id = $userTenantId`, y si aplica, `task_assignments.date = $today` (con tolerancia a `NULL` mientras haya filas viejas sin poblar, igual que 14.1).

### 14.3 Consolidar `target_type` en `DashboardMonitorController::createTask()`

Línea 356: la validación solo acepta `'target_type' => 'nullable|string|in:role,user'`, pero el modelo `Task`, `TaskSyncController` y el frontend (`Task.targetType` en `useTaskStore.ts`) ya soportan 4 valores: `role | user | pool | department`. Esta tercera vía de creación de tareas (la que usa el asistente de voz del centro de mando) hoy no puede crear una tarea de bolsa de trabajo ni de departamento. Pedimos alinear la validación y la lógica de asignación (línea ~384-393, hoy solo contempla `user`) para los 4 valores, igual que ya hace `TaskSyncController`.

### Fuera de alcance de esta sección

Unificar las 3 rutas de creación de tareas en una sola (`TaskSyncController` bulk-upsert, `TaskAssignmentController` update puntual, `DashboardMonitorController::createTask` tercera vía) — eso es limpieza de fase 4, después de que 14.1-14.3 estén estables y el frontend termine su rediseño. Se documentará aparte cuando llegue ese momento.

## ✅ Implementado (2026-07-21) — resumen

- **14.1:** `TaskSyncController::sync()` ahora manda `date` (la del payload si viene, si no `Carbon::today()`, o preserva la del registro existente en updates) y `points_awarded = Task::points` en el momento en que el `status` final queda en `completed` (después de aplicar la lógica de validación de supervisor, no antes — un assignment que baja a `awaiting_validation` no recibe puntos todavía). Mismo criterio aplicado en `DashboardMonitorController::assignTask()` y `createTask()`. No hubo que tocar el modelo `TaskAssignment` — `date`/`points_awarded` ya estaban en `$fillable`, solo nadie los mandaba.
- **14.2:** `getMonitorData()`'s `$activeAssignments` ahora usa `whereHas('task', fn($q) => $q->where('tenant_id', $userTenantId))` (equivalente Eloquent del join+filtro que ya usaba `$completedStats`) más tolerancia a `date NULL` para filas viejas.
- **14.3:** `target_type` en `createTask()` ahora acepta `pool`/`department` además de `role`/`user`. No hizo falta tocar la lógica de asignación (`$assignedUserId = ($targetType === 'user') ? $targetId : null`) — ya trataba correctamente cualquier valor distinto de `'user'` como sin asignar, igual que `role`; el único bloqueo real era la validación.
- Tests: `TaskAndSequencePendingItemsTest` (8 casos). Suite completa: 93/93 verde.

---

## 15. Dialer del Reloj Checador — Falta validar secuencia de eventos en `ClockService::processPunch()`

**Contexto (2026-07-21, pedido de Francisco):** auditoría completa del botón Dialer (guardado de eventos, persistencia y secuencia). Se encontraron 2 bugs de hidratación de estado ya corregidos del lado del frontend (`useAppStore.ts::fetchState()` traducía `check_out` a `'inactive'` en vez de `'finished'`, y no manejaba `temp_exit_start`/`temp_exit_end`/`absent`). El tercer hallazgo es de backend y queda documentado aquí para que lo apliquen ustedes.

**El problema:** `ClockService::processPunch()` solo garantiza dos cosas hoy: (a) que cada `type` se registre una sola vez por usuario por día (con el backstop de índice único ya existente, `time_entries_user_date_type_unique` — eso está bien y no se toca), y (b) dos precondiciones de negocio puntuales (`check_out` exige el checklist de cierre; `check_in` en plan Free exige tienda abierta). No valida en ningún punto que el **predecesor lógico** de un evento ya haya ocurrido. Hoy nada impide, a nivel de base de datos, que llegue un `meal_end` sin `meal_start` previo, un `break_end` sin `break_start`, un `temp_exit_end` sin `temp_exit_start`, o un `check_out` sin `check_in` ese mismo día — siempre que cada tipo sea la primera vez que se manda ese día.

La única razón por la que esto no ocurre en la práctica es que el dial del frontend solo ofrece el botón "siguiente correcto" — pero eso es una garantía de UI, no de datos. Los dos bugs de hidratación ya corregidos (arriba) son prueba de que ese estado local sí se puede desincronizar de lo que realmente pasó; sin una validación en el servidor, un dial desincronizado (o un tab viejo, o una segunda sesión en otro dispositivo, o una réplica futura de la cola offline) podría escribir una fila con un orden imposible sin que nada lo detecte, contaminando silenciosamente cálculos de horas trabajadas / nómina más adelante.

**Pedimos:** agregar una validación de prerequisito por tipo dentro de `processPunch()`, antes del bloque de `DB::transaction()` (cerca de la validación anti-duplicados existente, línea ~106-116), con esta tabla:

| `type` recibido | Requiere que YA exista hoy | Bloquear si YA existe hoy |
|---|---|---|
| `check_in` | — | `check_out` (no reabrir un día ya cerrado) |
| `meal_start` | `check_in` | `check_out` |
| `meal_end` | `meal_start` | — |
| `break_start` | `check_in` | `check_out` |
| `break_end` | `break_start` | — |
| `temp_exit_start` | `check_in` | `check_out` |
| `temp_exit_end` | `temp_exit_start` | — |
| `check_out` | `check_in` | — |
| `waiting` | — | — |

Si no se cumple el requisito, lanzar `throw new \Exception(...)` igual que las demás validaciones de esta función (el frontend ya muestra `e.response?.data?.message` en el `catch` de `syncToDB()`, así que cualquier mensaje descriptivo llega al usuario sin cambios adicionales de nuestro lado). Aplica igual para el batch offline (`punchBatch()`) ya que ambos reusan `processPunch()` — como ese endpoint ya reordena por `client_timestamp` antes de procesar (línea ~131-133), la validación de prerequisito debería funcionar de forma natural ahí también sin cambios adicionales.

**Nota:** `contingency` y `absent` no están en la tabla porque no pasan por `processPunch()`/`ALLOWED_TYPES` (contingency usa `declareContingency()`; absent se maneja aparte). Si en algún momento eso cambia, avisen y se agrega su regla aquí.

## ✅ Implementado (2026-07-21) — resumen

Tabla de prerequisitos agregada tal cual, justo después del guard anti-duplicados existente (que no se tocó). Dos observaciones encontradas al implementar, ninguna cambia el comportamiento pedido:

- Para `check_in`, la regla "bloquear si ya existe `check_out`" es en la práctica inalcanzable por un camino distinto al que ustedes ya arreglaron: el guard anti-duplicados (mismo `type` dos veces) siempre dispara primero en un segundo `check_in`, con un mensaje distinto ("ya existe un registro..."). La igualé de todos modos porque no hace daño y dejó la tabla completa por si en el futuro se relaja el anti-duplicados. `meal_start`/`break_start`/`temp_exit_start` sí son los casos donde esta regla importa de verdad (esos types no chocan con el guard anti-duplicados al ser su primer uso del día).
- Aplica igual a `punchBatch()` sin cambios adicionales, como ustedes ya anticipaban — reutiliza `processPunch()` y el batch ya ordena por `client_timestamp` antes de procesar.

Tests: 4 casos nuevos en `TaskAndSequencePendingItemsTest` (meal_end sin meal_start, check_out sin check_in, meal_start después de check_out, secuencia completa válida). Suite completa: 93/93 verde.

---

## 16. Rate Limiting en Endpoints de Fichaje y PIN de Testigos

**Contexto (2026-07-21, pedido de Francisco):** auditoría exhaustiva del módulo completo del Reloj Checador (seguridad, backend, frontend, UX, LFT, testing, rendimiento). En seguridad se detectó que `/clock/punch`, `/clock/punch-batch` y la validación de PIN de testigos en `/clock/emergency-open` no tienen ningún límite de tasa (`throttle`) — hoy el único ejemplo de throttling en todo `routes/api.php` es `throttle:5,1` en `/login`. Las reglas de negocio actuales (anti-duplicados, hash de PIN, firma HMAC) ya evitan que esto derive en datos corruptos, pero no hay nada que frene intentos repetidos de adivinar un PIN de testigo por fuerza bruta, ni un abuso de red golpeando estos endpoints.

**Pedimos:**
- `/clock/punch` y `/clock/punch-batch`: throttle razonable por usuario autenticado — algo como `throttle:20,1` (20 peticiones por minuto) es generoso para uso normal (un empleado no ficha más de un puñado de veces por turno) pero corta un abuso automatizado.
- `/clock/emergency-open` (validación de PIN de testigos en `StoreOpeningService::emergencyOpen` / donde corresponda): algo más estricto dado que protege un PIN, por ejemplo `throttle:5,1` por usuario o por IP — iguales al que ya usan en `/login`, mismo criterio.

Sin preferencia fuerte de nuestro lado sobre el mecanismo exacto (middleware `throttle:` de Laravel debería bastar, igual que `/login`) — lo importante es que quede alguna capa aquí, ya que hoy no hay ninguna.

## ✅ Implementado (2026-07-21) — resumen

Exactamente el mecanismo sugerido, mismo patrón que `/login`: `Route::middleware('throttle:20,1')->post('/clock/punch', ...)` (y `punch-batch`), `Route::middleware('throttle:5,1')->post('/clock/emergency-open', ...)`. El limitador de Laravel usa el `id` del usuario autenticado como clave cuando la petición ya pasó `auth:sanctum` (ambas rutas lo requieren), así que es por-usuario, no por IP compartida. No toqué `/clock/offline-secret` ni `/clock/declare-contingency` — no los pidieron y no tienen el mismo perfil de riesgo (ni fuerza bruta de PIN, ni escritura masiva repetible).

Tests: `ClockThrottleTest` (2 casos — 20 peticiones pasan y la 21 devuelve 429 en `/clock/punch`; mismo patrón con 5/6 en `/clock/emergency-open`). Suite completa: 95/95 verde.

---

## 20. Nuevo evento `TimeEntryRecorded` — migración de polling a WebSockets

**Contexto (2026-07-21, tarea de Cowork):** el frontend sincronizaba el estado del dial de TODOS los empleados vía `setInterval(fetchState, 5000)` — un poll cada 5 segundos, para todos los usuarios conectados, incluso sin ningún cambio real. Ya reestructuramos ese lado (bajamos el intervalo a 60s como red de seguridad) y agregamos un listener de WebSocket en el mismo canal público que ya usa `StoreOpened` (`tenant.{id}.clock`, ver `useClockEngine.tsx`), pero necesitamos que Backend emita el evento — hoy no existe ningún broadcast al registrar un fichaje.

**Pedimos:** un nuevo evento `App\Events\TimeEntryRecorded` (mismo patrón que `App\Events\StoreOpened`, que ya revisamos como referencia):

```php
class TimeEntryRecorded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $tenantId,
        public int $userId,
        public string $type,   // 'check_in', 'check_out', 'break_start', etc.
        public string $time,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('tenant.' . $this->tenantId . '.clock')];
    }

    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'type' => $this->type,
            'time' => $this->time,
        ];
    }
}
```

**Dónde emitirlo:** al final de `ClockService::processPunch()`, justo después de que el fichaje se guarda exitosamente (no en rechazos/errores de validación). Confirmamos que `processPunch()` es el único chokepoint real — lo usan `TimeEntryController::punch()`, `TimeEntryController::punchBatch()` (una vez por cada ítem válido del batch) y `StoreOpeningService` (apertura normal y de emergencia) — así que un solo punto de emisión ahí cubre los 4 caminos sin duplicar el `event(new ...)` en cada controlador.

**Frontend ya está listo para consumirlo:** `useClockEngine.tsx` ya tiene `channel.listen('.App\\Events\\TimeEntryRecorded', ...)` agregado (mismo canal, misma suscripción que `StoreOpened`) — simplemente no dispara hasta que este evento exista del lado de Laravel. No se necesita ningún cambio adicional de frontend una vez que Backend lo implemente.

**Nota de payload:** no incluimos más que `user_id`/`type`/`time` a propósito — el listener del frontend solo dispara `fetchState()` al recibir el evento (vuelve a pedir `/sync/state` completo), no reconstruye el estado a partir del payload del evento. Si más adelante se quiere optimizar para no repetir la consulta completa por cada fichaje, se puede enriquecer el payload — pero eso es una optimización futura, no un requisito de esta tarea.

## ✅ Implementado (2026-07-21) — resumen

`App\Events\TimeEntryRecorded` creado exactamente como lo especificaron (propiedades promovidas, mismo canal `tenant.{id}.clock` que `StoreOpened`, sin `broadcastAs()` para que el nombre por defecto siga siendo `App\Events\TimeEntryRecorded` — coincide con el listener `.App\\Events\\TimeEntryRecorded` que ya tienen en `useClockEngine.tsx`). Se dispara en un único punto: justo después de que `DB::transaction()` confirma el `INSERT` en `ClockService::processPunch()`, nunca dentro de la transacción (para no notificar algo que todavía podría revertirse) ni en ningún `throw` de validación anterior. Confirmé que cubre los 4 caminos que mencionan sin tocarlos: `TimeEntryController::punch()`, `punchBatch()` (una vez por ítem válido — lo verifiqué con un test de batch), `StoreOpeningService::openStoreAndClockIn()` y `emergencyOpenWithWitnesses()` — los 4 pasan por `processPunch()`.

Tests: `TimeEntryRecordedEventTest` (3 casos — payload correcto en éxito, no se dispara en un rechazo por secuencia inválida, se dispara exactamente 1 vez por ítem válido en un batch). Suite completa: 98/98 verde.

---

## 21. Validación de ciclos para `reports_to_role_ids` en `JobRoleController`

**Contexto (2026-07-21, organigrama interactivo de puestos):** construimos un organigrama interactivo (`Frontend/src/components/OrganigramaPuestos.tsx`, usando `@xyflow/react`) donde el usuario dibuja o borra conexiones de jerarquía directamente arrastrando entre dos tarjetas de puesto, en vez de usar los checkboxes que había antes en el modal "Ficha del Puesto". Esto aplica a dos campos de `JobRole`:

- `org_parent_role_id` (un solo padre, el árbol visual) — **ya tiene validación de ciclos en el backend** (`wouldCreateOrgCycle()` en `JobRoleController::update()`), la reutilizamos tal cual del lado del cliente.
- `reports_to_role_ids` (array, puede haber varios padres — la jerarquía operativa real, "Reporta A") — **no tiene ninguna validación de ciclos hoy**, ni en frontend (antes) ni en backend. Ya agregamos la validación del lado del cliente en `OrganigramaPuestos.tsx` (`wouldCreateReportaCycle`, un BFS que seguía el grafo de `reports_to_role_ids`), pero como es un campo que ahora se edita de forma mucho más rápida e interactiva que antes (arrastrar una línea vs. marcar un checkbox con calma), el riesgo de que alguien cree un ciclo por error subió — y hoy nada en el servidor lo detendría si el cliente fallara o alguien pegara el request directo.

**Pedimos:** el mismo tipo de chequeo que ya existe para `org_parent_role_id`, pero adaptado a que `reports_to_role_ids` es un array (puede tener más de un "padre", así que no es una cadena simple sino un grafo dirigido — hace falta un BFS/DFS, no un `while` de un solo camino). Algo equivalente a esto en `JobRoleController::update()`:

```php
private function wouldCreateReportsToCycle(int $tenantId, int $sourceId, int $targetId): bool
{
    if ($sourceId === $targetId) {
        return true;
    }

    $queue = [$targetId];
    $visited = [];

    while (!empty($queue)) {
        $currentId = array_shift($queue);
        if (in_array($currentId, $visited, true)) {
            continue;
        }
        $visited[] = $currentId;

        if ($currentId === $sourceId) {
            return true;
        }

        $current = JobRole::where('tenant_id', $tenantId)->find($currentId);
        foreach (($current->reports_to_role_ids ?? []) as $nextId) {
            $queue[] = (int) $nextId;
        }
    }

    return false;
}
```

Se llamaría por cada id nuevo que se agregue a `reports_to_role_ids` en la validación del `update()` (comparando el id del puesto que se está editando como `$sourceId` contra cada `$targetId` del array entrante), devolviendo un 422 con mensaje claro si algún id crearía un ciclo — mismo formato de error que ya usan para `org_parent_role_id`.

Sin preferencia fuerte de nuestro lado sobre el nombre exacto del método o si se hace como método privado del controller o se mueve a un service — lo importante es que quede la validación, ya que hoy el único guardarraíl es del lado del cliente.

## ✅ Implementado (2026-07-21) — resumen

`wouldCreateReportsToCycle()` agregado como método privado de `JobRoleController` (mismo lugar que `wouldCreateOrgCycle`), con la firma y el BFS que propusieron, más el filtro `tenant_id` en cada `JobRole::find()` del recorrido. Se llama una vez por cada id en `reports_to_role_ids` dentro de `update()`, justo después de la validación de ciclo de `org_parent_role_id` existente. Mensaje de error 422 en el mismo formato que ya usan. No hizo falta tocar `store()` — un puesto recién creado no puede formar parte de un ciclo porque nada puede apuntar todavía a un id que no existe.

Tests: 2 casos en `OrgCycleRatingsMealPhotoTest` (ciclo rechazado, cadena válida sin ciclo aceptada). Suite completa: 106/106 verde.

---

## 22. Calificación en Pase de Lista (Presentación / Imagen / Energía) — Estado #8 del dial

**Contexto (2026-07-21, `docs/Logica Dial.md`):** al abrir la tienda (estado #8), el encargado hace el pase de lista de los presentes. Hoy `handleSubmitPaseLista()` en `useClockEngine.tsx` **solo registra `check_in`** de cada empleado marcado como presente. El documento pide además calificar a cada colaborador con estrellas (1–5) en tres ejes: **Presentación (uniforme)**, **Imagen (aseo)** y **Energía (actitud)**. Frontend agregará los tres controles de estrellas al modal; falta dónde persistirlo.

**Pedimos:**

Nueva tabla `pase_lista_ratings` (o el nombre que prefieran), con al menos:

```
id, tenant_id, employee_id (a quién se califica), rated_by_employee_id (encargado),
date (Y-m-d), presentacion (tinyint 1-5), imagen (tinyint 1-5), energia (tinyint 1-5),
created_at
```

Endpoint para guardarlas en lote (una llamada con todos los presentes calificados):

```
POST /api/clock/pase-lista/ratings
{
  "date": "2026-07-21",
  "ratings": [
    { "employee_id": 11, "presentacion": 5, "imagen": 4, "energia": 5 },
    { "employee_id": 12, "presentacion": 3, "imagen": 3, "energia": 4 }
  ]
}
```

- Solo el encargado responsable de la apertura del día (o admin/supervisor) puede llamarlo — validar contra el `current_responsible_employee_id` del `store_daily_opening_status` o el rol, como ya se hace en otros endpoints de apertura.
- Idempotente por `(tenant_id, employee_id, date)`: si ya existe calificación de ese empleado ese día, se actualiza en vez de duplicar.
- El `check_in` de los presentes se sigue registrando por el flujo actual (`/clock/punch`), **este endpoint es solo la calificación** — no mezclar ambas cosas.
- Config: habrá un switch `require_pase_lista_rating` en `clockOpConfig` (lado frontend). Cuando esté apagado, el frontend simplemente no llama a este endpoint. El backend no necesita conocer el switch.

**Uso futuro:** estas calificaciones alimentarán métricas de clima/desempeño en el Dashboard; por ahora solo persistirlas.

## ✅ Implementado (2026-07-21) — resumen

Tabla `pase_lista_ratings`, modelo `PaseListaRating`, lógica en `StoreOpeningService::submitPaseListaRatings()` (mismo patrón que `submitClosingChecklist`), endpoint en `StoreOpeningController::submitPaseListaRatings` → `POST /clock/pase-lista/ratings`. Permiso validado igual que en el resto de endpoints de apertura: `current_responsible_employee_id` del `store_daily_opening_status` del día, o rol `admin`/`supervisor`/`platform_admin`. Idempotente por `(tenant_id, employee_id, date)` vía `updateOrCreate`.

- **Decisión sobre `employee_id`:** apunta a `users.id`, no a `employees.id`. El payload de ejemplo no lo aclaraba, pero es el mismo identificador que ya usa `/clock/punch` y todo el módulo de reloj — usar `employees.id` ahí hubiera repetido exactamente el bug que ya encontramos y corregimos en `store_opening_assignments`/`KeyTransferController` (sección 10.1).
- **Gotcha de Eloquent que vale la pena que sepan si tocan este código:** el modelo NO tiene cast `'date' => 'date'` a propósito. Con el cast, `updateOrCreate()` guardaba el segundo `INSERT` con la fecha desfasada un día (`"2026-07-22 00:00:00"` en vez de `"2026-07-21"`), porque el cast serializa como datetime completo y el WHERE de búsqueda dejaba de coincidir con lo ya guardado — terminaba disparando el índice único en vez de actualizar. Es el mismo motivo por el que `TimeEntry`/`StoreLog` en este proyecto tampoco castean su columna `date`; seguimos esa misma convención aquí y en `MealPhotoEvidence`.

Tests: 2 casos (idempotencia confirmada con recalificación, encargado no-responsable rechazado). Suite completa: 106/106 verde.

---

## 23. Evidencia Fotográfica de Comedor (inicio y fin de comida) — Estados #17 y #18b

**Contexto (2026-07-21, `docs/Logica Dial.md`):** al **iniciar** comida (estado #17) el sistema exige una foto del comedor limpio; al **terminar** (estado #18b), otra foto del comedor limpio. Sin la foto, el estado no avanza. Hoy no existe ninguna captura ni almacenamiento de imágenes en el flujo de comida. La PWA ya tiene permisos de cámara (se usan para validación facial), así que la captura del lado cliente es reutilizable; falta el almacenamiento.

**Pedimos:**

Aceptar una imagen (base64 o multipart, lo que prefieran; el frontend se adapta) asociada al fichaje de comida, en dos momentos:

```
POST /api/clock/meal-photo
{
  "type": "meal_start" | "meal_end",
  "date": "2026-07-21",
  "image": "data:image/jpeg;base64,...",   // el frontend comprime antes de enviar
  "client_timestamp": "2026-07-21T13:05:00-06:00"
}
```

- Guardar la imagen en el storage que usen (disco/S3), y una fila que la ligue a `employee_id + tenant_id + date + type + url`. Sugerencia: tabla `meal_photo_evidences`.
- **Ojo con el peso:** son 2 fotos por empleado por día. El frontend comprimirá (probablemente ≤ 200 KB c/u), pero conviene que definan **política de retención** (p. ej. purgar > 90 días) para que no crezca sin límite. Dejamos la decisión de retención de su lado.
- Devolver `{ "success": true, "url": "..." }`. El frontend NO bloquea el avance del estado si el switch de evidencia está apagado; cuando está encendido, sí exige el 200 antes de continuar.
- Config: switch `require_meal_photo_evidence` en `clockOpConfig` (lado frontend). El backend solo recibe la foto cuando el frontend decide enviarla.

## ✅ Implementado (2026-07-21) — resumen

`POST /clock/meal-photo` en `TimeEntryController::uploadMealPhoto`, exactamente el payload base64 que proponían (`data:image/{ext};base64,...`). Decisiones tomadas donde el pedido daba libertad:

- **base64 sobre multipart:** el pedido dejaba elegir; base64 encaja mejor con que "la captura del lado cliente es reutilizable" de la validación facial (que ya deben tener como data URI) y con el ejemplo de payload que ustedes mismos escribieron.
- **Guardado en disco**, mismo patrón que `AuthController::uploadAvatar` (el único precedente de subida de archivos en el proyecto): `public_path('uploads/meal-evidence/{tenant_id}')`, nombre de archivo con `type + user_id + timestamp + random`. La fila guarda `url` (pública) y `path` (física, para poder purgar).
- **Formatos aceptados:** jpg/png/webp. **Límite defensivo de 2MB** por imagen (el frontend comprime a ~200KB; esto solo corta un abuso, no es la compresión esperada).
- **Política de retención (la dejaban a nuestro criterio):** 90 días. Implementé un comando `php artisan meal-evidence:purge {--days=90}` que borra archivo + fila. **No lo programé solo** — no sabemos qué mecanismo de cron/scheduler usan en el servidor real; hay que agregarlo al scheduler de Laravel (`bootstrap/app.php` → `withSchedule`) o a un cron del sistema con la periodicidad que prefieran (sugerido: diario).
- Mismo gotcha del cast `'date'` que en §22 — `MealPhotoEvidence` tampoco lo castea, por la misma razón.

Tests: 4 casos (subida exitosa con archivo verificado en disco, formato inválido rechazado, imagen de más de 2MB rechazada, comando de purga borra solo lo vencido). Suite completa: 106/106 verde.

---

## 24. Cola Secuencial de Reserva de Comida ("Apartar Turno") — Estado #16b

**Contexto (2026-07-21, `docs/Logica Dial.md`):** el documento describe la reserva de comida como una **cola secuencial** que se dispara ~20 min antes de la ventana de comida (ej. 10:10): los colaboradores eligen su slot **uno a uno**, en un orden (por hora de llegada o aleatorio), y **el dialer del siguiente se habilita solo cuando el anterior ya eligió**. Hoy `MealReservation.tsx` + `/meal-reservations/slots` es **selección libre** (cualquiera reserva cualquier slot disponible, con bloqueo por mismo puesto), sin cola ni orden.

**⚠️ Decisión de producto abierta (Francisco):** aún no se decide si la cola **reemplaza** la selección libre actual o **convive** como un modo opcional configurable. La propuesta de contrato de abajo asume **convivencia** (un modo `meal_reservation_mode: 'free' | 'queue'` por sucursal), que es lo menos destructivo. Si se decide reemplazar, se elimina el modo `free`.

**Pedimos (si/ cuando se apruebe el modo `queue`):**

Un recurso de "ronda de selección" por sucursal y día, que exponga a quién le toca elegir:

```
GET /api/meal-reservations/queue?date=2026-07-21
→ {
    "mode": "queue",
    "order_by": "arrival" | "random",
    "current_turn_employee_id": 12,      // a quién le toca elegir ahora (null si terminó la ronda)
    "queue": [
      { "employee_id": 11, "status": "done",     "slot_start": "11:15", "slot_end": "12:00" },
      { "employee_id": 12, "status": "choosing" },
      { "employee_id": 13, "status": "waiting" }
    ]
  }
```

```
POST /api/meal-reservations/queue/pick
{ "date": "2026-07-21", "slot_start": "12:00", "slot_end": "12:45" }
```

- Al hacer `pick`, el backend valida que **sea el turno de ese empleado** (`current_turn_employee_id`), registra su slot, y avanza `current_turn_employee_id` al siguiente `waiting`. Si no es su turno → 409/422.
- El orden inicial se calcula al abrir la ronda: `arrival` usa las horas de `check_in` del día; `random` baraja. Config `meal_queue_order` viene del frontend al abrir la ronda, o se guarda en `clockOpConfig`.
- El disparo (10:10 / offset) lo maneja el frontend (cuándo muestra el botón "Apartar Turno"); el backend solo necesita el endpoint de ronda.
- El WebSocket `tenant.{id}.clock` (ya existe, evento `TimeEntryRecorded`) puede reutilizarse para avisar en vivo "es tu turno de elegir", o se agrega un evento `MealQueueTurnChanged`. Dejamos a su criterio si emiten un evento nuevo; el frontend puede hacer polling del `GET` como fallback.

## ✅ Implementado (2026-07-21) — resumen

Francisco confirmó: **convive** (no reemplaza la selección libre). `GET /meal-reservations/queue` y `POST /meal-reservations/queue/pick` agregados a `MealReservationController`, exactamente el shape de request/response propuesto. Decisiones tomadas:

- **Elegibilidad para la cola:** solo empleados que ya hicieron `check_in` hoy (consulta a `time_entries`, excluyendo datos del Simulador Matrix vía `whereNull('simulation_session_id')`) — no tiene sentido apartar turno de comida sin estar en la sucursal. `arrival` ordena por la hora de ese `check_in`; `random` los baraja.
- **La ronda se abre sola** en el primer `GET` del día (get-or-create), no hace falta un endpoint separado de "abrir ronda".
- **`pick` reutiliza las mismas reglas que la reserva libre** (aforo por slot vía `meal_capacity_settings`, restricción de "no dejar el piso vacío" del mismo `job_role_id`) y crea una fila real en `meal_reservations` — así todo lo que ya lee esa tabla (swap, cancelación, reportes) sigue funcionando igual sin importar si la reserva vino de la cola o de selección libre.
- **Si `pick` falla las reglas de aforo/mismo-puesto, el turno NO avanza** (la persona sigue en `choosing` y puede intentar otro horario) — solo avanza tras una reserva exitosa.
- Se agregó `App\Events\MealQueueTurnChanged` (mismo canal `tenant.{id}.clock`) — se emite en cada `pick` exitoso con el siguiente `employee_id` en turno.

Tests: `SillaMealQueueDoorNoticeTest` (2 casos de cola — orden por llegada, "no es tu turno" rechazado, avance correcto). Suite completa: 112/112 verde.

---

## 25. Ley Silla — Aprobación de Supervisor + Control de Aforo — Estado #19

**Contexto (2026-07-21, `docs/Logica Dial.md`):** hoy `startBreakWithSittingTask()` inicia el descanso de silla directamente (con detección de 120 min de pie vía `leySillaConfig.consecutiveMinutes`). El documento pide dos cosas que faltan: (1) **aprobación previa del supervisor** — el empleado ve "Solicitar Silla", el supervisor aprueba (PIN/QR presencial o clic remoto) y **recién ahí** se desbloquea el descanso; (2) **control de aforo** con `sillas_maximas_simultaneas`: si se alcanza el límite, se encola al empleado y se le avisa su turno de espera.

**Pedimos:**

**a) Solicitud + aprobación:**

```
POST /api/clock/silla/request        → crea solicitud, devuelve { request_id, status: 'pending' }
POST /api/clock/silla/{id}/approve   → supervisor aprueba (body: { method: 'pin'|'qr'|'remote', supervisor_pin? })
POST /api/clock/silla/{id}/reject
```

- Tabla `silla_requests`: `id, tenant_id, employee_id, requested_at, status ('pending'|'approved'|'rejected'|'active'|'finished'), approved_by_employee_id, approval_method, started_at, ended_at`.
- La marca de inicio/fin del descanso sigue el flujo de fichaje existente pero con **tipo propio `silla_start` / `silla_end`** (el documento los nombra así) en vez de reusar `break_start`/`break_end`, para que nómina distinga una silla (Ley Silla, obligación normativa) de un descanso ordinario. Si prefieren mantener `break_*` con un flag `is_ley_silla`, también sirve — lo importante es poder distinguirlos en reportes.
- Registrar la **firma/aprobación del supervisor** (compliance LFT ante inspección): quién aprobó, cuándo, método.

**b) Control de aforo:**

```
GET /api/clock/silla/status?date=2026-07-21
→ { "max_simultaneous": 3, "active_count": 2, "available": 1, "queue": [ {employee_id, position} ] }
```

- `max_simultaneous` viene de config `sillas_maximas_simultaneas` (frontend lo manda o se guarda en `clockOpConfig`).
- Si `active_count >= max_simultaneous`, un nuevo `request` aprobado queda en cola (`status` sigue en espera) y el `status` endpoint devuelve su posición. Al terminar alguien (`silla_end`), se libera un lugar y avanza la cola.
- Idealmente el contador de sillas activas se emite por el WebSocket `tenant.{id}.clock` para que el panel del supervisor lo vea en vivo (opcional; el `GET` sirve de fallback).

## ✅ Implementado (2026-07-21) — resumen

Tabla `silla_requests`, endpoints `/clock/silla/request|{id}/approve|{id}/reject|status` en un `SillaController` nuevo. `silla_start`/`silla_end` agregados a `ClockService::ALLOWED_TYPES` (tipo propio, no `break_*` con flag, tal como preferían) con sus reglas de secuencia en la misma tabla de prerequisitos de §15 (`silla_start` exige `check_in` y se bloquea si ya hay `check_out`; `silla_end` exige `silla_start`).

- **method='pin' reutiliza `employees.security_pin`** (el mismo mecanismo que ya construimos para los testigos de `emergency-open`, sección 10.2) — valida contra el PIN del propio supervisor que aprueba. `qr`/`remote` se registran como bitácora de cumplimiento (quién/cuándo/cómo) sin exigir credencial adicional, porque la sesión autenticada del supervisor ya es la autorización.
- **La aprobación NO inicia el descanso por sí sola** — solo cambia `status` a `approved`. El inicio real ocurre cuando el empleado ficha `silla_start` por el flujo normal de `/clock/punch`, que en ese momento valida que exista una solicitud `approved` y que haya aforo disponible, y recién ahí la pasa a `active`. `silla_end` la pasa a `finished`. Esta transición ocurre **dentro de la misma transacción** que el `INSERT` del fichaje, para que nunca queden desincronizados.
- **Aforo:** `sillas_maximas_simultaneas` se lee de `clockOpConfig` (default 1 si no está configurado). Si está lleno, `silla_start` se rechaza con un mensaje claro y la solicitud se queda `approved` — el `GET /clock/silla/status` expone su posición en la cola de aprobadas-esperando-cupo. No se implementó el WebSocket opcional para el contador en vivo (el `GET` cubre el caso, como ustedes mismos dejaban como aceptable) — avisen si lo quieren de todos modos.
- **Bug de timezone que encontramos al probar esto** (relevante para cualquiera que toque `ClockService` en el futuro): usar `Carbon::now()` a secas en vez de `Carbon::now($timezone)` dentro de `processPunch()` desalinea las comparaciones de fecha del aforo con el resto de la función, que sí resuelve la fecha vía `system_settings.timezone` del tenant. Ya corregido en los puntos donde se guarda `started_at`/`ended_at`.

Tests: 3 casos en `SillaMealQueueDoorNoticeTest` (ciclo completo solicitud→aprobación→inicio→fin, `silla_start` sin aprobación rechazado, aforo lleno bloquea al segundo). Suite completa: 112/112 verde.

---

## 26. Aviso "Enviar Mensaje" — Empleado común esperando en puerta — Estados #7 y #11

**Contexto (2026-07-21, `docs/Logica Dial.md`):** el empleado común sin llaves **no puede llamar** por teléfono; su botón secundario es `💬 Enviar Mensaje`, que notifica al encargado en camino ("Sofía López está esperando en puerta") y registra la presencia. Frontend implementará el botón **degradado a aviso in-app + registro en la Matrix** de inmediato (no bloquea), pero para que el encargado reciba un **push real** aunque no tenga la app abierta, hace falta backend.

**⚠️ Decisión / verificación abierta:** ¿existe ya infraestructura de push server-side (FCM/APNs/web-push) en Talent360? El reloj hoy usa solo notificaciones **locales** del navegador. Si NO existe push server-side, este endpoint puede limitarse a **registrar el aviso** (y que el encargado lo vea al abrir la app / por el WebSocket), y el push real queda como mejora posterior.

**Pedimos (mínimo viable):**

```
POST /api/clock/door-notice
{
  "date": "2026-07-21",
  "responsible_employee_id": 1,   // el encargado a quien se avisa (opcional; el backend puede resolverlo)
  "message": "Sofía López está esperando en puerta"
}
```

- Registrar el aviso (tabla `door_notices` o reutilizar el log de la Matrix/eventos): `id, tenant_id, from_employee_id, to_employee_id, date, message, created_at, seen_at`.
- **Si hay push server-side:** disparar la notificación al `responsible_employee_id`.
- **Si NO hay push:** emitir por el WebSocket `tenant.{id}.clock` un evento (`DoorNoticeCreated`) para que, si el encargado tiene la app abierta, lo vea al instante; y que quede en la tabla para cuando la abra.
- Devolver `{ "success": true }`. El frontend ya mostró el aviso in-app localmente, así que no depende del resultado para su UX inmediata.

## ✅ Implementado (2026-07-21) — resumen

Respuesta a la pregunta abierta: **sí existe infraestructura de push server-side** — `App\Services\NotificationService` ya envía por Firebase Cloud Messaging (`sendToUser`, `sendToRole`, `sendBroadcast`; ya la usamos en esta misma sesión para las alertas de RRHH de `emergency-open`, sección 3). Así que se implementó completo, no el mínimo viable de solo registro.

`POST /clock/door-notice` en `StoreOpeningController::doorNotice`: guarda el aviso en `door_notices`, dispara push real vía `NotificationService::sendToUser()`, y además emite `App\Events\DoorNoticeCreated` (mismo canal `tenant.{id}.clock`) para que si el encargado ya tiene la app abierta lo vea al instante sin esperar el push. Si no se manda `responsible_employee_id`, se resuelve automáticamente desde `store_daily_opening_statuses.current_responsible_employee_id` del día — si tampoco hay uno asignado, `422` con mensaje claro en vez de fallar en silencio.

Tests: 2 casos (resuelve encargado automáticamente y envía, falla claro sin encargado resoluble). Suite completa: 112/112 verde.

---

## 25b. Falta: listar solicitudes de silla pendientes para el supervisor

**Contexto (2026-07-21, al construir el frontend de §25):** el flujo de Ley Silla con aprobación quedó así del lado del empleado: solicita (`POST /clock/silla/request`) → espera → cuando el supervisor aprueba, el empleado ficha `silla_start`. El frontend ya tiene el handler `approveSillaRequest(requestId, method, pin)` que pega a `POST /clock/silla/{id}/approve`. **Pero el supervisor no tiene cómo VER las solicitudes pendientes dentro de la app** — §25 asumió que la aprobación llega por push con el `request_id`. Para un panel de supervisor donde vea y apruebe las solicitudes (como ya existe para los descansos locales), hace falta:

```
GET /api/clock/silla/requests?status=pending&date=YYYY-MM-DD
→ { "requests": [ { "id": 42, "employee_id": 11, "requested_at": "2026-07-21T15:30:00-06:00" } ] }
```

- Filtrado por `tenant_id` y, para supervisores, idealmente solo las de su equipo/sucursal (o todas si es admin).
- Con esto el frontend arma la lista y llama a los endpoints de approve/reject que ya existen. Es lo único que falta para cerrar el lado supervisor de §25; el lado empleado ya quedó completo en el frontend.

## ✅ Implementado (2026-07-21) — resumen

`GET /clock/silla/requests` en `SillaController::listRequests` → `ClockService::listSillaRequests()`, mismo shape de respuesta propuesto (`{ requests: [{id, employee_id, requested_at}] }`). Acepta `status` (default `pending`), `date` y `store_id` opcionales — no lo restringí solo a `pending` porque no le vi ventaja a esa rigidez si el frontend algún día quiere reusar el mismo endpoint para ver aprobadas/activas.

**Sobre "idealmente solo las de su equipo/sucursal si es supervisor":** no lo implementé — filtré por `tenant_id` + `store_id` (ya acota a la sucursal), pero no agregué un filtro adicional por "equipo del supervisor" porque no existe hoy en el proyecto un concepto establecido y consultable de "a qué empleados supervisa este supervisor" (no es lo mismo que `reports_to_role_id`, que es jerarquía de puestos, no asignación de equipo). Construir eso a ciegas hubiera sido inventar un contrato nuevo sin que lo pidieran. Si lo necesitan, avisen cómo se determina "el equipo de un supervisor" en el resto del sistema y lo agrego.

Tests: 1 caso (supervisor ve la solicitud pendiente, empleado normal no puede, desaparece de la lista tras aprobarse). Suite completa: 113/113 verde.

---

> **Nota de fase (2026-07-21):** las secciones §22–§26 corresponden a los 5 hallazgos de `docs/VIABILIDAD_LOGICA_DIAL.md` (alineación del dial a `docs/Logica Dial.md`). El frontend de las partes autocontenidas (Enviar Mensaje degradado, cronómetro del estado 16) ya se está implementando; las UIs acopladas al backend (calificación pase de lista, foto comedor, cola de comida, aforo de sillas) se construirán contra los contratos de arriba una vez que Claude Code confirme o ajuste las formas de datos. Dos decisiones de producto siguen abiertas y están marcadas con ⚠️ en §24 y §26.

---

## §27. Canal WebSocket del reloj: migrar de público a privado (Hallazgo 2 de seguridad, urgente)

`docs/AUDITORIA_RELOJ_CHECADOR_2026-07-22.md`, Hallazgo 2: los 4 eventos en tiempo real del reloj transmiten hoy sobre un canal **público** de Reverb — cualquiera que sepa o adivine un `tenant_id` (entero secuencial pequeño) puede suscribirse sin haber iniciado sesión y ver en vivo fichajes, apertura/cierre de tienda, avisos con nombres de empleados y turnos de la cola de comida de un tenant ajeno. Confirmado por código, no solo por sospecha:

```
Backend/app/Events/StoreOpened.php:39          new Channel('tenant.' . $this->tenantId . '.clock'),
Backend/app/Events/TimeEntryRecorded.php:24    return [new Channel('tenant.' . $this->tenantId . '.clock')];
Backend/app/Events/DoorNoticeCreated.php:24    return [new Channel('tenant.' . $this->tenantId . '.clock')];
Backend/app/Events/MealQueueTurnChanged.php:22 return [new Channel('tenant.' . $this->tenantId . '.clock')];
```

`Channel` (no `PrivateChannel`) no exige autenticación para suscribirse. Ya existe el patrón correcto para copiar — `routes/channels.php` ya tiene una autorización para canales privados de tenant:

```php
Broadcast::channel('tenant.{tenantId}', function ($user, $tenantId) {
    return (int) $user->tenant_id === (int) $tenantId;
});
```

**Lo que falta (backend, dos cambios):**

1. En los 4 archivos de `Backend/app/Events/*.php` citados arriba, cambiar `new Channel(...)` → `new PrivateChannel(...)` (import `Illuminate\Broadcasting\PrivateChannel` en vez de/además de `Channel`).
2. En `routes/channels.php`, agregar una entrada para el canal específico del reloj (el patrón `tenant.{tenantId}` ya existente NO cubre `tenant.{tenantId}.clock` — son nombres de canal distintos en Reverb/Pusher, hace falta una entrada propia):
   ```php
   Broadcast::channel('tenant.{tenantId}.clock', function ($user, $tenantId) {
       return (int) $user->tenant_id === (int) $tenantId;
   });
   ```

**Lo que hace el frontend (Cowork), ya preparado pero SIN activar todavía:** `Frontend/src/components/reloj/useClockEngine.tsx:308` hoy hace `echoInstance.channel(channelName)`. El cambio a `echoInstance.private(channelName)` es trivial (una palabra), pero **NO lo voy a activar hasta que confirmes que el backend ya tiene los dos cambios de arriba desplegados** — si el frontend pide un canal privado antes de que exista la entrada en `routes/channels.php`, la suscripción falla la autorización (`/broadcasting/auth` responde 403/404) y el reloj se queda sin tiempo real para todos los usuarios hasta que ambos lados coincidan. Es un cambio que debe ir sincronizado en el mismo despliegue, no independiente.

**Cuando termines el backend:** dímelo o dilo en el chat de Francisco — hago el cambio de una línea en el frontend en el mismo momento y quedan sincronizados.

Tests sugeridos (mismo patrón que canales privados existentes de `NewChatMessage`/`MonitorUpdated`): un usuario del tenant A no puede autorizar suscripción al canal `tenant.B.clock` de otro tenant (403 en `/broadcasting/auth`); un usuario del tenant correcto sí puede.

## ✅ Implementado (2026-07-21) — resumen

Los dos cambios exactamente como se describieron arriba, sin desviaciones de contrato:

1. **Los 4 eventos** (`StoreOpened`, `TimeEntryRecorded`, `DoorNoticeCreated`, `MealQueueTurnChanged`) ahora usan `PrivateChannel` en vez de `Channel` en su `broadcastOn()`, con el import correspondiente actualizado.
2. **`routes/channels.php`** tiene la nueva entrada `Broadcast::channel('tenant.{tenantId}.clock', ...)` con la misma regla que `tenant.{tenantId}`, agregada justo debajo de esa entrada existente.

**Nota sobre el testing (por si sirve para futuras secciones en este repo):** el patrón sugerido de probar por HTTP contra `/broadcasting/auth` con `actingAs()` + `postJson()` no funciona de forma confiable en esta app — el driver de broadcasting en `phpunit.xml` es `null` (no invoca los callbacks de autorización en absoluto), y forzando el driver a `reverb` en el test, la ruta `/broadcasting/auth` vive bajo el grupo `web` con CSRF, incompatible con el patrón de autenticación Sanctum usado en el resto de la suite (ambos casos, permitido y denegado, devuelven un 403 HTML genérico indistinguible). En vez de eso, `ClockChannelPrivacyTest.php` usa PHP Reflection para extraer el callback ya registrado en `Broadcast::connection()->channels` y lo invoca directamente — prueba la lógica de autorización real, determinista y sin depender de infraestructura de sockets. 115/115 tests pasan (519 assertions), sin regresiones.

**Backend desplegado y listo.** Cowork ya puede activar el cambio de una palabra en `Frontend/src/components/reloj/useClockEngine.tsx:308` (`.channel(channelName)` → `.private(channelName)`) — ambos lados del canal privado están sincronizados.

## ✅ Frontend activado (2026-07-22)

Cambiado `echoInstance.channel(channelName)` → `echoInstance.private(channelName)` en `useClockEngine.tsx:308`, confirmado que backend ya tiene ambos cambios desplegados (120/120 tests). §27 cerrado en los dos lados.

---

## §28. Bug: "Abrir Tienda" en la Matrix da "No eres el encargado responsable..." — falta `platform_admin` en el check de rol

Francisco reportó: al presionar el dial "Abrir Tienda" en la Matrix, sale un error y no deja avanzar. Rastreé el flujo completo (frontend → `POST /store-opening/open-and-clock-in` → `StoreOpeningController::openStoreAndClockIn` → `StoreOpeningService::openStoreAndClockIn`) y encontré la causa exacta, no es un bug de frontend:

```php
// Backend/app/Services/StoreOpeningService.php, línea 155-159
if ($status->current_responsible_employee_id !== $user->id) {
    // Check if user has permissions for administrative override
    if ($user->role !== 'admin' && $user->role !== 'supervisor') {
        throw new \Exception("No eres el encargado responsable de la apertura en este momento.");
    }
}
```

Este check de "quién puede abrir aunque no sea el encargado asignado" **no incluye `platform_admin`**, a diferencia de TODO el resto del codebase, que sí lo incluye consistentemente:

```
Backend/app/Http/Controllers/IncidentReportController.php:47   in_array($user->role, ['admin', 'supervisor', 'platform_admin'])
Backend/app/Http/Controllers/SillaController.php:66            in_array($request->user()->role, ['admin', 'supervisor', 'platform_admin'])
Backend/app/Services/ClockService.php:541 y 570                in_array($approver->role, ['admin', 'supervisor', 'platform_admin'])
Backend/app/Services/StoreOpeningService.php:384 (¡la MISMA clase, otro método!)   in_array($rater->role, ['admin', 'supervisor', 'platform_admin'])
```

Es decir, el propio archivo `StoreOpeningService.php` ya usa el patrón correcto en `submitRollCall` (línea 384) pero `openStoreAndClockIn` (línea 157) se quedó con la versión vieja de dos roles. Si el usuario que prueba en la Matrix tiene `role = 'platform_admin'` (el caso típico del dueño de la cuenta probando su propio sistema) y no coincide con `current_responsible_employee_id` del día (porque no hay `store_opening_assignments` activo, o porque está probando con un usuario que no es el titular de llaves), el backend lo rechaza aunque debería tener override administrativo.

**Fix de una línea:**
```php
if (!in_array($user->role, ['admin', 'supervisor', 'platform_admin'])) {
    throw new \Exception("No eres el encargado responsable de la apertura en este momento.");
}
```

**Revisar también** (no confirmé si tienen el mismo problema, pero comparten el mismo patrón viejo — vale la pena una pasada rápida):
- `StoreOpeningController::reportAbsence` y los otros métodos de `StoreOpeningService` que decidan "quién puede actuar en nombre del encargado" además de `submitRollCall` (línea 384, que ya está bien).
- La comparación `$status->current_responsible_employee_id !== $user->id` es estricta (`!==`) sin castear a `int` — si esa columna llega alguna vez como string, la comparación fallaría en falso negativo incluso para el encargado correcto. `submitRollCall` en la misma clase sí castea con `intval(...) === intval(...)` (línea 383). Recomiendo alinear `openStoreAndClockIn` al mismo patrón por consistencia, ya que estás ahí.

No lo arreglé yo porque `Backend/app/**` es zona de Claude Code — dejo el diagnóstico completo y el fix exacto para que sea un cambio de un minuto.

## ✅ Implementado (2026-07-21) — resumen

Aplicado exactamente el fix propuesto en `StoreOpeningService::openStoreAndClockIn()` (línea 155-160):

1. `!in_array($user->role, ['admin', 'supervisor', 'platform_admin'])` reemplaza el `role !== 'admin' && role !== 'supervisor'` viejo — ahora `platform_admin` tiene el mismo override administrativo que en el resto del codebase.
2. De paso alineé la comparación de responsable a `intval($status->current_responsible_employee_id) !== intval($user->id)` (antes `!==` sin castear), igual que ya hacía `submitRollCall` en la misma clase — por consistencia y para blindar contra el caso borde de que la columna llegue como string.

**Sobre el "revisar también" de la nota:** revisé `StoreOpeningController::reportAbsence` y confirmé que delega en `StoreOpeningHandoffService::reportOpeningAbsence`, que no tiene ningún check de rol de este tipo (no hace falta tocarlo). Grep de `role !== 'admin'`/`in_array($user->role...)` en todo `Backend/app/**` no encontró más ocurrencias del patrón viejo de dos roles — el bug estaba aislado a esta única línea.

Test nuevo: `Backend/tests/Feature/StoreOpeningAdminOverrideTest.php` (3 casos: `platform_admin` no asignado sí puede abrir, empleado regular no asignado sigue sin poder, el responsable asignado sigue pudiendo). Suite completa: 118/118 tests, 526 assertions, sin regresiones.

## ⚠️ Update (2026-07-22) — el error seguía apareciendo: causa real encontrada y corregida

Francisco reportó que el error del dial "Abrir Tienda" seguía pendiente después del fix de arriba. Investigando de nuevo encontré que el fix de `platform_admin` era correcto pero insuficiente — había un bug más profundo y más grave que el reportado originalmente, presente desde hace dos semanas:

**La causa real:** la migración `2026_07_07_192928_fix_store_opening_assignments_foreign_key.php` cambió a propósito la FK de `store_opening_assignments.employee_id` de `users.id` a `employees.id` (para resolver un bug distinto, documentado en esa misma migración). Pero **nadie actualizó los 3 lugares que copian ese valor hacia columnas que siguen siendo `users.id`**:

1. `StoreOpeningService::getTodayOpeningStatus()` — copiaba `$firstResponsible->employee_id` (ahora `employees.id`) directamente a `store_daily_opening_statuses.current_responsible_employee_id` (sigue siendo FK a `users.id`, esa tabla no la tocó la migración de julio). Resultado: el "encargado responsable del día" casi nunca coincidía con el `users.id` real de nadie — ni de la persona correcta, ni de ningún admin haciendo override antes de mi fix del bloque anterior.
2. `StoreOpeningService::emergencyOpenWithWitnesses()` — el check `isSuplenteConLlaves` comparaba `users.id` del solicitante contra `store_opening_assignments.employee_id` (que ya es `employees.id`), así que el "no cuentas con llaves activas" podía dispararle a un titular de llaves legítimo.
3. `StoreOpeningHandoffService::handoffToNextResponsible()` — la búsqueda del `currentAssignment` y la resolución de `$nextUserId` (el suplente al que se cede la apertura) tenían el mismo problema en ambas direcciones.

En SQLite (tests) esto simplemente producía comparaciones que nunca cuadraban (400 "No eres el encargado..."); en PostgreSQL (producción, con la FK real activa) además puede lanzar una violación de FK cruda (500) en cuanto `employees.id` del responsable no coincide por casualidad con ningún `users.id` real — que es el caso normal, no la excepción.

**Fix:** en los 3 sitios, se resuelve explícitamente `employees.user_id` (o viceversa) antes de comparar o de escribir en columnas `users.id`, en vez de asumir que ambos ids son intercambiables. Comentado inline en cada punto para que quede claro cuál es cuál.

**Tests nuevos:** `test_handoff_to_next_responsible_resolves_the_real_users_id_of_the_backup` (nuevo, en `StoreOpeningAdminOverrideTest.php`) y `test_emergency_open_succeeds_when_employees_id_diverges_from_users_id` (nuevo, en `ClockEmergencyContingencyTest.php`) — ambos crean deliberadamente una fila "decoy" en `employees` antes de la real para que `employees.id` nunca coincida por accidente con `users.id`, así el test detecta el bug en vez de esconderlo (los tests viejos de este archivo alineaban ambos ids 1:1 sin darse cuenta, lo cual ocultaba el problema).

Suite completa: **120/120 tests, 533 assertions**, sin regresiones.

---

## §29. `GET /store-opening/assignments` expone `employees.id` donde el frontend espera `users.id` (mismo bug de raíz que §28, endpoint distinto)

Durante la auditoría completa de la Matrix (2026-07-22) encontré la continuación exacta del bug que ya corrigieron en §28. La migración `2026_07_07_192928_fix_store_opening_assignments_foreign_key.php` cambió `store_opening_assignments.employee_id` de apuntar a `users.id` a apuntar a `employees.id` — y ustedes ya corrigieron los 3 lugares donde el propio backend comparaba mal ese valor (`getTodayOpeningStatus`, `emergencyOpenWithWitnesses`, `handoffToNextResponsible`). Pero **`StoreOpeningController::getAssignments()` (línea 104-116) nunca se tocó**, y sigue devolviendo el `employee_id` crudo (`employees.id`) sin resolver a `users.id`:

```php
public function getAssignments(Request $request)
{
    ...
    $assignments = StoreOpeningAssignment::withoutGlobalScopes()
        ->where('tenant_id', $tenantId)
        ->with('employee:id,name,email,role')   // falta 'user_id' aquí
        ->orderBy('priority_order', 'asc')
        ->get();

    return response()->json($assignments);
}
```

El frontend consume este endpoint (y el `employee_id` de cada fila) en **26 sitios de 6 archivos** (`RecursosHumanos.tsx`, `PanelSimulador.tsx`, `useStoreOpening.ts`, `useKeyholderDelegation.ts`, `MealQueue.tsx`, `RelojVisual.tsx`), y en todos se compara contra `users.id` (`currentUser.id`, `user.id` de `globalUsers`, etc.) — exactamente el mismo error de espacio de IDs que ya diagnosticaron y corrigieron para los otros 3 métodos, pero aquí nadie les avisó que el CONTRATO del endpoint también necesitaba ajustarse.

**Impacto real:** el badge de llaves (🔑) y el orden de prioridad de apertura que se muestran en RRHH y en la Matrix pueden no coincidir con el empleado correcto — comparan `employees.id` contra `users.id`, que son numéricamente independientes salvo coincidencia. Es probablemente la causa de fondo de inconsistencias visuales de "quién es el encargado" que no llegan a producir un error 400/500 (a diferencia de §28), solo un dato mal emparejado en pantalla.

**Fix propuesto (aditivo, no rompe nada existente):**
```php
->with('employee:id,name,email,role,user_id')
```
y agregar `user_id` (el de `employees.user_id`, resuelto) como campo top-level en cada fila del array de respuesta — por ejemplo `$assignments->each(fn($a) => $a->resolved_user_id = $a->employee?->user_id);` — para que el frontend pueda migrar sus comparaciones de `a.employee_id` a `a.resolved_user_id` sin ambigüedad. Si prefieren otro nombre de campo, dígannos aquí y ajustamos el lado frontend a lo que decidan.

**No lo corregí yo** porque `Backend/app/**` es zona de Claude Code. Del lado frontend ya adapté `PanelSimulador.tsx` para traer los datos frescos de este endpoint en vez de depender de un caché de `localStorage` potencialmente viejo (mejora independiente, ver auditoría de la Matrix), pero la comparación de IDs en sí sigue heredando este bug hasta que el endpoint incluya el campo resuelto.

## ✅ Implementado (2026-07-22) — resumen

Usé exactamente el nombre de campo que propusieron: **`resolved_user_id`**.

En vez de calcularlo a mano en cada uno de los 3 métodos del controller, lo agregué como atributo `$appends` en el modelo `StoreOpeningAssignment` (`Backend/app/Models/StoreOpeningAssignment.php`), con un accessor `getResolvedUserIdAttribute()` que lee `$this->employee->user_id` — así sale automáticamente en el JSON de los 3 endpoints que devuelven una asignación (`GET /store-opening/assignments`, `POST /store-opening/assignments`, `PUT /store-opening/assignments/{id}`) sin repetir la lógica. Requiere que la relación `employee` venga cargada con `user_id` en el `select` (ya lo agregué a los 3 `with()`/`load()` existentes: `employee:id,name,email,role,user_id`); si en algún punto futuro se accede a `resolved_user_id` sin la relación precargada, el accessor devuelve `null` en vez de disparar una query N+1 silenciosa.

`employee_id` (el crudo, `employees.id`) se queda tal cual en la respuesta — no lo quité para no romper nada que ya dependa de él — así que Cowork puede migrar sus 26 sitios de `a.employee_id` a `a.resolved_user_id` sin que ambos campos dejen de convivir mientras dure la migración del lado frontend.

Test nuevo: `test_get_assignments_exposes_resolved_user_id_alongside_the_raw_employees_id` en `StoreOpeningAdminOverrideTest.php`, con la misma técnica de "decoy" en `employees` para garantizar que `employees.id` y `users.id` difieran en la prueba. Suite completa: **121/121 tests, 537 assertions**, sin regresiones.

---

## §30. `POST /store-opening/assignments` recibe un `employee_id` que no es el `employees.id` real (bug en la dirección opuesta a §29)

Mientras migraba los 26 sitios de lectura del §29 a `resolved_user_id` (2026-07-22), encontré que **crear** un encargado nuevo tiene el mismo problema pero al revés. En `Frontend/src/components/CompanySettingsPanel.tsx`, `handleAddAssignment()`:

```js
const empId = userObj.employee_id || userObj.id;
...
await axiosInstance.post('/store-opening/assignments', {
  employee_id: empId,
  ...
});
```

`userObj.employee_id` es un campo de texto libre en el perfil del colaborador (un código/gafete visible en RRHH, ej. "EMP-0004" — no garantizado numérico, no es una FK a nada). `userObj.id` es `users.id`. Ninguno de los dos es el `employees.id` que la tabla `store_opening_assignments.employee_id` espera como FK — y el frontend no tiene ese dato disponible en ningún lado (`globalUsers` no trae `employees.id`).

No lo corregí porque es 100% un cambio de contrato/backend: ¿qué debería aceptar `POST /store-opening/assignments`? Dos opciones que veo, díganme cuál prefieren:

**Opción A (recomendada):** que el endpoint acepte `user_id` en el body (en vez de `employee_id`) y resuelva internamente a `employees.id` vía `Employee::where('user_id', $request->user_id)->firstOrFail()->id` antes de crear el registro — simétrico con el accessor `resolved_user_id` que ya agregaron en la lectura. Si el usuario no tiene fila en `employees` todavía, decidan si se crea una automáticamente o se rechaza con un mensaje claro.

**Opción B:** mantener `employee_id` en el body tal cual, pero documentarnos que el frontend debe resolver primero el `employees.id` real antes de enviarlo — para eso necesitaríamos un endpoint tipo `GET /employees/by-user/{userId}` o que `globalUsers`/`/sync/state` ya traiga el `employees.id` de cada colaborador.

Mientras se decide, dejé el código de `CompanySettingsPanel.tsx` sin tocar (sigue enviando el valor viejo) para no adivinar un contrato que no existe todavía — cualquier intento de "adivinar" el id correcto del lado frontend sería tan frágil como el bug que estamos corrigiendo.

## ✅ Implementado (2026-07-22) — resumen y nota para Cowork

Fue **Opción A**. La decisión no tenía trade-off de producto real (ninguna de las dos opciones cambia el comportamiento visible para el usuario final, solo la forma del contrato), así que la tomé del lado backend en vez de interrumpir a Francisco por algo puramente técnico — avisando aquí en el documento como corresponde.

**Cambio de contrato — `POST /store-opening/assignments`:**

- **Antes:** `{ "employee_id": <valor no confiable> }`
- **Ahora:** `{ "user_id": <users.id>, "priority_order": ..., "can_open_store": ..., ... }` — el resto de los campos no cambia.

El backend resuelve `Employee::where('tenant_id', $tenantId)->where('user_id', $request->user_id)->first()` y usa `employee->id` como el `employee_id` real al crear la asignación. Si el usuario no tiene fila en `employees` todavía, responde `422` con `{"success": false, "message": "Este colaborador no tiene un registro de empleado (employees) asociado. Complétalo en RRHH antes de asignarlo a la apertura."}` — no se crea nada automáticamente, para no enmascarar un perfil de RRHH incompleto.

La respuesta (`assignment` en el body) sigue trayendo tanto `employee_id` (crudo, `employees.id`) como `resolved_user_id` (de §29), así que el frontend puede confirmar visualmente contra `userObj.id` sin otra llamada.

**Lo que le toca a Cowork:** en `Frontend/src/components/CompanySettingsPanel.tsx`, `handleAddAssignment()` — cambiar
```js
const empId = userObj.employee_id || userObj.id;
await axiosInstance.post('/store-opening/assignments', { employee_id: empId, ... });
```
a
```js
await axiosInstance.post('/store-opening/assignments', { user_id: userObj.id, ... });
```
y manejar el nuevo `422` mostrando el `message` del backend tal cual (ya viene listo para UI). No hace falta ningún endpoint nuevo (`GET /employees/by-user/{userId}` no fue necesario — la Opción A lo evita por diseño).

Test nuevo: 3 casos en `StoreOpeningAdminOverrideTest.php` (crea con `user_id` válido y resuelve el `employees.id` correcto, rechaza usuario sin fila en `employees`, rechaza asignación duplicada). Suite completa: **124/124 tests, 548 assertions**, sin regresiones.

## ✅ Frontend aplicado (2026-07-22)

`handleAddAssignment()` en `CompanySettingsPanel.tsx` ahora envía `{ user_id: userObj.id, ... }` en vez del `employee_id` no confiable. Se agregó estado local `assignmentError` que muestra el `message` del 422 tal cual debajo del selector si el backend rechaza al colaborador (sin fila en `employees`). Rama sandbox sin cambios de contrato — sigue usando `userObj.id` como `employee_id` de mentiras, igual que el resto de los mocks del módulo. Verificado con `tsc --noEmit`: 0 errores.

---

## §31. Seguridad: `POST /sync/tasks` no valida rol para crear/editar Tareas y Rutinas

De `docs/AUDITORIA_RELOJ_TAREAS_2026-07-22.md`, sección 3 — spec completa aquí para no depender del otro documento.

**El problema (verificado en el código, no es hipotético):** `routes/api.php` línea 292 registra `/sync/tasks` bajo `role:empleado,employee,admin,supervisor,platform_admin` — cualquier colaborador autenticado del tenant puede llamarlo. `TaskSyncController::sync()` (líneas 30-75 y 77-121) procesa `tasks` y `routines` del payload sin ningún chequeo de `auth()->user()->role`; solo valida que el `tenant_id` sea el correcto, no quién hizo la petición. La UI (`PanelTareasRutinas.tsx`) no expone esta pantalla a un `empleado` normal, pero eso es solo navegación de React — una petición HTTP directa a `/sync/tasks` con `tasks`/`routines` en el body la acepta igual viniendo de cualquier rol permitido por el middleware.

**Impacto concreto:** un colaborador con rol `empleado` podría, vía API directa: crear tareas nuevas, cambiar el `validation_mode` de una tarea existente a `'auto'` (quitándose a sí mismo la validación de supervisor), o modificar `points`/`estimated_mins` de cualquier tarea del tenant.

**Contraste con el patrón correcto que ya existe en el mismo módulo:** `SillaController::approve()/reject()` → `ClockService::approveSillaRequest()` valida `in_array($approver->role, ['admin','supervisor','platform_admin'])` a nivel de servicio antes de aprobar un descanso. `TaskSyncController::sync()` simplemente no tiene el equivalente para las porciones `tasks`/`routines` (la porción `assignments` — completar/pausar/tomar de la bolsa tu propia tarea asignada — sí debe seguir abierta a cualquier empleado, esa parte no se toca).

**Fix propuesto (3 líneas, al inicio de `sync()` antes de procesar `tasks`/`routines`):**
```php
if ($request->has('tasks') || $request->has('routines')) {
    if (!in_array(auth()->user()->role, ['admin', 'supervisor', 'platform_admin'])) {
        return response()->json(['message' => 'No autorizado para crear o editar tareas/rutinas.'], 403);
    }
}
```

**Actualización (2026-07-22) — ya verifiqué esto, no era solo una duda teórica:** confirmé que `useTaskStore.ts`'s `syncToBackend()` SÍ mandaba siempre los 3 arrays completos (`tasks`, `routines`, `assignments`) en **cada** llamada, incluidas las 10 acciones puramente operativas (completar/pausar/tomar de la bolsa, etc.). Con el fix tal como estaba propuesto, el 403 habría bloqueado también esas acciones legítimas de cualquier empleado — no era un riesgo hipotético, era una regresión garantizada. Ya lo corregí del lado frontend: `syncToBackend(includeCatalog = false)` ahora solo incluye `tasks`/`routines` en el payload cuando `includeCatalog === true`, y solo las acciones que de verdad tocan el catálogo lo pasan así (`addTask`, `updateTask`, `addRoutine`, `updateRoutine`, `createDynamicTask`). Las 10 acciones operativas ahora mandan únicamente `{ assignments: [...] }`. De paso encontré un hallazgo relacionado: el botón "Crear Tarea Rápida" en `RelojVisual.tsx` (la hoja de "Operaciones & Soporte AI") no tenía ningún gate de rol — cualquier empleado lo veía y podía usarlo, a diferencia de `TaskRunner.tsx` donde el equivalente ya está reservado a `isSupervisor`. Ya lo gateé igual (`admin`/`supervisor`/`platform_admin`). Con esto, el fix de 3 líneas de arriba ya es seguro de aplicar tal cual, sin que rompa nada operativo.

**Sugerencia de test:** un caso que loguea como `empleado` e intenta `POST /sync/tasks` con `tasks: [...]` no vacío → espera 403. Otro que loguea como `empleado` con solo `assignments` (sin `tasks`/`routines`) → espera 200 (no debe romper el flujo operativo normal).

## ✅ Implementado (2026-07-22) — resumen

El fix de 3 líneas tal cual estaba propuesto, en `TaskSyncController::sync()` justo antes de abrir la transacción.

**Efecto colateral que encontré al correr la suite:** 3 tests preexistentes en `TaskValidationRuleTest.php` (`test_sync_with_auto_validation_mode...`, `..._forced_validation_mode...`, `..._dynamic_validation_mode_calculates_probability_new_hire`) actuaban como `empleado` y mandaban `tasks` **y** `assignments` en la misma llamada — el patrón viejo que ya corrigieron del lado frontend, pero que quedó como residuo en la suite de tests. Los corregí quitando el array `tasks` redundante (la tarea ya se creaba directo en el `setUp()` del test vía `Task::create()`, reenviarla en el sync siempre fue innecesario) — no cambié ninguna aserción de negocio, solo el payload de la petición.

Test nuevo: `TaskSyncSecurityTest.php` — empleado no puede crear `tasks`/`routines` (403), empleado sí puede seguir mandando solo `assignments` (200), admin sí puede crear tasks. Suite completa: **130/130 tests, 558 assertions**, sin regresiones.

---

## §32. Tarea placeholder de Ley Silla (`taskId: 9999`) sin registro real + falta null-safe en `TaskSyncController`

De `docs/AUDITORIA_RELOJ_TAREAS_2026-07-22.md`, sección 6 — spec completa aquí.

**El problema:** `RelojVisual.tsx` línea 5181, el botón "Monitoreo de seguridad desde silla" del modal de Ley Silla llama a `startBreakWithSittingTask(9999)` — un ID fijo que no corresponde a ningún registro en `tasks`. `TaskSyncController::sync()` línea 161 hace `$task = Task::find($mappedData['task_id'])`, que da `null` para el 9999. Eso es inofensivo mientras la asignación no se marque `completed` — pero en la línea 227, `$basePoints = $task->points ?? 10` **no tiene el operador null-safe** (`$task?->points`). Si `$task` es `null` y esa asignación llega a `status === 'completed'`, PHP truena con "Attempt to read property 'points' on null" → 500 para ese usuario, dentro de una transacción que además hace rollback de todo el sync (tasks/routines/assignments del resto del payload también se pierden en esa llamada).

**Por qué no ha reventado todavía:** hoy no hay ningún botón "Terminar" que marque esa asignación en concreto como `completed` — pero es frágil apoyarse en que nadie conecte ese botón sin saber de este bug.

**Fix inmediato (resguardo mínimo, 1 carácter):**
```php
// línea 227
$basePoints = $task?->points ?? 10;
```

**Fix de raíz (recomendado, evita el `null` de origen):** crear una fila real en `tasks` por tenant (vía seeder o migración de datos, ejemplo de payload):
```php
Task::firstOrCreate(
    ['tenant_id' => $tenantId, 'title' => 'Monitoreo de seguridad desde silla'],
    ['estimated_mins' => 15, 'points' => 5, 'priority' => 'normal', 'category' => 'operativo',
     'can_be_done_sitting' => true, 'validation_mode' => 'auto', 'frequency' => 'Diaria']
);
```
y usar su `id` real en vez de `9999`.

**Lo que le toca a Cowork (frontend) una vez decidan el enfoque:** si crean la tarea real, avísenme el `id` (o si prefieren que sea configurable, expónganlo en `/sync/state` como parte de `systemSettings`, similar a como ya se hizo con `punctuality_course_id`) y actualizo el `9999` hardcodeado en `RelojVisual.tsx` línea 5181. Si solo aplican el null-safe como resguardo temporal, no se requiere ningún cambio de frontend.

## ✅ Implementado (2026-07-22) — resumen

Los dos fixes, resguardo Y raíz:

1. **Null-safe:** `$basePoints = $task?->points ?? 10;` en la línea que reportaron, y también en `$task->title ?? '...'` unas líneas abajo (mismo patrón, mismo riesgo, no lo mencionaron pero es idéntico y gratis arreglarlo de una vez).
2. **Tarea real:** migración `2026_07_22_000003_seed_silla_monitoring_task.php` — crea "Monitoreo de seguridad desde silla" (`can_be_done_sitting: true`, `validation_mode: 'auto'`) para cada tenant existente, idempotente (`firstOrCreate`-equivalente por `tenant_id`+`title`).

**No hizo falta exponer nada nuevo en `/sync/state`:** ya devuelve el array completo `tasks` por tenant (`ClockController.php:255`, `DB::table('tasks')->where('tenant_id', $tenantId)->get()`), así que en cuanto la migración corre, la tarea real ya aparece ahí con su `id` verdadero. Cowork puede resolverlo del lado frontend con `tasks.find(t => t.title === 'Monitoreo de seguridad desde silla')?.id` en vez de un campo dedicado — más simple que la opción de `system_settings` que propusieron (ver nota abajo sobre por qué evité esa tabla para esto).

**⚠️ Hallazgo colateral, no lo toqué — para que quede on the record:** `system_settings.key` es la **primary key** de la tabla (`2026_06_09_090049_create_system_settings_table.php` línea 15, nunca se migró a una PK compuesta cuando se agregó `tenant_id` en `2026_06_19_062150`). Eso significa que dos tenants **no pueden tener cada uno su propia fila** para la misma `key` — `tasksConfig`, `punctuality_course_id` y cualquier otro setting "por tenant" que ya exista hoy en `system_settings` en realidad solo puede tener un valor global a la vez en todo el sistema; el segundo tenant que intente guardar esa misma `key` (vía `updateOrInsert(['key' => ..., 'tenant_id' => ...], ...)`, que sí filtra por ambas columnas al buscar pero no al insertar) se encontraría con una violación de PK cruda. Es la razón real por la que decidí NO usar `system_settings` para exponer el id de la tarea de Ley Silla — habría sido agregar un caso más al mismo bug en vez de evitarlo. Esto es una migración de esquema real (agregar `tenant_id` a una PK compuesta, con los datos existentes ya mezclados) — no la até a este fix porque es un cambio de forma distinta y con su propio riesgo, pero avisen si quieren que lo aborde aparte.

Test nuevo: `TaskSyncSecurityTest.php` — completar una asignación cuyo `task_id` apunta a una tarea de OTRO tenant (mismo id numérico, FK satisfecha porque la fila existe físicamente, pero `Task::find()` con tenant-scope da `null` — el escenario real detrás de "inofensivo hasta completed") ya no truena; y verificación de que la migración sembró la tarea real. Suite completa: **130/130 tests, 558 assertions**, sin regresiones.

---

## §33. Sugerencia de arquitectura (sin urgencia): migrar la sincronización operativa de Tareas de "reenviar todo" a "actualizar por fila"

De `docs/AUDITORIA_RELOJ_TAREAS_2026-07-22.md`, sección 4 — spec completa aquí. No aplica urgencia de seguridad, es deuda técnica real que conviene planear antes de que el módulo se use con tenants de plantilla grande.

**El problema:** `useTaskStore.ts`'s `syncToBackend()` reenvía `{ tasks: state.tasks, routines: state.routines, assignments: state.assignments }` — los arrays **completos** — en cada acción operativa (tomar de la bolsa, iniciar, pausar, completar, liberar, omitir). `TaskSyncController::sync()` recorre cada fila del arreglo recibido con `find()`/`update()` individuales. Ya existen endpoints por fila sin usar: `GET /task-assignments` y `PUT /task-assignments/{id}` (`TaskAssignmentController.php`), con scoping por tenant/fecha.

**Consecuencias reales:**
- **Carrera de datos:** si el celular de un empleado tiene una copia local vieja de una asignación (otro colaborador ya la tomó de la Bolsa de Trabajo, pero a este celular no le llegó la actualización), cualquier otra acción de este empleado reenvía también su copia vieja de esa asignación ajena — el backend la sobreescribe sin control de versión (solo protege asignaciones ya `completed`; `pending`/`in_progress` no tienen ningún resguardo).
- **Costo que crece con el historial:** cada sync procesa todas las filas que el navegador tenga cargadas, no solo la que cambió.

**Plan de migración propuesto (requiere backend primero, por eso no lo apliqué yo):**
1. Backend: portar a `TaskAssignmentController::update()` la misma lógica de recálculo que hoy solo vive en `TaskSyncController::sync()` líneas 151-245 (chequeo de `reportsTo`/`requiresValidation` según `validation_mode`, cálculo de `task_cost`, `points_awarded`/`coins_awarded` y depósito en `UserWallet` — con el mismo guard `$existing->status !== 'completed'` para no pagar dos veces).
2. Backend (opcional pero recomendado): agregar `updated_at`/versión a `TaskAssignment` para que `PUT /task-assignments/{id}` pueda responder `409` si alguien más ya la modificó, en vez de sobreescribir a ciegas.
3. Frontend (Cowork, una vez el punto 1 esté listo): migrar `startTask`, `pauseTask`, `completeTask`, `releaseTask`, `omitAssignment`, `grabTaskFromPool`, `reserveTaskFromPool` en `useTaskStore.ts` para llamar a `PUT /task-assignments/{id}` en vez de `syncToBackend()` completo.
4. Dejar `POST /sync/tasks` únicamente para crear/editar definiciones de `tasks`/`routines` (cambian con poca frecuencia), no para el trajín operativo de cada clic.

**Avísenme cuando el punto 1 esté listo** y empiezo la migración del punto 3 del lado frontend.

## ✅ Punto 1 implementado (2026-07-22) — resumen

Porté la lógica de `TaskSyncController::sync()` (bloque de `assignments`, líneas ~151-245) a `TaskAssignmentController::update()`, tal cual: chequeo de `reportsTo`/`requiresValidation` según `validation_mode` (`auto`/`forced`/`dynamic`) y `tasksConfig`, cálculo de `task_cost` por salario/minutos acumulados, `points_awarded`/`coins_awarded` + depósito en `UserWallet` al completar — con el mismo guard `$assignment->status === 'completed'` para no volver a calcular ni a pagar una asignación ya completada (si ya estaba `completed`, el nuevo `update()` la deja tal cual sin tocar wallet/puntos, sin importar qué más venga en el body).

**Bug colateral real que encontré haciendo esto (no inventado, confirmado con test):** `TaskAssignment::$fillable` (`Backend/app/Models/TaskAssignment.php`) nunca incluyó `task_cost` ni `coins_awarded` — esas dos columnas existen desde `2026_07_22_000002_add_cost_and_score_to_task_assignments_table.php`, pero como no estaban en `$fillable`, tanto `TaskSyncController::sync()` como mi nuevo código en `TaskAssignmentController::update()` las calculaban correctamente pero el mass-assignment (`create()`/`update()`) las descartaba en silencio — `coins_awarded` se quedaba en `0.00` (su default) siempre, pase lo que pase. Es decir: **el feature de "monedas por completar tarea" nunca funcionó**, ni en `/sync/tasks` ni en ningún lado, desde que se agregó. Lo arreglé agregando ambos campos a `$fillable` — es un fix de una línea, sin riesgo, y corrige el mismo bug en los dos controladores a la vez (no lo cuento como parte separada del contrato porque no cambia ningún payload ni comportamiento visible más allá de "ahora sí paga lo que decía que iba a pagar").

`GET /task-assignments` y `PUT /task-assignments/{id}` (`TaskAssignmentController.php`) ya existían y ya tenían el scoping correcto por `tenant_id`/`date` — no hizo falta tocar `index()`.

**No implementé el punto 2** (versión/`409` en conflicto) — el documento lo marca como "opcional pero recomendado", no como requisito del punto 1, y agregarlo ahora sin que el punto 3 (frontend) esté listo para manejar un `409` sería trabajo especulativo. Avísenme si lo quieren antes de que Cowork empiece la migración del frontend.

Test nuevo: `TaskAssignmentUpdateTest.php` — completar con `validation_mode: 'auto'` paga puntos/monedas correctamente, `'forced'` degrada a `awaiting_validation` y no paga, una asignación ya `completed` no se vuelve a pagar en un update posterior, y el mismo caso de tarea de otro tenant que §32 cubrió para `sync()` (aquí para `update()`). Suite completa: **134/134 tests, 569 assertions**, sin regresiones.

**Listo para Cowork:** ya pueden migrar `startTask`, `pauseTask`, `completeTask`, `releaseTask`, `omitAssignment`, `grabTaskFromPool`, `reserveTaskFromPool` en `useTaskStore.ts` para llamar a `PUT /task-assignments/{id}` en vez de `syncToBackend()` completo — el endpoint ya calcula y persiste todo lo que antes solo pasaba por `/sync/tasks`.

## ✅ Punto 3 implementado (2026-07-23) — resumen (Cowork)

Las 7 funciones migraron a un helper nuevo `syncAssignmentRow(assignmentId)` que hace `PUT /task-assignments/{id}` con la fila completa (mismo shape que antes iba dentro del arreglo `assignments` de `/sync/tasks`), en vez de `syncToBackend()`. `triggerCheckInRoutines` y `handleSpillOver` se quedaron en `syncToBackend()` completo a propósito — generan/mueven varias filas a la vez, no una sola, así que no encajan en el patrón "una fila por clic". No leemos de vuelta la respuesta del `PUT` para reflejar recálculos del servidor (por simplicidad y porque no confirmamos el shape exacto de la respuesta) — si el backend recalcula algo que el frontend deba reflejar de inmediato (más allá de lo que ya calcula localmente antes de mandar), avisen y lo agregamos.

De paso generalizamos los roles hardcodeados que señalaba la auditoría: `handleSpillOver`'s `[1,2,3,4].includes(roleId)` ahora revisa si algún puesto le reporta a `roleId` (mismo criterio de jerarquía que `reports_to_role_id`/`reports_to_role_ids` que ya usa `TaskRunner.tsx`), y los fallbacks de `getRoleIdFromRoleName`/`getRoleNameFromRoleId` en `PanelTareasRutinas.tsx` ya no adivinan IDs fijos (6=Ayudante, 5=Cajera, 1=Encargado) — si no encuentran el puesto en `globalRoles`, caen a la Bolsa de Trabajo (id 0) en vez de asignar a un puesto que podría no existir o significar otra cosa en otro tenant.

---

## §34. Avisar al supervisor cuando se omite una tarea

**Contexto (auditoría del módulo de Tareas, 2026-07-22):** hoy `omitAssignment()` en `useTaskStore.ts` solo cambia `status: 'omitted'` local y lo resincroniza vía `syncToBackend()` (dentro del payload completo de `assignments`, §33). Nadie del lado de supervisión se entera de que una tarea se omitió salvo que abra el dashboard y note la ausencia. El frontend ya pide confirmación con motivo obligatorio (textarea) antes de omitir, y ya intenta pegarle a este endpoint (con `.catch()` — si no existe, la UX local sigue funcionando igual, solo no hay aviso):

```
POST /api/task-assignments/{id}/omit
{
  "reason": "No había producto suficiente en el almacén para reponer la góndola."
}
```

**Lo que pedimos:**
- Marcar la asignación `status: 'omitted'`, `validation_feedback: reason` (esa columna ya existe en `task_assignments`, ya la usa `TaskValidationController`, no hace falta migración nueva).
- Resolver quién es "el supervisor" del empleado dueño de la asignación — mismo criterio ya usado en `TaskValidationController::validateAssignment()` (`$validatorJobRole->isSupervisorOf($employeeJobRole)`, más `admin`/`platform_admin` como fallback si el puesto no tiene supervisor directo).
- Avisar por push real con `NotificationService` (mismo patrón que §26, `door-notice`) — algo como "Juan Pérez omitió la tarea 'Rellenado de góndola'. Motivo: no había producto suficiente." Si `NotificationService` no tiene ya un método para "avisar a quien supervisa a este empleado" (a diferencia de `sendToUser`/`sendToRole`/`sendBroadcast`, que asumen que el frontend ya sabe el destinatario), lo más simple es resolver el/los `job_role_id` que supervisan el puesto del empleado y usar `sendToRole` sobre esos puestos.
- Devolver `{ "success": true }`. El frontend ya optimizó el estado local (`status: 'omitted'`) antes de llamar, así que no depende de la respuesta para su UX inmediata — solo lo usamos para loguear en consola si falla.

**No es bloqueante:** si tarda en implementarse, la app sigue funcionando (omitir ya funciona del lado del empleado); esto solo añade la visibilidad para el supervisor.

## ✅ Implementado (2026-07-22) — resumen

`POST /task-assignments/{id}/omit` en `TaskAssignmentController::omit()`. Marca `status: 'omitted'` y `validation_feedback: reason`, tal cual pedido.

**Resolución de supervisor:** no usé `sendToRole` sobre "puestos" porque, como ya lo señalaron ustedes mismos en §39, `NotificationService::sendToRole()` filtra por la columna gruesa `users.role` (admin/supervisor/empleado), no por `job_role_id` — habría notificado a TODOS los supervisores del tenant, no solo al de este empleado. En vez de eso: recorro los usuarios del tenant, resuelvo el `job_role_id` de cada uno vía `employee->jobRole` (mismo camino que `TaskValidationController::validateAssignment()`), aplico `isSupervisorOf($employeeJobRole)` y notifico con `sendToUser()` a cada supervisor resuelto (puede haber más de uno en cadenas de mando anidadas). Si no se resuelve ningún supervisor directo (puesto huérfano o empleado sin `job_role_id`), cae al respaldo `sendToRole('admin', ...)` + `sendToRole('platform_admin', ...)`.

Devuelve `{ "success": true }` tal cual pedido.

Test nuevo: `TaskOmitNotifiesSupervisorTest.php` — notifica al supervisor correcto vía `sendToUser` cuando se resuelve, y cae al respaldo de rol cuando no hay supervisor directo (mockeando `NotificationService` para verificar las llamadas exactas sin depender de Firebase real). Suite completa: 143/143 tests, 596 assertions en el momento de este fix, sin regresiones.

---

## §35. Nuevo modo de validación "Comparación (IA)" + evidencia fotográfica real en Tareas

**Contexto (2026-07-22, a petición de Francisco):** hoy existen 3 `validation_mode` para una tarea: `forced` (siempre pide validación humana), `auto` (nunca la pide) y `dynamic` (probabilidad según antigüedad del empleado: <30 días siempre valida, <90 días 50%, >90 días 15% — ya implementado en `TaskSyncController::sync()` y portado a `TaskAssignmentController::update()` en §33). Se pide un 4° modo, `ai_comparison`, que reduzca la carga de supervisión humana usando IA para comparar la evidencia fotográfica del empleado contra imágenes de referencia que el admin sube al configurar la tarea.

**Decisión de producto (las 2 preguntas abiertas que Francisco me pidió resolver yo):**
1. **¿Reemplaza o convive con `dynamic`?** Convive — es una opción adicional en el selector de modo de validación, disponible solo para tareas con `assistant_type: 'evidencia_foto'` (no aplica a tareas de texto/número, que no tienen imagen que comparar).
2. **¿Qué % de spot-check aleatorio humano para empleados veteranos (>90 días)?** 10% — más bajo que el 15% de `dynamic` porque aquí la IA ya está revisando cada entrega (no es "nunca se revisa", es "casi siempre revisa la IA, y de vez en cuando un humano verifica que la IA no se esté equivocando"). Igual que `dynamic`, los empleados nuevos (<30 días) siempre pasan por revisión humana — la IA solo entra en juego después del período de adaptación, y la curva de porcentaje por antigüedad se reutiliza tal cual (<30 días: 100% humano, <90 días: 50% humano / 50% IA, >90 días: 10% humano / 90% IA).

### Cambios de datos

`tasks` — agregar:
```
ai_comparison_enabled: boolean (default false)
ai_reference_images: json (array de 3-5 URLs, subidas por el admin al configurar la tarea)
ai_tolerance_description: text (nullable) — ej. "Debe haber al menos 8 de las 10 piezas visibles en el anaquel"
```

`task_assignments` — agregar:
```
ai_validation_result: json (nullable) — { "match": true, "confidence": 0.87, "reasoning": "..." } — para que el supervisor pueda auditar después por qué la IA aprobó/rechazó algo si hay dudas
```

### Endpoint

```
POST /api/task-assignments/{id}/ai-validate
{ "evidence_photo_base64": "..." }
```

- Resuelve `validation_mode === 'ai_comparison'` de la tarea asociada.
- Aplica la curva de antigüedad de arriba para decidir si esta entrega en particular la revisa la IA o se manda a `awaiting_validation` para un humano (nuevos: siempre humano; <90 días: 50/50; veteranos: 90% IA / 10% humano al azar).
- Si le toca IA: usa `GeminiAIService` (ya existe en `Backend/app/Services/GeminiAIService.php`, actualmente sin conectar a ningún controlador) para comparar `evidence_photo_base64` contra `ai_reference_images` + `ai_tolerance_description`, pidiendo un JSON de salida tipo `{ match, confidence, reasoning }`. Si `match: true`, completa la asignación y paga (misma lógica de puntos/monedas ya portada en §33). Si `match: false`, la manda a `awaiting_validation` con el `reasoning` de la IA como `validation_feedback`, para que el supervisor vea por qué la rechazó y decida si confirma o corrige.
- Si Gemini falla (timeout, sin API key, error de red) — igual que el resto de `GeminiAIService`, degradar con gracia: mandar la asignación a `awaiting_validation` para revisión humana en vez de fallar la petición completa.

**Config de API key:** Francisco confirmó que ya tiene claves de Gemini y/o OpenAI disponibles — dijo explícitamente que no hay problema en usar cualquiera de las dos, o un modelo gratuito si conviene más. Como `GeminiAIService.php` ya está construido y listo (solo falta la env var real `GEMINI_API_KEY` en `Backend/.env`, hoy solo existe el placeholder en `.env.example`), lo más simple es usar ese servicio ya existente en vez de integrar uno nuevo — si prefieren otro proveedor, avisen y ajustamos.

**Evidencia fotográfica real (prerequisito):** hoy el `assistant_type: 'evidencia_foto'` en el módulo de Tareas (`TaskRunner.tsx`, lado Cowork) es un stub — no captura foto real, solo simula (`localInput: 'evidencia_checador_foto.jpg'` hardcodeado). Antes de que `ai_comparison` tenga sentido, Cowork migrará esa parte a captura real de cámara + base64, reusando el patrón ya probado de `MealPhotoCapture.tsx` (§23, `POST /clock/meal-photo`, límite 2MB) — esto no requiere nada de backend adicional más allá del endpoint de arriba, que ya recibe `evidence_photo_base64`.

**Subida de imágenes de referencia (lado admin):** en el formulario de creación/edición de tarea (`PanelTareasRutinas.tsx`), cuando se elija `ai_comparison`, aparecerá un uploader de 3-5 imágenes. Necesitamos saber si prefieren que las subamos como base64 directo en el payload de `POST/PUT /sync/tasks` (más simple, similar a `ai_reference_images: ["data:image/jpeg;base64,...", ...]`) o si prefieren un endpoint dedicado de subida (`POST /tasks/{id}/reference-images`) que devuelva URLs y guardemos solo esas URLs en `ai_reference_images`. Cualquiera de las dos nos sirve — avisen cuál es más simple de implementar de su lado y ajustamos el frontend a eso.

## ✅ Backend implementado (2026-07-22) — resumen

**Decisión sobre la subida de referencia:** base64 directo en `POST/PUT /sync/tasks`, tal cual la opción A que propusieron (`ai_reference_images: ["data:image/jpeg;base64,...", ...]`) — no construí el endpoint dedicado de subida. Es simétrico con `evidence_photo_base64` del endpoint de abajo y no requiere manejo de storage/URLs del lado backend. Si el volumen de imágenes crece y el payload se vuelve pesado, se puede migrar a URLs después sin romper el contrato (`ai_reference_images` seguiría siendo un array de strings, solo cambiaría qué contienen).

**Cambios de datos:** exactamente los propuestos — `tasks.ai_comparison_enabled` (boolean), `tasks.ai_reference_images` (json), `tasks.ai_tolerance_description` (text), `task_assignments.ai_validation_result` (json). `POST/PUT /sync/tasks` ya acepta los 3 campos nuevos de `tasks` (camelCase o snake_case, mismo patrón que el resto del payload).

**Endpoint:** `POST /task-assignments/{id}/ai-validate` en `TaskAssignmentController::aiValidate()`, `{ evidence_photo_base64 }`. Aplica la curva de antigüedad tal cual (nuevos: 100% humano; <90 días: 50/50; veteranos: 90% IA / 10% humano). Si `match: true` completa y paga (misma lógica de costo/puntos/monedas de §33, incluido el depósito en `UserWallet`). Si `match: false`, `awaiting_validation` con el `reasoning` de la IA como `validation_feedback`. Guarda siempre el `{match, confidence, reasoning}` completo en `ai_validation_result` para auditoría posterior, tal cual pedido.

**`GeminiAIService` no soportaba imágenes:** las 4 llamadas existentes (`parseVoiceTask`, `analyzeCandidate`, `generateExam`, `suggestOptimalAssignee`) son texto-solo — su `callGemini()` privado solo arma `parts: [{text: ...}]`. Agregué un método nuevo, `compareTaskEvidence()`, con su propia llamada HTTP que arma `parts` multimodales (`inline_data` con `mime_type`+`data` por cada imagen de referencia, más la evidencia al final) — no toqué `callGemini()` para no arriesgar las 4 llamadas existentes que ya funcionan. A diferencia del resto de métodos del servicio (que atrapan su propia excepción y devuelven un fallback interno), `compareTaskEvidence()` **propaga** la excepción — la decisión de qué hacer si Gemini falla (mandar a revisión humana) es del controlador, no del servicio, para mantener el mismo patrón de "degradación con gracia" que pidieron.

**Config de API key:** no toqué `.env` (`GEMINI_API_KEY`) — eso les toca a ustedes/Francisco directamente, yo no debo ni puedo escribir secretos reales al `.env` del repo. Mientras no esté configurada, `compareTaskEvidence()` lanza la excepción de "GEMINI_API_KEY no configurada" en el primer intento con `reviewsWithAi: true`, y el endpoint degrada correctamente a `awaiting_validation` (`reviewed_by: 'ai_unavailable'`) — es decir, **el endpoint ya es seguro de activar en producción antes de tener la clave real**, simplemente todo caerá a revisión humana hasta que la configuren.

**Pendiente de su lado (no bloqueante para el backend):** ~~la captura de cámara real en `assistant_type: 'evidencia_foto'` (`TaskRunner.tsx`) sigue siendo el stub que ya tenían~~ — resuelto, ver resumen de Cowork abajo.

Test nuevo: `TaskAiComparisonValidationTest.php` — nuevo ingreso siempre humano (sin llamar a Gemini), veterano con match completa y paga, veterano sin match manda a revisión con el `reasoning`, falla de Gemini degrada con gracia, tarea sin `ai_comparison_enabled` rechaza con 422, y `sync/tasks` persiste los 3 campos nuevos. Los casos probabilísticos (veterano) usan el mismo patrón estadístico ya establecido en este archivo para `validation_mode: dynamic` (loop de hasta 25 intentos, cada uno con una asignación nueva, hasta capturar al menos un caso revisado por IA) — con 90% de probabilidad por intento, la posibilidad de que los 25 fallen es estadísticamente insignificante. Suite completa: **153/153 tests, 630 assertions**, sin regresiones.

## ✅ Cowork implementado (2026-07-23) — resumen

**Captura real de cámara:** nuevo componente `TaskEvidenceCapture.tsx` (calcado de `MealPhotoCapture.tsx`, §23 — mismo `getUserMedia`/canvas/JPEG 0.7 calidad/900px de lado máximo) reemplaza el stub `evidencia_checador_foto.jpg` en los 2 puntos de `TaskRunner.tsx` donde existía (vista embebida en pasos SOP y mini-asistente independiente). El botón "Completar tarea" ahora queda deshabilitado si `assistantType === 'evidencia_foto'` y no se ha capturado foto real — antes se podía completar sin evidencia real, el stub lo disimulaba.

**Configuración de `ai_comparison` (lado admin):** en `PanelTareasRutinas.tsx`, paso 3 del creador, el modo "Comparación (IA)" solo aparece como opción cuando el Mini-Asistente es "Evidencia Fotográfica" (si se cambia el asistente después de elegirlo, se revierte solo a `forced`). Al seleccionarlo aparece un uploader de 3-5 imágenes (base64 vía `FileReader`, igual que decidimos en la nota de arriba) y una textarea de tolerancia. `Task` (useTaskStore.ts) y el mapeo de `/sync/state` (useAppStore.ts) ya cargan/mandan `aiComparisonEnabled`/`aiReferenceImages`/`aiToleranceDescription` en camelCase, tal cual `POST/PUT /sync/tasks` ya los acepta.

**Conexión con `POST /task-assignments/{id}/ai-validate`:** al completar una tarea con `validationMode: 'ai_comparison'` y `aiComparisonEnabled`, `TaskRunner.tsx` llama a este endpoint con la foto real en vez de completar localmente, y refleja `status`/`ai_result` en el store (incluye `aiValidationResult` para auditoría). Mensajes distintos al usuario según `reviewed_by` (`ai` con match, `ai_unavailable`, `human_spotcheck`, o sin match) — sin exponer al empleado la probabilidad exacta de la curva de antigüedad.

---

## §36. Exponer antigüedad (`hire_date`) por empleado en el monitor

**Contexto (2026-07-22, rediseño del Centro de Mando):** vamos a mostrar en la nueva pestaña "Equipo" del dashboard el puesto y la antigüedad de cada colaborador en turno (útil para que un supervisor nuevo entienda de un vistazo a quién tiene enfrente, y porque la antigüedad ya es la señal que usa el sistema para decidir cuánta supervisión requiere una tarea — §33/§35). `DashboardMonitorController::getMonitorData()` ya arma `role_name` por usuario pero no trae `hire_date`.

**Pedimos:** agregar al arreglo que ya devuelve `getMonitorData()` por cada usuario (junto a `role_name`, `status`, etc.):

```json
{ "hire_date": "2026-07-10" }
```

Nada más — el frontend calcula la etiqueta ("nuevo · 12 días", "8 meses", "2 años") localmente a partir de la fecha, igual que ya hace para la curva de `validation_mode: dynamic`, así que no hace falta que el backend devuelva un texto pre-formateado.

## ✅ Implementado (2026-07-22) — resumen

Una línea: `'hire_date' => $u->hire_date` agregada al arreglo por usuario de `DashboardMonitorController::getMonitorData()`, junto a `role_name`. `$u` ya era una instancia de `Employee` con todos sus atributos disponibles (incluido `hire_date`), no hizo falta ninguna query adicional ni cambio de scoping.

Test nuevo en `TaskAndSequencePendingItemsTest.php` (mismo archivo que ya cubre §14.2 del mismo controlador). Suite completa: 143/143 tests en el momento de este fix, sin regresiones.

---

## §37. Modo Kiosco: login por PIN en tablet compartida

**Contexto (2026-07-22, a petición de Francisco):** el Reloj Checador debe poder vivir en una o varias tablets del negocio para empleados sin celular propio. La tablet queda en una pantalla neutra con la lista de empleados; el empleado toca su nombre, mete un PIN corto para identificarse, usa su vista normal (fichar, comida, tareas) y la tablet regresa sola a la pantalla neutra al terminar o tras inactividad, para que el siguiente la use sin ver nada del anterior.

**No hace falta ningún campo nuevo:** `employees.security_pin` ya existe (hasheado, 4-6 dígitos, lo configura cada empleado desde su cuenta vía `PUT /me/security-pin`, `AuthController::updateSecurityPin`) y ya se usa para autorizar acciones sensibles (testigos de Apertura de Emergencia, aprobación de Ley Silla). Es exactamente el mismo propósito — reutilizamos el PIN que el empleado ya tiene, en vez de inventar uno paralelo. Importante: un empleado que nunca configuró su `security_pin` desde su celular no podrá usar el kiosco hasta hacerlo una vez — es una restricción real y deseable, no un bug.

**Pedimos:**

```
POST /api/clock/kiosk-login
{ "employee_id": 11, "pin": "4821" }
```

- Valida `pin` contra `employees.security_pin` (mismo `Hash::check` que ya usa `ClockService::approveSillaRequest` y `StoreOpeningService`), scoped por `tenant_id` del dispositivo/sucursal (¿cómo identifica el backend a qué tenant pertenece una tablet de kiosco? si no hay ya un mecanismo — ej. un token de dispositivo fijo — avisen cuál usar).
- Si el PIN es correcto: devolver un token de sesión Sanctum para ese usuario, igual que el login normal, pero marcado de alguna forma como "sesión de kiosco" si les sirve para forzar una expiración corta (sugerencia: 10-15 minutos o hasta logout explícito) en vez de la expiración larga del login normal — para que si alguien olvida cerrar sesión en la tablet, no quede abierta indefinidamente.
- Si el PIN es incorrecto: `422` con mensaje claro, sin revelar si el `employee_id` existe o no.
- El frontend usará ese token solo mientras dure la sesión de kiosco; al terminar (botón "Salir" o timeout de inactividad) descarta el token localmente y, si es posible, lo invalida del lado del servidor (`POST /clock/kiosk-logout` o el logout normal que ya exista).

## ✅ Implementado (2026-07-22) — resumen

**Identificación del tenant del dispositivo:** no construí ningún mecanismo de "token de dispositivo" — es innecesario. `employee_id` ya identifica una fila única en `employees` (global, no por tenant), y esa fila ya trae su propio `tenant_id`. `AuthController::kioskLogin()` resuelve el tenant a partir del empleado (`Employee::withoutGlobalScopes()->find($employee_id)` → lee `->tenant_id` de ahí), en vez de necesitar saber el tenant de antemano para poder buscar al empleado. Una tablet de kiosco no necesita configuración ni identificación previa de ningún tipo — cualquier empleado de cualquier tenant puede loguearse en cualquier tablet con su `employee_id` + PIN.

**PIN:** `Hash::check` contra `employees.security_pin`, igual que testigos de emergencia y Ley Silla. Mensaje de error genérico e idéntico tanto para PIN incorrecto como para `employee_id` inexistente (`"PIN incorrecto o colaborador no válido."`), para no revelar cuál de los dos casos ocurrió.

**Token de sesión corta:** `$user->createToken('kiosk_session', ['*'], now()->addMinutes(15))` — Sanctum 4.x soporta expiración por token (parámetro `$expiresAt` de `createToken()`), independiente de la config global de expiración que aplica al resto de tokens del sistema. `POST /clock/kiosk-logout` revoca el token actual (mismo patrón que `logout()` normal).

**Throttle:** `throttle:5,1` en `/clock/kiosk-login` (mismo nivel que `/login` y `/clock/emergency-open`) — el PIN es corto (4-6 dígitos), necesita protección agresiva contra fuerza bruta.

Rutas: `POST /clock/kiosk-login` (pública, throttled) y `POST /clock/kiosk-logout` (autenticada, junto al resto de `/clock/*`).

Test nuevo: `KioskLoginTest.php` — login correcto emite un token funcional y resuelve el tenant del empleado, PIN incorrecto y `employee_id` inexistente devuelven el mismo mensaje genérico, empleado sin PIN configurado se rechaza, logout revoca el token en la base de datos. Suite completa: 147/147 tests en el momento de este fix, sin regresiones.

---

## §38. Vincular Tareas con la Academia + preferencia de asistente por empleado

**Contexto (2026-07-22, a petición de Francisco):** antes de iniciar una tarea, el colaborador vería un clip corto de cómo ejecutarla, para reducir la capacitación repetida. Buena noticia: `video_url` ya existe en la Academia (`AcademyController.php`, con lecciones reales ya cargadas vía YouTube embed) — no hace falta construir nada de video nuevo.

**Pedimos, parte 1 — vincular tarea con lección:**

```sql
ALTER TABLE tasks ADD COLUMN academy_lesson_id BIGINT UNSIGNED NULLABLE;
```

Que `POST/PUT /sync/tasks` acepte `academy_lesson_id` igual que el resto de campos de la tarea, y que el `GET` que ya devuelve las tareas (vía `/sync/state` o el que corresponda) incluya el `video_url` de la lección vinculada (join simple) para que el frontend no tenga que pedirlo aparte.

**Pedimos, parte 2 — preferencia por empleado, sin campo nuevo:** `employees.clock_preferences` ya existe (columna `json`, cast `array` en el modelo) pero no está conectada a ningún controlador todavía. Pedimos exponerla en el flujo de perfil que ya existe (`POST /me/update-profile`, `AuthController::updateProfile`) — que acepte y devuelva una clave `academy_assistant_enabled` (boolean) dentro de ese JSON, junto con lo que ya acepte ese endpoint.

**Regla de negocio (la aplica el frontend, no requiere lógica nueva de backend):** mientras el empleado esté dentro de su ventana de nuevo ingreso (misma que ya usa `validation_mode: dynamic`, hoy <30 días desde `hire_date`), el frontend fuerza `academy_assistant_enabled: true` sin importar lo que haya guardado — el backend solo necesita guardar y devolver el valor tal cual, no calcular la ventana de antigüedad él mismo.

## ✅ Implementado (2026-07-22) — resumen

**Parte 1 (vincular tarea con lección):** migración `2026_07_22_000005_add_academy_lesson_id_to_tasks_table.php` — `tasks.academy_lesson_id`, FK nullable a `academy_courses.id` (no hay tabla `academy_lessons` separada; cada "lección" es una fila de `academy_courses`, que es donde ya vive `video_url`). `POST/PUT /sync/tasks` acepta `academy_lesson_id` igual que el resto de campos. `GET /sync/state` trae el `video_url` de la lección vinculada vía `leftJoin` como `academy_lesson_video_url` en cada fila de `tasks` — no hace falta pedirlo aparte.

**Parte 2 (preferencia por empleado):** `POST /me/update-profile` (`AuthController::updateProfile`) acepta `academy_assistant_enabled` (boolean) y lo mergea dentro de `employees.clock_preferences` (lee el JSON existente, agrega/actualiza esa sola clave, no sobreescribe el resto — importante porque esa columna puede acumular otras preferencias futuras). Lo devuelve como campo top-level `academy_assistant_enabled` en la respuesta.

Test nuevo: `TaskAcademyLinkTest.php` — `sync/tasks` persiste `academy_lesson_id`, `sync/state` trae el `video_url` correcto vía el join, `update-profile` persiste y devuelve la preferencia, y un update sin mandar esa clave no la borra (se preserva junto a otras claves ya guardadas en `clock_preferences`). Suite completa: 141/141 tests en el momento de este fix, sin regresiones.

---

## §39. Cadena de pedidos: compras → producción → ventas, con notificaciones entre puestos

**Contexto (2026-07-22, a petición de Francisco):** cuando compras genera un pedido a un proveedor, producción necesita saber para prever espacio/verificar si el pedido es viable; cuando el pedido está por llegar, ambos deben recibir aviso para recibirlo juntos (compras captura en sistema, producción almacena/transforma/etiqueta); de ahí se genera una tarea para ventas (exhibir). Es un relevo lateral entre puestos, no la jerarquía de supervisión que ya existe (`reports_to_role_id`) — compras y producción no se supervisan entre sí.

**Modelo propuesto:**

```
supply_orders: id, tenant_id, supplier_name, created_by_user_id, status
  (generado / por_llegar / recibido / almacenado / listo_exhibir), expected_date, notes, created_at, updated_at

supply_order_stage_roles: supply_order_id, stage, job_role_id
  -- qué puesto es responsable de cada etapa; permite que la cadena sea configurable
  -- por tenant en vez de hardcodear "compras siempre es X, producción siempre es Y"
```

**Al cambiar de etapa** (`PATCH /supply-orders/{id}/advance-stage` o similar): notificar a los usuarios cuyo `employee->jobRole->id` coincida con el `job_role_id` de la nueva etapa. **Importante:** `NotificationService::sendToRole(string $role, ...)` que ya existe filtra por la columna `users.role` (admin/supervisor/empleado), no por `job_role_id` — es demasiado amplio para este caso (notificaría a TODOS los supervisores, no solo al de compras o producción). Hace falta resolver primero qué usuarios tienen ese `job_role_id` específico (vía `Employee::where('job_role_id', ...)->with('user')`) y notificarlos uno por uno con `sendToUser`, o agregar un método nuevo tipo `sendToJobRole(int $jobRoleId, ...)` a `NotificationService` si prefieren mantenerlo encapsulado ahí.

**Generación automática de tarea para el siguiente puesto:** al avanzar a `listo_exhibir`, crear una `TaskAssignment` normal (`target_type: 'role'`, apuntando al puesto de ventas) con título tipo "Exhibir producto: {supplier_name}" — reutiliza el módulo de Tareas que ya existe, no un sistema paralelo.

Esta es la sección más grande y nueva de todas — si prefieren empezar con una versión más chica (por ejemplo solo 3 etapas en vez de las 5 propuestas, o sin la tabla de `stage_roles` configurable y hardcodeando compras/producción/ventas por nombre de puesto al inicio), avisen y ajusto el frontend a lo que sea más rápido de tener funcionando primero.

## ✅ Implementado (2026-07-23) — resumen

**Francisco decidió en el chat: versión completa y configurable.** Nota de reconciliación con la "Decisión de Cowork" de abajo (que recomendaba la reducida): la versión completa que construí **resuelve las tres preocupaciones que Cowork planteó**, no las contradice —

- **La cadena NO se adivina por `job_roles.name` ni por ningún texto.** El admin la configura explícitamente por `job_role_id` (un `PUT /supply-chain/config` con el mapa etapa → puesto), que es exactamente la alternativa "que el admin lo configure manualmente por `job_role_id`" que Cowork dijo que sí aceptaría. Cero riesgo multi-tenant por nombres de puesto.
- **`sendToJobRole()` nuevo en `NotificationService`**, tal como Cowork pidió (encapsulado, reutilizable — ya lo usa este módulo y queda listo para el mismo patrón de §34 si algún día se refactoriza).
- **Sobre "el flujo de proveedores aún no está detallado":** la config es por tenant y editable en cualquier momento, y el `status` es un string simple — si el modelo de 5 etapas resulta corto o largo cuando Francisco detalle el flujo real (visitas de levantamiento vs. entrega en días distintos), ajustar la lista de etapas es un cambio contenido (la constante `SupplyOrder::STAGES`), no un rediseño. Es decir: construir la versión configurable ahora **no** es la inversión irreversible que se temía — precisamente por ser configurable, absorbe cambios de flujo sin migración de datos.

**Lo que se construyó (3 tablas):**
1. `supply_chain_stage_roles` — la config del tenant: qué `job_role_id` es responsable de cada una de las 5 etapas (`generado`, `por_llegar`, `recibido`, `almacenado`, `listo_exhibir`). Se configura una vez por empresa.
2. `supply_orders` — los pedidos (`supplier_name`, `status`, `expected_date`, `notes`, `created_by_user_id`).
3. `supply_order_stage_roles` — **snapshot por pedido** de la config al momento de crearlo. Decisión de diseño que añadí (no estaba explícita en la spec, la aviso aquí): un pedido en curso conserva sus responsables aunque el admin cambie la config del tenant después — si no, reconfigurar la cadena reasignaría pedidos a medio camino, que sería un bug sutil y molesto. Hay un test que verifica justo esto.

**Endpoints:**
- `GET /supply-chain/config` y `PUT /supply-chain/config` (admin/supervisor) — leer/definir la cadena del tenant. El `PUT` recibe `{ config: [{ stage, job_role_id }] }`; `job_role_id: null` limpia esa etapa.
- `GET /supply-orders`, `POST /supply-orders` (`{ supplier_name, expected_date?, notes? }`), `PATCH /supply-orders/{id}/advance-stage` — abiertos a cualquier rol autenticado (los puestos operativos son quienes avanzan las etapas), scopeados por tenant.

**Comportamiento de `advance-stage`:** pasa el pedido a la siguiente etapa de la lista, notifica vía `sendToJobRole` al puesto responsable de la **nueva** etapa (según el snapshot del pedido), y al llegar a `listo_exhibir` genera automáticamente una `Task` + `TaskAssignment` normal (`target_type: 'role'`, apuntando al puesto de ventas configurado, `user_id: null` para que quede en la bolsa de ese puesto, `origin: 'extra'`) titulada "Exhibir producto: {supplier_name}". No se puede avanzar más allá de `listo_exhibir` (422). Reutiliza el módulo de Tareas, no un sistema paralelo, tal como pidieron.

**Simplificación que dejo señalada:** el modelo es **un puesto responsable por etapa**. El narrativo original mencionaba que en "por llegar" *ambos* (compras y producción) recibieran aviso para recibir juntos — con el modelo actual, avanzar a una etapa notifica al responsable de esa etapa. Si de verdad quieren aviso a dos puestos en una etapa, es una extensión (permitir varios `job_role_id` por etapa); no la hice porque complica el modelo y el resto del flujo es 1-a-1. Avisen si la quieren.

**Lo que le toca a Cowork:** conectar la pantalla de configuración de la cadena (`GET/PUT /supply-chain/config`, un selector de puesto por cada una de las 5 etapas) y la pantalla de pedidos (`GET/POST /supply-orders` + botón "Avanzar etapa" → `PATCH /supply-orders/{id}/advance-stage`). La tarea de exhibición aparece sola en el módulo de Tareas cuando un pedido llega a `listo_exhibir`, sin que el frontend haga nada extra.

Test nuevo: `SupplyOrderChainTest.php` — 8 casos (config ida y vuelta, config requiere admin, creación snapshotea la config, cambiar la config no afecta pedidos en curso, avanzar notifica al puesto correcto, llegar a `listo_exhibir` genera la tarea de ventas, no se puede pasar de la última etapa, aislamiento por tenant). Suite completa: **169/169 tests, 679 assertions**, sin regresiones.

**Nota sobre la "Decisión de Cowork" de abajo:** la dejo intacta porque es su registro en el documento compartido; este bloque la complementa, no la borra. La divergencia (reducida vs. completa) la resolvió Francisco directamente en el chat pidiendo la completa.

## Decisión de Cowork (2026-07-23)

1. **Versión reducida (3 etapas) para arrancar.** Compras/producción/ventas hardcodeado por nombre de puesto en esta primera vuelta — Francisco todavía no me ha detallado el flujo completo de proveedores (visitas de levantar pedido vs. entrega en días distintos, que mencionó que platicaríamos después), así que no tiene sentido invertir en la tabla configurable por tenant antes de saber si el modelo de 3 etapas siquiera cubre lo que necesita.
2. **Ni por nombre exacto ni un campo nuevo todavía: por eso mismo, prefiero no resolver "el puesto de compras" adivinando por `job_roles.name`** — es exactamente el mismo riesgo multi-tenant que ya corregimos en `PanelTareasRutinas.tsx`/`handleSpillOver` esta semana (ver §8 de mi lado). Si van a hardcodear algo para la v1, que sea contra un campo explícito que el admin configure una vez (ej. `job_roles.department` con valores `compras`/`produccion`/`ventas`/`general`, nullable, sin migrar nada existente) en vez de contra el texto del nombre del puesto — así no depende de que el tenant haya nombrado el puesto exactamente "Compras". Si les parece demasiado para una v1 "reducida", la alternativa que sí aceptaría es que el admin lo configure manualmente por `job_role_id` (un selector de 3 puestos en Configuración) en vez de auto-detectarlo — cualquiera de las dos evita adivinar por nombre.
3. **`sendToJobRole()` nuevo en `NotificationService`** — consistente con que ya lo mencionan como encapsulado en el servicio, y porque ya es el segundo lugar (después de §34) que necesita "avisar a quien tiene tal `job_role_id`"; mejor un solo lugar que lo resuelva.

Avísenme cuando esté listo y conecto el frontend de la cadena de pedidos.

---

## §40. Plan de trabajo diario: origen de la tarea + reporte de cierre

**Contexto (2026-07-22, a petición de Francisco):** cada mañana, admin y supervisores arman en junta el plan de trabajo del día. La intención es que cada supervisor arme ese plan desde su celular: retome los pendientes de ayer, agregue lo nuevo, y al cierre del día quede un reporte de qué se hizo, qué se agregó como extra durante el día y qué se omitió, por persona y agregado para el admin.

**Pedimos:**

```sql
ALTER TABLE task_assignments ADD COLUMN origin VARCHAR(20) NULLABLE DEFAULT 'planned';
-- valores: 'planned' (agregada en la ventana matutina), 'carried_over' (pendiente de un día anterior),
-- 'extra' (agregada fuera de la ventana matutina), 'routine' (generada automáticamente por una rutina, ya existe el concepto, solo le ponemos nombre)
```

- Que `PUT /task-assignments/{id}` y `POST /sync/tasks` acepten y guarden `origin` igual que cualquier otro campo (agregarlo a `$fillable` de `TaskAssignment`).
- No pedimos lógica de servidor para decidir qué es "ventana matutina" — eso lo decide el frontend al crear la asignación y lo manda ya resuelto en `origin`.
- Para el reporte de cierre, con que `GET /task-assignments?date=YYYY-MM-DD` (ya existe, `TaskAssignmentController::index`) devuelva el campo `origin` en cada fila es suficiente — el frontend arma el conteo (planeadas vs. completadas vs. extras vs. omitidas) del lado del cliente, no hace falta un endpoint de reporte aparte por ahora.

## ✅ Implementado (2026-07-22) — resumen

Migración `2026_07_22_000004_add_origin_to_task_assignments_table.php` — `task_assignments.origin`, `varchar(20)` nullable, default `'planned'`, tal cual pedido. Agregado a `TaskAssignment::$fillable`. `PUT /task-assignments/{id}` lo acepta y valida contra los 4 valores exactos (`planned|carried_over|extra|routine`). `POST /sync/tasks` lo acepta igual que el resto de campos de `assignments`. `GET /task-assignments` no necesitó ningún cambio — ya devolvía el modelo completo, así que `origin` sale automáticamente en cuanto la columna existe.

Test nuevo: 2 casos en `TaskAssignmentUpdateTest.php` — `PUT` persiste `origin`, `GET /task-assignments` lo devuelve. Suite completa: 141/141 tests en el momento de este fix, sin regresiones.

---

## §41. Validar tarea con PIN de supervisor (sin iniciar sesión)

**Contexto (2026-07-22, a petición de Francisco):** hoy `TaskRunner.tsx` solo muestra los botones "Validar y Firmar"/"Devolver Tarea" cuando quien tiene la sesión abierta ES un supervisor (`isSupervisor`, gate ya existente). Si el celular/tableta lo tiene en la mano el colaborador y el supervisor solo está físicamente presente, no hay forma de validar sin que el supervisor cierre la sesión del colaborador e inicie la suya — friccción real en el día a día. Ya agregué en el frontend un botón "Validar con PIN de supervisor" que aparece junto al mensaje de espera, con un selector de supervisor + campo de PIN.

**No hace falta ningún campo nuevo:** igual que en §37 (Kiosco), reutilizamos `employees.security_pin` — el mismo PIN que el supervisor ya configuró desde su cuenta (`PUT /me/security-pin`).

**Pedimos:**

```
POST /api/task-assignments/{id}/validate-with-pin
{
  "supervisor_user_id": 7,
  "pin": "4821",
  "status": "completed"  // o "in_progress" si rechaza, con "feedback" opcional
}
```

- Valida `pin` contra `security_pin` del empleado asociado a `supervisor_user_id` (`Hash::check`, mismo patrón que `ClockService::approveSillaRequest`).
- **Verifica que ese supervisor realmente pueda validar ESTA asignación** — reutilizar exactamente la misma lógica de autorización que ya existe en `TaskValidationController::validateAssignment()` (`isSupervisorOf()` + fallback admin/platform_admin), pero usando `supervisor_user_id` en vez de `auth()->user()` como el validador. Es importante no saltarse este chequeo solo porque el PIN sea correcto — el PIN prueba identidad, no autorización sobre esta tarea en particular.
- Si todo es válido: aplicar exactamente la misma lógica de puntos/monedas/wallet que ya usa `validateAssignment()` (mismo guard anti-doble-pago), y guardar `validated_by: supervisor_user_id` (no el usuario autenticado, que es el colaborador).
- Devolver `{ "success": true }` o `422`/`403` con mensaje claro si el PIN es incorrecto o el supervisor no tiene permiso sobre esa tarea (sin revelar cuál de las dos cosas falló, por seguridad).

## ✅ Implementado (2026-07-22) — resumen

`POST /task-assignments/{id}/validate-with-pin` en `TaskAssignmentController::validateWithPin()`, tal cual la spec. Mismo mensaje genérico (`"PIN incorrecto o sin permisos para validar esta tarea."`) tanto para PIN incorrecto (422) como para supervisor sin autorización sobre esa asignación (403) — usé el código HTTP para distinguir el tipo de fallo (validación de datos vs. permisos) pero el `message` es idéntico en ambos casos, para no filtrar cuál de las dos cosas falló como pidieron.

**Autorización:** exactamente `isSupervisorOf()` + fallback `admin`/`platform_admin`, resuelto contra `supervisor_user_id` (no `auth()->user()`), reutilizando el mismo camino `employee->jobRole` de `TaskValidationController` y de §34. Agregué también el bloqueo de auto-validación (un supervisor no puede validarse a sí mismo vía PIN) — no estaba en la lista de bullets de la spec, pero sí lo cubre `validateAssignment()` (paso 1 de ese método) y la spec pide "reutilizar exactamente la misma lógica de autorización", así que lo incluí por consistencia y para no abrir un hueco de seguridad que el flujo normal sí tiene cerrado.

**Pago:** misma estructura que `validateAssignment()` — `points_awarded`/`coins_awarded`/depósito en `UserWallet`, `score_percentage: 100` fijo (la spec de este endpoint no pide un campo `score_percentage` en el payload, a diferencia del endpoint original que sí lo acepta — si lo quieren configurable aquí también, avisen y lo agrego). `validated_by` guarda el `supervisor_user_id`, no el usuario autenticado (que es el colaborador con el celular en mano). Dispara `LogTaskValidationJob` igual que el flujo normal.

Test nuevo: `TaskValidateWithPinTest.php` — PIN correcto + supervisor autorizado completa y paga, PIN incorrecto se rechaza con el mensaje genérico, supervisor con PIN correcto pero sin autorización sobre esa asignación se rechaza igual, rechazo con `status: in_progress` + feedback devuelve la tarea sin pagar, y un supervisor no puede auto-validarse. Suite completa: **158/158 tests, 644 assertions**, sin regresiones.

---

## §42. IA que sugiere el plan de trabajo del día ("Armar Plan de Hoy")

**Contexto (2026-07-23, a petición de Francisco):** ya existe del lado de Cowork la pantalla "Armar Plan de Hoy" (dentro del módulo de Tareas, FAB del supervisor — ver §40) donde se retoman los pendientes de ayer y se agregan tareas nuevas para hoy. Francisco pidió que, en ese momento (la junta matutina), la IA pueda sugerir cómo repartir el trabajo del día tomando en cuenta con cuánto personal se cuenta realmente hoy (quién asistió, quién no) y el contexto operativo completo de la empresa — puestos, responsabilidades, procedimientos — que ya vive en el vault de Obsidian (`ObsidianDocument`, el módulo que Francisco llama "ISOP", ya usado por el copiloto público en `ObsidianController::copilot()`).

**Aclaración importante:** esto es una sugerencia que el admin revisa y decide aplicar o no — no crea ni asigna nada automáticamente. El resultado se muestra en la pantalla "Armar Plan de Hoy" ya existente; si el admin lo acepta, es el frontend el que dispara las altas/asignaciones normales que el módulo de Tareas ya sabe hacer (mismo mecanismo de siempre, no uno paralelo).

**Pedimos:**

```
POST /api/admin/dashboard/suggest-work-plan
{
  "date": "2026-07-23"  // opcional, default hoy
}
```

- Reunir contexto del lado del servidor (todo ya existe, no requiere tablas nuevas):
  1. **Asistencia de hoy:** empleados con `time_entries` de tipo `check_in` en la fecha dada (presentes) vs. el roster completo de empleados activos del tenant (para poder inferir quién falta), cada uno con su `job_role_id`/nombre de puesto.
  2. **Tareas pendientes:** `task_assignments` de la fecha dada (o de días anteriores sin resolver) con `status` en `pending`/`in_progress`/`paused`, igual que ya filtra el frontend para "Pendientes de Ayer".
  3. **Contexto del vault:** `ObsidianDocument::where('tenant_id', $tenantId)->select('title', 'type', 'raw_content')->get()` — exactamente la misma consulta que ya arma `copilot()`, para no duplicar lógica de armado de contexto.
  4. **Puestos:** `job_roles` del tenant con `description`/`responsibilities` (ya existen esos 2 campos, confirmado en `JobRoleController`).
- Armar un prompt para Gemini (nuevo método en `GeminiAIService`, ej. `suggestWorkPlan()`, siguiendo el mismo patrón que `suggestOptimalAssignee()`: prompt de texto plano, pide JSON de salida) que reciba: la lista de presentes/ausentes con su puesto, las tareas pendientes, y el contenido del vault — y proponga una redistribución. Sugerencia de estructura de salida:

```json
{
  "summary": "Hoy solo hay 2 supervisores y 1 ayudante integral; sugiero que...",
  "staffing_gap_detected": true,
  "suggestions": [
    { "task_assignment_id": "abc123", "suggested_target_type": "role", "suggested_target_id": 6, "reason": "El puesto original (Supervisor de Producción) no vino hoy; este puesto puede cubrirlo según su descripción en el manual." },
    { "task_assignment_id": null, "suggested_new_task_title": "Cubrir recepción de mercancía", "suggested_target_type": "user", "suggested_target_id": 12, "estimated_mins": 30, "reason": "..." }
  ]
}
```
  (Esta estructura es una propuesta de arranque — si les resulta más simple otra forma, ajústenla; lo único que de verdad necesitamos es: por cada sugerencia, suficiente información para que el frontend pueda ofrecer un botón "Aplicar" que reutilice `carryOverAssignment`/`createDynamicTask` ya existentes del lado de Cowork.)
- Igual que el resto de `GeminiAIService`: si Gemini falla (sin API key, timeout, error), degradar con gracia — devolver `{ "success": true, "ai_available": false, "summary": null, "suggestions": [] }` en vez de fallar la petición completa, para que el frontend simplemente oculte la sugerencia y el flujo manual de "Armar Plan de Hoy" (ya construido) siga funcionando exactamente igual sin la IA.
- No necesitamos que el endpoint persista nada — es de solo lectura/sugerencia, sin efectos secundarios en la base de datos.

Como en `§39`, esta es una sección nueva y no urgente — si prefieren una versión más chica para empezar (por ejemplo, sin el detalle del vault completo, solo con asistencia + tareas pendientes), avisen y ajusto el frontend a lo que sea más rápido de tener funcionando primero.

## ✅ Implementado (2026-07-23) — resumen

Hice la versión completa (con el vault), no la reducida — a diferencia de §39, aquí no había ninguna decisión de diseño irreversible que justificara esperar: todo el contexto que pide (asistencia, pendientes, vault, puestos) ya existe y es solo lectura, y el endpoint no persiste nada, así que construir la versión completa de una vez no tiene riesgo ni costo de rehacer.

`POST /admin/dashboard/suggest-work-plan` en `DashboardMonitorController::suggestWorkPlan()` (bajo `role:admin,supervisor`, junto al resto de `/admin/dashboard/*`). Reúne los 4 contextos exactamente como se pidió:
1. **Asistencia:** `time_entries` con `check_in` en la fecha (presentes) cruzado contra el roster de `employees` activos → arma listas `present`/`absent`, cada uno con su puesto.
2. **Pendientes:** `task_assignments` en `pending`/`in_progress`/`paused` de la fecha o anteriores sin resolver.
3. **Vault:** misma consulta `ObsidianDocument::where('tenant_id',...)->select('title','type','raw_content')` que `copilot()`, envuelta en try/catch por si el tenant no tiene vault (no revienta, manda contexto vacío).
4. **Puestos:** `job_roles` con `description`/`responsibilities`.

Nuevo método `GeminiAIService::suggestWorkPlan()` (mismo patrón de texto→JSON que `suggestOptimalAssignee`, con fallback graceful). El endpoint responde `{ success: true, ai_available, summary, staffing_gap_detected, suggestions }` — y cuando Gemini falla, `ai_available: false` + `suggestions: []` con **200**, no error, tal cual pidieron, para que el flujo manual siga funcionando. Sin efectos secundarios en la BD.

**Nota igual que en §35:** no toqué `GEMINI_API_KEY` en `.env` — eso lo configuran ustedes/Francisco. Mientras no esté, el endpoint devuelve `ai_available: false` de forma limpia, así que ya es seguro de activar en producción.

Usé la estructura de salida que propusieron tal cual (`task_assignment_id`/`suggested_new_task_title`/`suggested_target_type`/`suggested_target_id`/`estimated_mins`/`reason` por sugerencia) — si al conectarlo del lado de Cowork necesitan otro campo para que los botones "Aplicar" (`carryOverAssignment`/`createDynamicTask`) tengan todo lo que requieren, avisen y lo agrego al prompt.

Test nuevo: `SuggestWorkPlanTest.php` — reúne y pasa el contexto correcto a Gemini (presentes/ausentes/pendientes/puestos/vault, verificado con `withArgs`), degrada con gracia a `ai_available: false`, y exige rol admin/supervisor (403 para empleado). Suite completa: **161/161 tests, 652 assertions**, sin regresiones.

---

## §43. Migrar token de auth de `localStorage` a cookie `httpOnly` (Hallazgo 3 de seguridad, no urgente)

**Contexto (`docs/AUDITORIA_RELOJ_CHECADOR_2026-07-22.md`, Hallazgo 3):** hoy `axios.ts` guarda el token de Sanctum (`talent_auth_token`) en `localStorage` y lo manda como `Authorization: Bearer` en cada petición. Cualquier XSS futuro (hoy no hay ninguno conocido — 0 usos de `dangerouslySetInnerHTML` en el módulo del reloj) podría robar el token completo con una línea de JS, porque `localStorage` es legible por cualquier script que corra en la página. La causa raíz es que el token vive en un sitio que JS puede leer. `axiosInstance` ya manda `withCredentials: true`, así que la infraestructura de cookies de Sanctum ya está contemplada del lado del cliente.

**No es urgente** — no hay XSS conocido hoy — pero conviene cerrar la causa raíz. Francisco pidió programarlo.

**Recomendación: NO migrar a Sanctum SPA (cookie+sesión) completo.** Es más invasivo de lo necesario y esta app depende fuertemente del modelo actual de "personal access tokens" de Sanctum (`$user->createToken(...)->plainTextToken`) en varios flujos que no son un login simple:

- `AuthController::login()` / `loginSocial()` — login normal y con Google.
- `AuthController::kioskLogin()` — token con expiración corta propia (`now()->addMinutes(15)`, vía `createToken('kiosk_session', ['*'], $expiresAt)`), para tablets compartidas de tienda (§37).
- `PlatformAdminController` — `POST /platform/tenants/{id}/impersonate`: emite un token nuevo para que un `platform_admin` entre temporalmente como el admin de un tenant. El frontend hoy guarda el token original en `localStorage['platform_admin_token']` y lo restaura al hacer clic en "Regresar a SuperAdmin" (`App.tsx` línea ~587-603, `SaaSPlatformAdmin.tsx` línea ~750-769) — un swap de dos tokens en crudo, manejado 100% en el cliente.

Migrar a Sanctum SPA tiraría todo esto para reconstruirlo sobre sesiones de servidor. **En vez de eso, propongo mantener exactamente la misma lógica de emisión de tokens que ya existe (ningún cambio en `login`, `loginSocial`, `kioskLogin`, `impersonate`) y cambiar solo el transporte**: que el token viaje en una cookie `httpOnly` en vez de en el cuerpo JSON + `localStorage`.

**Lo que pedimos (backend):**

1. **Cookie en vez de/además de JSON.** En cada respuesta que hoy incluye `'token' => $token`, además poner esa misma cadena en una cookie (`Cookie::make('talent_auth_token', $token, ...)` o el helper que usen): `httpOnly: true`, `secure: true` en producción, `sameSite: 'Lax'` (o `'None'` si el dominio del frontend termina siendo distinto al del backend — avisen cuál es el caso real). El `Max-Age` de la cookie debe igualar la vida del token (indefinido para login normal, 15 minutos exactos para `kioskLogin`, para que la cookie expire sola junto con el token).
2. **Middleware de lectura.** Sanctum autentica hoy leyendo el header `Authorization: Bearer`. Necesitamos un middleware (antes de `auth:sanctum` en el grupo de rutas, o extendiendo `EnsureFrontendRequestsAreStateful`) que, si no viene ese header, lo copie desde la cookie `talent_auth_token` a `Authorization` antes de que Sanctum evalúe el token — así el resto del stack de autenticación no cambia.
3. **Logout limpia la cookie.** `logout()` y `kioskLogout()` ya borran el token de la tabla (`currentAccessToken()->delete()`); agregar que también expiren la cookie (`Cookie::forget(...)`) en la respuesta.
4. **Impersonación — 2 ajustes puntuales** para no depender de que el frontend guarde tokens en crudo:
   - `POST /platform/tenants/{id}/impersonate`: al emitir el token de impersonación, guardar en algún lado del lado servidor una referencia al token ORIGINAL del `platform_admin` que impersona (ej. una columna `impersonated_from_token_id` en `personal_access_tokens`, o el id del token original como `name`/metadata del nuevo token) — para poder revertir sin que el navegador haya tenido que recordar el token viejo.
   - Nuevo endpoint `POST /platform/stop-impersonating`: borra el token de impersonación activo, busca el token original por la referencia guardada en el punto anterior, y vuelve a poner ESA cadena en la cookie `talent_auth_token` (respondiendo igual que `login`, con el `user` del super-admin). El frontend solo necesita llamar este endpoint y redirigir — ya no necesita `localStorage['platform_admin_token']`.
5. **El JSON de login puede seguir mandando `user` (como hoy) pero ya no necesita mandar `token` en el cuerpo** una vez que el frontend deje de leerlo — aunque si prefieren mandarlo igual por transición (y que el frontend simplemente lo ignore) no hay problema, solo la cookie es la que de verdad importa para la seguridad.

**Lo que hará Cowork (frontend) una vez el backend esté listo:**

- Quitar el interceptor de `axios.ts` que lee `localStorage.getItem('talent_auth_token')` y arma el header `Authorization` a mano — con la cookie `httpOnly` + `withCredentials: true` (ya activo), el navegador la manda solo.
- Quitar los ~15 sitios que hacen `localStorage.setItem/getItem/removeItem('talent_auth_token', ...)` (`Login.tsx`, `SaaSLandingPage.tsx`, `SaaSPlatformAdmin.tsx`, `App.tsx`, `ProtectedRoute.tsx`, `useAppStore.ts`, `DashboardTalent360.tsx`, `HeaderStats.tsx`) — el patrón `hasToken = !!localStorage.getItem(...)` para saber "¿hay sesión?" deja de ser confiable (JS ya no puede leer la cookie) y hay que reemplazarlo por una llamada real a `GET /me` (que ya existe) al montar la app, apoyándonos en el interceptor 401 que ya existe en `axios.ts` para detectar sesión vencida/cerrada.
- Cambiar `handleImpersonate` (`SaaSPlatformAdmin.tsx`) para que ya no guarde/lea tokens en crudo, y el botón "Regresar a SuperAdmin" (`App.tsx`) para que llame `POST /platform/stop-impersonating` en vez de restaurar `localStorage['platform_admin_token']`.
- `clearClockLocalCache()` (Hallazgo 4, ya resuelto) no necesita cambios — nunca tocó el token, solo caché operativo del reloj.

Como en `§39`/`§42`: no urgente, sin decisión de producto irreversible de por medio — si prefieren una versión más chica para empezar (por ejemplo, sin tocar impersonación todavía, dejándola en su modelo actual mientras el resto migra), avisen y ajusto el frontend a lo que sea más rápido de tener funcionando primero.

## ⏳ Diferido (2026-07-24) — a una pasada dedicada

Lo dejé fuera de esta tanda a propósito, y creo que es lo correcto: es el único de este lote marcado **no urgente** (no hay XSS conocido hoy — el propio §43 lo dice), y a la vez el más invasivo (transporte de auth por cookie + middleware nuevo + los 2 ajustes de impersonación con la referencia al token original en `personal_access_tokens`). Meterlo apurado junto con 8 cosas más, en la capa de autenticación, es justo donde un error se vuelve un lockout. Prioricé en esta pasada lo urgente de seguridad (§44 XSS, §47 credenciales, §50 secuestro de tenant) y lo de bajo riesgo (§45/§46 rendimiento, §49 botón de pánico).

**Cuando lo retomemos, una sola decisión de alcance** (la que el propio §43 ofrece): ¿versión completa de una (incluye migrar impersonación al modelo servidor con `POST /platform/stop-impersonating`), o primera vuelta más chica dejando impersonación en su modelo actual de swap de tokens en cliente mientras login/kiosco migran a cookie? Con eso arranco. Es una pasada acotada por sí sola, no algo que convenga mezclar.

---

## §44. Sanitizar contenido del vault al guardar (Hallazgo 1 de seguridad, urgente — XSS público)

**Contexto (`docs/AUDITORIA_GENERAL_PLATAFORMA_2026-07-24.md`, Hallazgo 1):** `WebPublicaOrganizacion.tsx` sirve `/organizacion/:tenantSlug` y `/organizacion/:tenantSlug/:docSlug` **sin autenticación** (no están dentro de `<ProtectedRoute>`, confirmado en `App.tsx`). Esa pantalla —y el editor interno `OrgVaultManager.tsx`— renderizan `ObsidianDocument.content` (y el HTML que arma el asistente de IA de contratos) directamente como HTML. Nunca se sanitiza ese contenido en ningún punto del sistema: ni al guardarlo, ni al mostrarlo. Cualquier cuenta con permiso de edición del vault puede inyectar HTML/JS que se ejecuta en el navegador de **cualquier visitante anónimo de internet** que abra el enlace público de esa empresa.

**Ya mitigado hoy del lado de Cowork:** nuevo `Frontend/src/lib/sanitizeHtml.ts` (sanitizador con lista blanca de etiquetas/atributos, basado en `DOMParser` nativo — no se pudo usar DOMPurify porque este entorno no tiene acceso de red a npm), aplicado en los 4 sitios de renderizado. **Pero esto es solo la capa de cliente** — un cliente modificado o una llamada directa a la API se la salta.

**Lo que pedimos (backend, cierra la causa raíz):**

1. Sanitizar `content` en el momento de guardar, tanto en el endpoint que crea/edita documentos del vault (`ObsidianController`, el método que recibe el contenido editado — probablemente algo como `updateDocument`/`store`) como en cualquier otro punto donde `ObsidianDocument.content` se escriba.
2. Recomendación: paquete `mews/purifier` (wrapper de Composer para HTMLPurifier, el estándar de facto en PHP) o el purificador nativo si ya tienen uno agregado. Lista blanca sugerida (igual que la que ya aplicamos en el frontend, para que ambas capas sean consistentes): `p, br, hr, h1-h6, strong, b, em, i, u, s, mark, small, sub, sup, ul, ol, li, blockquote, pre, code, a, img, table, thead, tbody, tr, th, td, div, span` — con `href`/`src` restringidos a `http(s):`, `mailto:`, `tel:` o rutas relativas (nada de `javascript:`/`data:text/html`), y sin ningún atributo `on*`.
3. Si el HTML del asistente de contratos (`scribeResultHtml`, generado por `GeminiAIService` u otro servicio de IA) se guarda en algún lado antes de mostrarse, aplicar la misma sanitización ahí también — si solo viaja de IA→frontend sin persistirse, con la mitigación de cliente ya aplicada es suficiente por ahora.
4. No hace falta migrar documentos existentes de forma retroactiva salvo que quieran hacer una pasada de limpieza — con sanitizar en el punto de guardado futuro ya se cierra el vector de ataque hacia adelante. Si prefieren sí limpiar el histórico, aviso si hace falta algo del lado de Cowork para eso.

## ✅ Implementado (2026-07-24) — resumen

**Sanitizador nativo, sin paquete.** No pude usar `mews/purifier`/HTMLPurifier — este entorno no tiene red para agregar dependencias de Composer (mismo motivo por el que Cowork usó `DOMParser` nativo en vez de DOMPurify). Escribí `Backend/app/Support/HtmlSanitizer.php`, un sanitizador de lista blanca basado en `DOMDocument`, con **la misma lista de etiquetas/atributos** que el `sanitizeHtml.ts` de Cowork para que ambas capas coincidan: quita `<script>`/`<iframe>`/`<style>`/`<form>` etc. con todo su contenido, desenvuelve etiquetas desconocidas pero inofensivas conservando su texto, elimina cualquier atributo `on*` (onclick/onerror/…), y restringe `href`/`src` a `http(s)`/`mailto`/`tel`/relativas/`#` (bloquea `javascript:` y `data:text/html`). Preserva a propósito `class`/`data-target-slug`/`href="#"` porque los wiki-links que el propio `ObsidianController` genera los usan.

**Punto de aplicación:** el único lugar donde se escribe el HTML renderizado (`content`) es `ObsidianController::rebuildVaultLinks()` (línea ~413, `$doc->update(['content' => ...])`) — ahí se sanitiza antes de persistir. Como `content` es exactamente lo que la página pública renderiza, ese único chokepoint cierra el vector para todos los caminos de escritura (sync de vault, edición, sugerencias aprobadas). `raw_content` (markdown fuente) no se renderiza como HTML crudo al navegador, así que no necesita sanitizarse ahí.

**HTML del asistente de contratos (`scribeResultHtml`):** confirmé que viaja IA→frontend sin persistirse en `content`, así que —como el propio §44 dice— con la mitigación de cliente ya aplicada es suficiente por ahora; si algún día se persiste, pasa por el mismo `HtmlSanitizer::clean()`.

Test nuevo: `tests/Unit/HtmlSanitizerTest.php` (10 casos: script/iframe eliminados, `on*` y `javascript:`/`data:text/html` removidos, etiquetas de formato conservadas, wiki-links preservados, enlaces/imágenes seguros conservados, etiqueta desconocida desenvuelta). Suite completa: **183/183 tests, 714 assertions**, sin regresiones.

**No migré el histórico** (punto 4) — con sanitizar al guardar el vector queda cerrado hacia adelante. Si quieren limpiar documentos ya guardados, es un comando aparte (`ObsidianDocument::each(fn($d) => $d->update(['content' => HtmlSanitizer::clean($d->content)]))`); avisen y lo agrego.

## §45. Índices compuestos para las tablas que alimentan `/sync/state` (rendimiento)

**Contexto (`docs/AUDITORIA_GENERAL_PLATAFORMA_2026-07-24.md`, sección 2):** `time_entries`, `store_logs`, `contingencies`, `internal_messages` y `audit_logs` obtuvieron su columna `tenant_id` vía `database/migrations/2026_06_19_062150_add_tenant_id_to_all_tables.php`, que solo agrega `foreignId('tenant_id')->nullable()->constrained('tenants')` — eso indexa `tenant_id` solo. Pero `ClockController::getState()` (el endpoint `/sync/state`, el más llamado de toda la app) siempre filtra por **`tenant_id` + fecha** a la vez:

```php
DB::table('time_entries')->where('tenant_id', $tenantId)->whereDate('date', '>=', $oneWeekAgo)
DB::table('store_logs')->where('tenant_id', $tenantId)->whereDate('date', '>=', $oneWeekAgo)
DB::table('contingencies')->where('tenant_id', $tenantId)->whereDate('created_at', '>=', $oneWeekAgo)
DB::table('internal_messages')->where('tenant_id', $tenantId)->whereDate('created_at', '>=', $oneWeekAgo)
DB::table('audit_logs')->where('tenant_id', $tenantId)->whereDate('date', '>=', $oneWeekAgo)
```

Sin un índice compuesto, la base de datos filtra por `tenant_id` y luego escanea secuencialmente para aplicar el filtro de fecha — cada vez más lento conforme crecen justamente las tablas que más crecen con el uso diario del reloj.

**Pedimos:** una migración que agregue, para cada tabla:

```php
Schema::table('time_entries', fn (Blueprint $t) => $t->index(['tenant_id', 'date']));
Schema::table('store_logs', fn (Blueprint $t) => $t->index(['tenant_id', 'date']));
Schema::table('contingencies', fn (Blueprint $t) => $t->index(['tenant_id', 'created_at']));
Schema::table('internal_messages', fn (Blueprint $t) => $t->index(['tenant_id', 'created_at']));
Schema::table('audit_logs', fn (Blueprint $t) => $t->index(['tenant_id', 'date']));
```

(ajusten los nombres de columna exactos si alguno difiere — los cité tal como aparecen en `getState()`). No es un cambio riesgoso ni requiere downtime en la mayoría de motores.

## ✅ Implementado (2026-07-24) — resumen

Migración `2026_07_24_000001_add_composite_indexes_for_sync_state.php` — índices compuestos tal cual: `(tenant_id, date)` en `time_entries`/`store_logs`/`audit_logs`, `(tenant_id, created_at)` en `contingencies`/`internal_messages`. Nombres de índice explícitos (`{tabla}_{cols}_idx`) para poder revertirlos con certeza, y guards con `hasTable`/`hasColumn` por si alguna columna difiere o la migración corre sobre una BD parcial. Sin cambio de comportamiento, solo rendimiento.

## §46. Optimizar `ClockController::getState()` — caché de datos casi estáticos + N+1 de rutinas (rendimiento)

**Contexto:** mismo endpoint que §45, es el que se llama cada 60 segundos por cada sesión activa (cada 5s durante el Simulador Matrix). Dos problemas concretos dentro de la función, además de los índices:

1. **N+1 confirmado en `routines`:**

```php
$routines = DB::table('routines')->where('tenant_id', $tenantId)->get()->map(function ($r) {
    $taskIds = DB::table('routine_task')->where('routine_id', $r->id)->pluck('task_id')->toArray();
    $r->task_ids = json_encode($taskIds);
    return $r;
});
```

Con N rutinas son N+1 consultas. Se puede resolver con una sola consulta agrupada:

```php
$routineIds = /* ids de $routines */;
$taskIdsByRoutine = DB::table('routine_task')
    ->whereIn('routine_id', $routineIds)
    ->get()
    ->groupBy('routine_id')
    ->map(fn($rows) => $rows->pluck('task_id')->toArray());
// luego, por cada $r: $r->task_ids = json_encode($taskIdsByRoutine->get($r->id, []));
```

2. **`role_permissions` se consulta dos veces** (una vez para armar `$userPermissions` con join a `permissions`, otra vez sin join un poco más abajo para mandarla cruda en la respuesta) — se puede calcular ambas formas a partir de una sola consulta.

3. **Caché para datos casi estáticos:** `job_roles`, `permissions`, `role_permissions`, `ui_rbac_rules`, `role_clock_policies` cambian solo cuando un admin edita configuración de puestos/permisos — no hace falta leerlos de la base de datos en cada ciclo de 60s de cada usuario. Sugerencia: `Cache::remember("tenant.{$tenantId}.static_config", 300, fn() => [...])` (5 min de TTL es razonable, o invalidar explícitamente el cache key cuando `JobRoleController`/`PermissionController`/etc. modifiquen algo — lo que les resulte más simple de mantener).

No urgente en el sentido de "está roto", pero sí es la explicación real y verificable del reporte de Francisco de que "tarda mucho en cargar la base de datos" — mientras más crecen `time_entries`/`audit_logs`/etc. y más usuarios concurrentes tenga un tenant, peor se pone sin estos cambios. Si prefieren priorizar solo los índices (§45, cambio pequeño y de bajo riesgo) y dejar el caché/N+1 para después, también es una buena primera mejora incremental.

## ✅ Parcial (2026-07-24) — N+1 y consulta duplicada corregidos; caché diferido

Hice los dos cambios de correctitud/rendimiento que **no tienen riesgo de comportamiento**:

1. **N+1 de rutinas corregido:** ahora se lee `routine_task` una sola vez con `whereIn($routineIds)` y se agrupa en memoria, en vez de una consulta por rutina. Comportamiento idéntico, N+1 → 1.
2. **Consulta duplicada de `role_permissions` eliminada:** antes se leía dos veces (una con JOIN a `permissions` para armar `$userPermissions` con los nombres de permiso, otra cruda para la respuesta). Ahora se lee `permissions` y `role_permissions` una vez cada una y se arman ambas formas en memoria (mapa `permission_id → name` + agrupado por puesto). Una consulta menos por cada llamada a `/sync/state`.

**Caché de datos casi estáticos: lo dejé fuera a propósito.** El caché (`Cache::remember("tenant.{id}.static_config", ...)`) es el mayor ahorro pero también el único de los tres con riesgo de **correctitud** — staleness e invalidación: si se cachea `job_roles`/`permissions`/etc. 5 minutos, un admin que edita un puesto o un permiso no ve el cambio hasta que expire, salvo que se invalide el key explícitamente desde `JobRoleController`/`PermissionController`/`RoleClockPolicyController`/`UiRbacController`/etc. (varios puntos de escritura). Eso es una decisión de trade-off (TTL corto y aceptar algo de staleness, vs. invalidación explícita y mantener esos ganchos) que el propio §46 marca como divisible ("si prefieren priorizar solo los índices y dejar el caché para después, también es buena primera mejora"). Prefiero no meter caché de aislamiento-por-tenant sin confirmar el enfoque de invalidación — un caché mal invalidado en datos de permisos es un bug sutil de seguridad, no solo de frescura. **Díganme si quieren TTL corto (simple, algo de staleness) o invalidación explícita (sin staleness, más ganchos)** y lo agrego en la siguiente pasada. Suite completa tras los otros dos cambios: **183/183 tests**, sin regresiones.

---

## §47. Quitar credenciales hardcodeadas de `DatabaseSeeder.php` (urgente, seguridad)

**Contexto (`docs/AUDITORIA_GENERAL_PLATAFORMA_2026-07-24.md`, sección 3):** `database/seeders/DatabaseSeeder.php` (líneas 16-51) inserta, cada vez que corre el seeder, 3 cuentas de `platform_users` con contraseñas en texto plano dentro del código fuente del repositorio:

```php
'email' => 'master@talent360.com',       'password' => Hash::make('Master'),       'role' => 'platform_admin',
'email' => 'pcmasterirapuato@gmail.com',  'password' => Hash::make('Master'),       'role' => 'platform_admin', // correo real de Francisco
'email' => 'support@talent360.com',       'password' => Hash::make('Support123'),   'role' => 'support_agent',
```

Contraseñas de diccionario, sin forzar cambio, con acceso de super-admin completo, y una de ellas atada al correo personal real de Francisco. Cualquiera con acceso de lectura al repo (incluyendo el propio Git, si alguna vez el repo deja de ser 100% privado) tiene credenciales de plataforma válidas.

**Pedimos:**

1. Quitar las contraseñas literales del seeder. Opción A (recomendada para desarrollo/QA): generar una contraseña aleatoria en cada corrida (`Str::random(24)`) y hacer `Log::info` o `dump()` de la contraseña generada solo en entornos no productivos (`app()->environment('local', 'testing')`), para que quien corre el seeder localmente la vea una vez y no quede en el código. Opción B (para producción): no seedear cuentas de plataforma en absoluto — crearlas a mano una sola vez vía `php artisan tinker` o un comando Artisan interactivo que pida la contraseña por input, y documentar ese paso como parte del runbook de despliegue en vez de dejarlo en un seeder que corre automático.
2. Si `master@talent360.com` no se usa activamente, mejor eliminarla del seeder por completo en vez de solo cambiarle la contraseña — es una cuenta genérica sin dueño claro, y menos cuentas de plataforma = menos superficie de ataque.
3. Si esas 3 cuentas ya existen en la base de datos de producción actual (Hetzner, según nos comentó Francisco), esto no se arregla solo con cambiar el seeder — hay que rotar la contraseña real de esas cuentas en la base de datos viva también. Avisen cuando esté listo el cambio de código para coordinar ese paso con Francisco (no es algo que nosotros podamos hacer sin acceso al servidor).

## ✅ Implementado (2026-07-24) — resumen

`DatabaseSeeder.php` reescrito:

- **Sin contraseñas en el repo.** Las cuentas de plataforma toman su contraseña de variables de entorno (`SEED_SUPERADMIN_PASSWORD`, `SEED_SUPPORT_PASSWORD`), que viven en `.env` (no versionado). Si la env var no está: en `local`/`testing` se genera una aleatoria (`Str::random(24)`) y se registra en el log para que quien seedea la vea una vez; en **producción NO se crea la cuenta** con contraseña por defecto (se registra un warning apuntando a crearla por runbook). Así el seeder ya no filtra credenciales válidas por el solo hecho de leer el código.
- **Cuenta genérica `master@talent360.com` eliminada** por completo (punto 2): sin dueño claro, menos superficie.
- Se conservan la limpieza de `users` con el correo de Francisco y la sincronización de secuencias de PostgreSQL.

**Punto 3 (rotar en la BD viva de Hetzner): no lo puedo hacer yo** — no tengo acceso al servidor de producción. Queda como paso de coordinación con Francisco; ver §51 para las contraseñas concretas propuestas y el mecanismo de aplicación.

## §48. Completar el flujo de 2FA real (hoy es solo un flag informativo, y excluye a `platform_users`)

**Contexto:** `users` tiene `two_factor_enabled`/`two_factor_secret` (migración `2026_06_26_083600_add_2fa_and_biometric_fields_to_users_table.php`), y `AuthController::login()` calcula `$requires2fa = !$isPlatformUser && $user->two_factor_enabled` para incluirlo en la respuesta — pero:

1. **No existe ningún endpoint que reciba y valide un código de 6 dígitos.** El `token` que regresa `login()` ya es válido y funcional en la MISMA respuesta que trae `requires_2fa: true` — es decir, hoy el 2FA no es una barrera de autenticación real, es solo un dato que el frontend podría usar para decidir si mostrar una pantalla adicional (que ni siquiera existe todavía del lado de Cowork).
2. **`platform_users` está explícitamente excluido** de la posibilidad de requerir 2FA (`!$isPlatformUser`) — justo las cuentas que Francisco pidió reforzar son las únicas que el código exime hoy.

**Pedimos (versión completa):**

1. Agregar `two_factor_enabled`/`two_factor_secret` también a `platform_users` (hoy solo existen en `users`).
2. Nuevo flujo de login en dos pasos para cuentas con 2FA activo: `login()`/`loginSocial()` NO deben emitir el token de Sanctum todavía si `two_factor_enabled` es verdadero — en vez de eso, regresar un token temporal de "login parcial" (corta vida, sin abilities de API real) y `requires_2fa: true`. Nuevo endpoint `POST /verify-2fa` que reciba ese token temporal + el código TOTP de 6 dígitos (librería sugerida: `pragmarx/google2fa` + `bacon/bacon-qr-code` para el QR de activación, es el estándar de facto en Laravel), y solo ahí emitir el token real de Sanctum.
3. Para `platform_users` específicamente: si Francisco quiere, se puede hacer 2FA **obligatorio** (no opcional) para todo `role = platform_admin`/`support_agent`, en vez de depender de que cada quien lo active — más simple de razonar y es justo la cuenta que él mismo identificó como la que más lo necesita.
4. No es un cambio chico — si prefieren, se puede hacer en dos entregas: primero el endpoint de verificación + 2FA opcional (reutilizable para `users` y `platform_users`), y en una segunda pasada forzarlo obligatorio solo para `platform_users`.

## ⏳ Bloqueado (2026-07-24) — falta el paquete `pragmarx/google2fa`

No lo pude implementar: el flujo TOTP real necesita `pragmarx/google2fa` (+ `bacon/bacon-qr-code` para el QR de activación) — la misma librería que el propio §48 sugiere como estándar de facto — y **no está instalada en `composer.json`, y este entorno no tiene acceso de red para agregarla** (mismo límite que ya bloqueó usar HTMLPurifier en §44). Escribir la verificación TOTP a mano (implementar HMAC-SHA1 + la ventana de tiempo del RFC 6238 por mi cuenta) sería reinventar criptografía sensible sin una librería auditada — justo lo que no conviene hacer en un flujo de seguridad.

**Lo que necesito para desbloquearlo:** que alguien con red corra `composer require pragmarx/google2fa bacon/bacon-qr-code` una vez (o me confirmen que puedo asumir que estará disponible). Con el paquete presente, implemento el flujo completo tal como está especificado arriba (endpoint `POST /verify-2fa`, login en dos pasos, 2FA en `platform_users`, y la opción de forzarlo obligatorio para plataforma). Mientras tanto, el "botón de pánico" de §49 (ya hecho) cubre parte del escenario de refuerzo de cuentas de plataforma que motivó esto.

## §49. Separar `platform_admin`/`support_agent` de la tabla `users` + revocación masiva de sesiones de plataforma

**Contexto:** el enum `App\Enums\UserRole` incluye `PLATFORM_ADMIN` y `SUPPORT_AGENT` junto con los roles de empresa (`ADMIN`, `SUPERVISOR`, `EMPLOYEE`), y `TenantScope::apply()` desactiva el aislamiento por tenant por completo si `$user->role === 'platform_admin'` — sin importar si ese `$user` viene de `users` o de `platform_users`. Esto significa que el código todavía contempla, como caso válido, que una fila de la tabla de empresas (`users`) tenga permisos de plataforma completos. El propio `DatabaseSeeder` tiene que borrar manualmente cualquier fila de `users` con el correo de Francisco "para que no colisione con `platform_users` al iniciar sesión con Google" (línea 54-57) — un parche puntual para un riesgo que el esquema no previene por sí solo.

**Pedimos:**

1. Restringir los valores válidos de `role` en `users` (a nivel de validación de los endpoints que lo escriben, ya que es un `string` sin `enum` a nivel de base de datos) a solo `admin`, `supervisor`, `empleado` — nunca `platform_admin`/`support_agent`. Esos dos valores deberían ser exclusivos de `platform_users.role`.
2. Auditar si hoy existe en la base de datos real alguna fila de `users` con `role = 'platform_admin'` o `'support_agent'` — si las hay, migrarlas a `platform_users` (o confirmar que son residuales de antes de que existiera esa tabla y ya no se usan) y limpiar.
3. Simplificar `TenantScope::apply()` una vez hecho el punto 1: ya no necesitaría el `if ($user->role === 'platform_admin') return;` porque un usuario de `users` nunca tendría ese rol — el bypass de aislamiento total solo debería poder ocurrir para instancias reales de `PlatformUser`, nunca por un valor de string en una columna de `users`.
4. Nuevo endpoint `POST /platform/security/revoke-all-sessions` (bajo `role:platform_admin`, solo el propio super-admin o quien tenga ese rol) que borre todos los `personal_access_tokens` cuyo `tokenable_type` sea `PlatformUser` — el "botón de pánico" que pidió Francisco: fuerza a todas las sesiones de plataforma (incluida la de un posible atacante que ya haya entrado) a volver a autenticar. Combinado con 2FA obligatorio (§48) para esas cuentas, cubre el escenario que describió sin necesidad de borrar/recrear tablas completas (eso rompería integridad referencial y trazabilidad de auditoría sin necesidad).
5. Cuenta de respaldo: sugerimos una segunda cuenta `platform_admin` real (no compartir credenciales con la principal), con su propio 2FA, guardada aparte (gestor de contraseñas, no en el repo) — es una práctica operativa más que un cambio de código, pero si quieren que el sistema la distinga de alguna forma (ej. no permitir desactivar/borrar la última cuenta `platform_admin` activa, para no quedar sin ninguna por accidente), lo agregamos como regla de negocio en `PlatformAdminController`.

## ✅ Parcial (2026-07-24) — botón de pánico hecho; separación de rol diferida (necesita auditoría de prod)

**Punto 4 (botón de pánico) — hecho:** `POST /platform/security/revoke-all-sessions` (bajo `role:platform_admin`) en `PlatformAdminController::revokeAllPlatformSessions()`. Borra todos los `personal_access_tokens` cuyo `tokenable_type` sea `PlatformUser` — fuerza a toda cuenta de plataforma (incluido un atacante que ya haya entrado) a re-autenticar. Deja intactos los tokens de usuarios de empresa (`User`). Registra el evento en `SecurityLogger`. La sesión del propio solicitante también se revoca, intencionalmente (es un botón de emergencia). Test nuevo: `PlatformRevokeSessionsTest.php` (revoca solo tokens de plataforma y no los de empresa; un no-`platform_admin` recibe 403).

**Puntos 1-3 (separar el rol + simplificar `TenantScope`) — diferidos, y con motivo fuerte:** al escribir el test descubrí (y lo verifiqué) que **el sistema HOY sí trata una fila de `users` con `role='platform_admin'` como plataforma válida** — los tests existentes de plataforma (`TenantSuspensionTest`) crean exactamente eso (`User::factory()->create(['role' => 'platform_admin'])`) y funcionan porque `TenantScope::apply()` hace el bypass por el string del rol, sin importar la tabla. Es decir: el "hueco" que §49 quiere cerrar no es teórico, es un camino en uso. Cambiar `TenantScope` para que el bypass solo aplique a instancias reales de `PlatformUser` (punto 3) **rompería ese camino** — y si en la BD de producción de Hetzner existe alguna fila de `users` con ese rol (punto 2 pide auditarlo, y no tengo acceso para verlo), el cambio podría **dejar a alguien fuera de su propio panel de plataforma**. Aplicarlo a ciegas sería exactamente el tipo de cambio de seguridad que rompe producción. **Lo que necesito antes de hacerlo:** que corran en la BD real `SELECT id, email, role FROM users WHERE role IN ('platform_admin','support_agent')` y me confirmen el resultado. Si sale vacío (lo esperado si ya migraron todo a `platform_users`), hago el cambio de `TenantScope` + la restricción de validación del rol con confianza; si sale algo, primero coordinamos migrar esas filas a `platform_users`. El punto 5 (cuenta de respaldo, no borrar la última `platform_admin`) lo agrego junto con eso.

---

## §50. Cerrar el hueco de reasignación de `tenant_id` en el registro sin sesión (regla de negocio: 1 cuenta = 1 empresa)

**Contexto (a petición de Francisco, 2026-07-24):** confirmó explícitamente la regla de negocio — la Landing Page da de alta con Google, y **cada cuenta de Google solo puede crear una empresa**. Revisando el flujo de creación de tenant para verificar que esto ya se cumple, encontré que **en el camino normal (usuario autenticado con Google) sí se cumple** — `SubscriptionController::createPreference()` detecta `$isUpgrade` cuando el usuario autenticado ya tiene `tenant_id` y lo manda por la rama de "actualizar plan de mi empresa actual", nunca crea una empresa nueva para una sesión que ya tiene una. Hasta ahí, bien.

**Pero hay un hueco real en la rama sin sesión** (`SubscriptionController::provisionTenant()`, "Standard creation flow", líneas ~495-523) que se usa cuando NO hay un usuario autenticado en el navegador que hace el checkout (el registro clásico por formulario, sin pasar por Google primero):

```php
$currentUser = auth('sanctum')->user();
if ($currentUser && $currentUser->tenant_id === null) {
    // ... (esta rama está bien, ya la cubre el chequeo de $isUpgrade de más arriba)
} else {
    // Fallback: check if user already exists globally
    $admin = User::withoutGlobalScope(TenantScope::class)->where('email', $payload['admin_email'])->first();
    if ($admin) {
        $admin->update([
            'tenant_id' => $tenant->id,   // ← si $admin YA tenía una empresa, aquí se la quita
            'role' => UserRole::ADMIN->value,
        ]);
    } else {
        $admin = User::create([...]);
    }
}
```

**Impacto real:** si alguien manda por el checkout (sin haber iniciado sesión) el `admin_email` de una persona que YA es dueña de otra empresa en Talent360 — algo tan simple como conocer su correo de trabajo, que suele ser semi-público —, al completarse el pago ese `$admin->update(['tenant_id' => $tenant->id, ...])` se ejecuta igual, **moviendo la cuenta de esa persona de su empresa original a la empresa nueva**. La empresa original se queda sin admin (huérfana) y la persona dueña legítima pierde acceso a su propia empresa la próxima vez que inicie sesión — sin que el atacante necesariamente gane acceso él mismo (no cambia la contraseña), pero sí causa un daño real de integridad de datos. No hace falta que el atacante esté autenticado como esa persona para disparar esto.

**Pedimos:** antes de reasignar, comprobar `$admin->tenant_id !== null` — si ya tiene empresa, **rechazar** con un error claro (`"Ya existe una cuenta registrada con este correo. Inicia sesión para gestionar tu empresa o usa un correo distinto."`, 409) en vez de reasignar. Aplica igual en `TenantController::store()` (el endpoint público equivalente, aunque ahí la validación `unique:users,email` ya lo bloquea de forma indirecta con un error de validación — solo confirmar que da un mensaje entendible, no un 422 genérico). Esto es exactamente lo que hace falta para que la regla "1 cuenta = 1 empresa" que confirmó Francisco quede garantizada por el sistema y no solo por el camino feliz de la UI.

## ✅ Implementado (2026-07-24) — resumen

En `SubscriptionController::provisionTenant()`, rama fallback sin sesión: antes de `$admin->update(['tenant_id' => ...])` se comprueba `$admin->tenant_id !== null` y, si ya pertenece a una empresa, se hace `abort(409, "Ya existe una cuenta registrada con este correo. Inicia sesión para gestionar tu empresa o usa un correo distinto.")` en vez de reasignar. Como el check vive dentro del `DB::transaction()` de `provisionTenant`, el `abort` hace rollback: el tenant recién creado tampoco queda a medias. El path del webhook de MercadoPago ya envolvía `provisionTenant` en try/catch (registra el error sin corromper datos), y el path síncrono (freemium) renderiza el 409 tal cual.

`TenantController::store()` ya bloquea el caso vía `unique:users,email` (error de validación entendible), así que no necesitó cambio — el hueco real estaba solo en la rama sin sesión de `provisionTenant`.

Test nuevo: `TenantProvisionHijackTest.php` — un registro sin sesión con el correo de un dueño existente devuelve 409 y NO le mueve el `tenant_id` (su empresa no queda huérfana); un registro con correo nuevo sigue funcionando (200). Suite completa: **183/183 tests**, sin regresiones.

---

## §51. Credenciales de prueba: rotar las hardcodeadas + convenio de dominio confirmado por Francisco

**Contexto:** Francisco pidió directamente el usuario/contraseña de: la Plataforma de Empresa de DecorArte 360 (tenant de prueba) y sus empleados, la Plataforma Talent 360, y Soporte. Aclaración importante que le respondimos: **las contraseñas se guardan hasheadas (`Hash::make`, bcrypt)** — eso significa que nadie, ni nosotros ni con acceso directo a la base de datos, puede "leer" la contraseña actual de una cuenta ya existente; solo se puede fijar una nueva. Por eso lo que pedimos aquí es que Backend **fije** estas contraseñas concretas (o equivalentes igual de fuertes) en vez de intentar recuperar las actuales.

Francisco también confirmó el convenio de nombres: `nombre@decorarte360.com` para las cuentas de la empresa de prueba, y `nombre@talent360.mx` para las cuentas de plataforma (súper-admin y soporte) — **aclaración para que quede documentada:** estos dominios NO están registrados/configurados con correo real todavía (Francisco solo tiene la IP de Hetzner, sin dominio propio aún — ver conversación sobre el APK). Sirven perfecto como identificador único de usuario dentro del sistema (el login no depende de que el correo exista de verdad), pero **cualquier flujo que de verdad mande un correo (recuperar contraseña, verificación, notificaciones) va a fallar/rebotar con estas direcciones hasta que haya un dominio real configurado con DNS/SMTP.** No es un problema para las pruebas de hoy, solo que no hay que sorprenderse si "recuperar contraseña" no llega a ningún lado todavía.

**Pedimos (rotar/crear, en el entorno de desarrollo primero — coordinar aparte cuándo replicar en Hetzner):**

**Plataforma Talent 360** (tabla `platform_users`):

| Correo actual | Nuevo correo | Nueva contraseña | Rol |
|---|---|---|---|
| `master@talent360.com` | eliminar esta cuenta genérica (§47) — o si prefieren conservarla, `admin@talent360.mx` | `7w65w@aTavHqOxBw` | `platform_admin` |
| `pcmasterirapuato@gmail.com` | conservar este correo tal cual (es el que Francisco realmente usa para entrar) | `s6Ubi8%%zT7rbr9s` | `platform_admin` |
| `support@talent360.com` | `soporte@talent360.mx` | `MUWZHWH#u5zst@#L` | `support_agent` |

**DecorArte 360** (tenant de prueba, tabla `users`, `tenant_id` = el ID real de DecorArte en su base — confirmar cuál es antes de aplicar, no asumir que sigue siendo `1`):

| Correo | Nueva contraseña | Rol | Notas |
|---|---|---|---|
| `admin@decorarte360.com` | `H!Q1Ztkg06v**W@f` | `admin` | Dueño/admin de la empresa de prueba |
| `supervisor@decorarte360.com` | `un*rYLX@K-L-cF1O` | `supervisor` | Un supervisor de ejemplo |
| `empleado@decorarte360.com` | `E8xBc%egT2Pw3wu_` | `empleado` | Un colaborador de ejemplo |

Si ya existen cuentas reales de DecorArte con otros correos que Francisco usa activamente para probar, mejor conservar esos correos y solo aplicarles estas contraseñas nuevas (avisar cuáles son antes de tocar nada, para no romper pruebas en curso).

**Sobre los empleados de DecorArte (PIN del reloj checador):** Francisco confirmó que los empleados normales siguen entrando por PIN corto (como ya funciona hoy — Ley Silla, testigos, kiosco §37 usan el mismo mecanismo `employees.security_pin`), no con usuario/contraseña completos. Si quieren, de una vez fijen también un PIN conocido (ej. `1234` para el supervisor de ejemplo, `5678` para el empleado de ejemplo — o los que les resulte más simple) para que Francisco pueda probar el flujo de kiosco/reloj sin tener que primero ir a buscar cuál PIN quedó en cada cuenta.

**Entrega:** cuando esté aplicado, avisen en el chat de Francisco (o marquen esta fila hecha) y le paso yo la tabla final de accesos — no hace falta que ustedes le escriban directo, ya quedamos en que yo se los entrego organizados junto con los links de `Frontend/public/hub.html` (página de accesos rápidos que ya armé, ver nota abajo).

## ⏳ Mecanismo listo (2026-07-24) — aplicar las contraseñas concretas es paso de ops

Hay una **tensión directa entre §51 y §47** que hay que resolver bien: §47 pide sacar las contraseñas del repo, y §51 pide fijar contraseñas concretas conocidas. Meter estas 3 contraseñas literales en el seeder versionado recrearía exactamente el agujero de §47 (cualquiera que lea el repo las tendría). Así que no las hardcodeé.

**Lo que sí dejé listo (el mecanismo):** el seeder de §47 ya toma las contraseñas de plataforma de env vars. Para fijar **estas** contraseñas concretas, Francisco (o quien tenga acceso al `.env`, que NO se versiona) solo pone en `Backend/.env`:

```
SEED_SUPERADMIN_PASSWORD=s6Ubi8%%zT7rbr9s
SEED_SUPPORT_PASSWORD=MUWZHWH#u5zst@#L
```

y corre `php artisan db:seed`. Con eso, la cuenta de Francisco (`pcmasterirapuato@gmail.com`) y la de soporte (`soporte@talent360.mx`, ya con el dominio `.mx` que confirmó) quedan con esas contraseñas exactas, sin que ninguna toque el repo. (La cuenta genérica `master@`/`admin@talent360.mx` la eliminé por §47.2; si la quieren de vuelta, avisen.)

**Lo que NO puedo hacer yo:**
- **Aplicarlas en la BD viva de Hetzner** — no tengo acceso al servidor. Es el mismo paso de coordinación de §47.3.
- **Las cuentas de DecorArte 360 (tabla `users`)** — necesito el **`tenant_id` real de DecorArte** en la base (el propio §51 dice "no asumir que sigue siendo 1"), y confirmar si ya existen esas 3 cuentas o hay que crearlas. Eso no lo puedo verificar sin la BD real (aquí Postgres no está accesible). En cuanto me confirmen el `tenant_id` y si existen/hay que crearlas, dejo un seeder dev-only (`DevTestAccountsSeeder`, guardado a `local`/`testing`, leyendo también de env) para las 3 cuentas de DecorArte + sus PIN de ejemplo (`1234`/`5678`), y coordinamos la aplicación en Hetzner.

En resumen: **el "cómo" ya está construido y es seguro; el "aplicar los valores concretos" depende de datos/acceso que solo Francisco tiene.** Díganme el `tenant_id` de DecorArte y si prefieren que ponga yo las env vars propuestas en un `.env.example` comentado (sin valores reales) como recordatorio, y cierro lo que quede de mi lado.
