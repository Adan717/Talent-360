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
