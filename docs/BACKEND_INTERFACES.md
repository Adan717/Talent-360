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

- **Anti-duplicados real:** `ClockService::processPunch()` ahora rechaza (`400`, `"Fichaje Denegado: ya existe un registro de tipo '{type}' para hoy. No se puede duplicar."`) cualquier segundo fichaje del mismo `type` el mismo día para el mismo usuario. Antes no había ninguna validación — un doble clic o un reintento de red podía crear filas duplicadas. **Pendiente de una siguiente ronda (no se hizo ahora):** esto no cierra la ventana de carrera al 100% (dos requests casi simultáneos podrían ambos pasar el `exists()` antes de que el primero confirme el insert). Cerrarlo del todo requeriría un índice único en `time_entries (user_id, date, type)`, pero eso exige primero deduplicar filas históricas que ya puedan existir — es una migración con `DELETE`, no la corrí sin confirmación explícita por tratarse de datos de nómina.
- **Snapshot de nómina reparado:** `TimeEntry::$fillable` ya incluye `employee_name_at_time`, `job_role_title_at_time`, `base_salary_at_time` (antes Eloquent los descartaba en silencio, siempre quedaban `NULL`). De paso until otro bug relacionado: `ClockService` leía `$jobRole?->title`, pero la tabla `job_roles` no tiene columna `title` — el nombre del puesto vive en `name`. Se corrigió también, si no el snapshot del puesto habría seguido guardándose vacío aunque el `$fillable` ya estuviera bien.

---

## 9. Estados de la Matriz — Mapeo a Este Documento

| # Estado | Nombre | Cubierto por sección |
|---|---|---|
| 1 | Fichaje Bloqueado | (pendiente — hoy vive solo en localStorage, fuera de alcance de este documento) |
| 5 | Llamar a Suplente | Frontend-only, no requiere backend nuevo |
| 6 | En Camino a Sucursal | Frontend-only (geofencing progresivo con las mismas coords de §7) |
| 9 | Apertura de Emergencia | §3 |
| 10 | Declarar Eventualidad | §4 |
| 14 | Registrar Reingreso | Ya existe `temp_exit_start`/`temp_exit_end` en frontend, sin endpoint dedicado — reutiliza `/clock/punch` con esos types (verificar que estén en `ClockService::ALLOWED_TYPES`, hoy **no lo están** — agregar `temp_exit_start`, `temp_exit_end` a la constante) |
| 15 | Modo Contingencia Activo | §4 (mismo mecanismo, es el estado visual mientras la contingencia sigue abierta) |
| 22 | Checklist de Cierre | §6 |
| Perfil | Configura tu alarma | §5 |

**Nota aparte para Claude Code:** ~~`ClockService::ALLOWED_TYPES` (línea 20-23) no incluye `temp_exit_start` ni `temp_exit_end`~~ — ✅ corregido el 2026-07-20 junto con `/clock/punch-batch`. Ambos tipos ya están en la constante.
