# Auditoría del módulo Tareas y Rutinas — estado post-resincronización

**Fecha:** 2026-07-27 · **Base:** `main` @ `8f463fb` (merge Reloj + 46 commits del jefe) · **Suite:** 787 passed / 0 failed

> **✅ CIERRE (2026-07-27, mismo día): 10/10 hallazgos remediados.** Suite final: **820 passed
> / 0 failed** (+33 tests característicos, todos rojo→verde). Commits:
> C1 `d25e6bc` · A1+A2 `cc71b28` · A3+M2 `3de0c90` · A4 `a027cbe` (resultó PEOR: el comando
> estaba escrito contra un esquema inexistente y jamás persistió una pre-nómina) ·
> A5+M5 `d9db1a1` · M1+M4 `e0c9eeb` · M3 `391db7f` (panel verificado end-to-end en navegador).
> Las 6 puertas de depósito comparten el ancla única `coins_awarded`; todo lo fechado corta
> con la timezone del tenant. Quedan solo las 2 decisiones de producto del jefe (§31 tarea al
> vuelo, ProductivityBonusService muerto) — documentadas abajo, sin cambio a propósito.

Alcance: `TaskSyncController`, `TaskAssignmentController`, `TaskValidationController`,
`UserWalletController`, `TaskValidationPolicy`, `ProductivityBonusService`, `PayrollWeekService`,
`FlagUnfinishedTasksCommand`, `CalculateWeeklyPayrollCommand`, modelos (`Task`, `Routine`,
`TaskAssignment`, `UserWallet`), rutas, scheduler, y el FE (`useTaskStore`, `TaskRunner`,
enganches de rutinas en `useClockEngine`).

---

## Resumen ejecutivo

La base histórica del módulo (rondas T1–T16) sigue sólida: ownership y aislamiento de tenant en
`/sync/tasks` y `PUT /task-assignments/{id}`, `TaskValidationPolicy` como regla única de
validación jerárquica, anti-doble-pago en `update` y `validate`, candados §62 + `ownerCleared`,
y el motor de rutinas (apertura server-side + horario fijo/bolsa client-side) cableado.

**Pero los endpoints que entraron DESPUÉS de esas rondas (§34, §35, §41 y "tareas inconclusas"
Sección 2 #2) NO pasaron por el mismo endurecimiento y reabren las tres familias de agujeros que
T1–T9 cerraron: falta de gate de rol, falta de ownership y pagos sin ancla anti-doble-pago.**
Además hay 2 problemas de operación (scheduler duplicado de nómina y timezone UTC en el comando
nocturno) que afectarían dinero/datos en producción real.

---

## Hallazgos priorizados

### 🔴 CRÍTICO

**C1 — `POST /task-assignments/{id}/resolve-incomplete` sin gate de rol: cualquier empleado se
auto-aprueba y se auto-paga.**
`TaskAssignmentController::resolveIncomplete` (l.441) se documenta como "los 3 botones del
gerente", pero la ruta (routes/api.php l.466) está en el grupo autenticado general y el método
no verifica rol, ni ownership, ni que la asignación esté `flagged_incomplete`, ni que no se haya
pagado ya (`coins_awarded`). Vectores:
- Empleado llama `action=approve` sobre su PROPIA tarea pendiente → `completed` + depósito de
  monedas/XP, saltándose la validación jerárquica completa (incluida `awaiting_validation`, que
  en las demás puertas es pegajosa).
- Repetido sobre una tarea ya pagada pero no-completed → **paga otra vez** (sin ancla).
- `action=reject` sobre tareas de un compañero → sabotaje (omitted).
**Fix sugerido:** gate admin/supervisor/platform_admin + exigir `flagged_incomplete=true` +
ancla `coins_awarded` en el pago (mismo patrón de `TaskValidationController`).

### 🟠 ALTO

**A1 — `validateWithPin` (§41) paga sin ancla: doble pago repetible.**
`TaskAssignmentController::validateWithPin` (l.343): con `status=completed` deposita SIEMPRE
(l.400–417) — no verifica `coins_awarded` ni que la asignación no estuviera ya completada.
Llamarlo dos veces = pagar dos veces; también paga de nuevo tras el ciclo
rechazo→in_progress→re-validación. `TaskNoDoblePagoTest` cubre `validate`, no esta puerta.

**A2 — `validateWithPin` sin throttle: fuerza bruta del PIN del supervisor.**
El PIN es de 4–6 dígitos y la ruta no tiene `throttle` (compárese con `emergency-open`, que
lleva `throttle:5,1` justamente "contra fuerza bruta del PIN"). Un empleado puede iterar
`supervisor_user_id` + PIN hasta acertar y auto-validarse tareas con pago. También permite
enumerar qué user_ids tienen PIN configurado por diferencia de respuesta (422 vs 403).

**A3 — Doble pago vía sync: el ancla es el STATUS, no `coins_awarded`.**
`TaskSyncController::sync` (l.345): deposita si `!$existing || $existing->status !== 'completed'`.
El PUT permite legítimamente desmarcar una completada (checklist del Reloj) conservando
`coins_awarded > 0`; re-completarla por sync vuelve a depositar. Ciclo
sync-completa → PUT-desmarca → sync-completa = pago por vuelta. `update` y `validate` ya anclan
en `coins_awarded`; sync (y `aiValidate`, ver M2) deben usar la misma marca.

**A4 — Nómina semanal agendada DOS VECES + posibilidad de fila duplicada.**
`payroll:calculate-weekly` está en `bootstrap/app.php` (l.44, `dailyAt('23:00')`) **y** en
`routes/console.php` (`weeklyOn(6,'23:59')`, con nombre distinto → ambas corren). Además el
comando sólo detecta existente con `status='draft'` (l.65–68): si la fila de la semana ya no es
draft (p.ej. firmada), crea una SEGUNDA fila de nómina para la misma semana. Decidir un solo
registro de schedule y hacer el lookup por semana sin filtrar status (o unique compuesto
`employee_id+week_start`).

**A5 — Timezone: `tasks:flag-unfinished` corre a las 00:30 UTC = 18:30 CDMX y marca como
"inconclusas de días anteriores" tareas del turno EN CURSO.**
`config/app.php` fija `timezone=UTC` y el comando compara `date < Carbon::today()` (UTC). Las
asignaciones se fechan con la fecha LOCAL del cliente (CDMX). A las 00:30 UTC del día D+1, en
México aún es D 18:30 con turnos abiertos: toda tarea `in_progress/paused` fechada D se fuerza a
`awaiting_validation` + `flagged_incomplete` a media jornada. El Reloj ya resolvió esta familia
de bugs con la TZ del tenant (`TenantTimezone`) — aplicar el mismo criterio aquí (y en el
`dailyAt` del scheduler, o correr el comando con corte por tenant).

### 🟡 MEDIO

**M1 — `omit` (§34) sin ownership:** cualquier empleado puede omitir la tarea de cualquier
compañero del tenant (l.163–181; sólo scopea tenant). Sabotaje silencioso; el aviso al
supervisor sale a nombre del DUEÑO de la tarea, no del que la omitió — ni rastro del actor.
Fix: mismo guard de `update` (no privilegiado ⇒ sólo propias) + registrar quién omite.

**M2 — `aiValidate` (§35) sin ownership y con pago sin ancla:** cualquier empleado puede empujar
la asignación de otro a validación IA (o degradarla a `awaiting_validation` con fotos basura), y
el pago en match (l.302–320) no verifica `coins_awarded` (mismo edge de A3: re-pago sobre
desmarcada). Fix: ownership + ancla.

**M3 — Feature "tareas inconclusas" sin UI: los 3 botones no existen en el FE.**
`resolve-incomplete` / `flagged_incomplete` tienen CERO referencias en `Frontend/src`. Las
tareas flaggeadas por el comando nocturno caen al pozo genérico de `awaiting_validation` (el
panel de validación normal las puede aprobar/rechazar, pero sin el semáforo 🟢🟡🔴 ni
"reprogramar/proteger bono"). El backend de la feature está completo; falta el panel del
gerente. (Nota: mientras C1 esté abierto, cablear la UI SIN cerrar C1 empeora la exposición.)

**M4 — `UserWallet::deposit` sin transacción/lock:** lectura-modificación-escritura sin
`lockForUpdate` (UserWallet.php l.48–73). Dos depósitos concurrentes del mismo usuario pueden
perder uno (los controllers que la llaman dentro de transacción no serializan entre requests).
Riesgo bajo en la práctica (mismo usuario, doble click / batch), pero es dinero: barato de
cerrar con `DB::transaction` + `lockForUpdate` en `getOrCreateForUser`.

**M5 — `resolveIncomplete reschedule` re-fecha con `Carbon::today()` UTC** (l.498): después de
las 18:00 CDMX "hoy" ya es mañana-UTC → la tarea reprogramada aparece fechada un día adelante
del día operativo del tenant. Mismo fix de TZ que A5.

### 🟢 BAJO / deuda ya conocida (sin cambio, documentar)

- **ProductivityBonusService**: sigue SIN cablear (cero referencias) y con 2 ramas muertas
  (`completed_late`/`cancelled` no existen en el enum). Decisión previa: no tocar hasta que el
  jefe decida (cambia cuánto cobra la gente). Sin cambios en el resync.
- **§31 "tarea al vuelo"**: sigue el 403 duro del anfitrión; el botón de tarea dinámica del dial
  no funciona para empleados rasos. Decisión de producto pendiente del jefe (el guard granular
  alternativo está comentado en `TaskSyncController` l.77–84).
- `priority` sin enum en el mapping de sync (inalcanzable desde el FE tipado; deuda menor).
- BAJOs históricos aceptados: robo de atribución sobre pool row ya completed (T1), colisión de
  id string global entre tenants → 500, tx padre de apertura en pg (T6), inconsistencia de
  filtro en stats del monitor (T8), pool huérfano por targetId de otro rol (T13).

---

## Lo que está BIEN (verificado en esta pasada)

| Área | Estado |
|---|---|
| Authz/tenant en `sync`, `index`, `update`, `validate` (T1–T9) | ✅ ownership, tenant explícito sin `?? 1` en validate, IDOR de lectura cerrado |
| `TaskValidationPolicy` única (sync + update) | ✅ una sola regla; escalar JSON tolerado |
| Anti-doble-pago en `update` y `validate` | ✅ ancla `coins_awarded` (falta extenderla: A1/A3/M2) |
| `awaiting_validation` pegajoso (sync + update) | ✅ sólo `/validate` la libera |
| §62 + `ownerCleared` (resync) | ✅ incidente de pérdida cubierto sin romper bolsa/release/rebote/dinámica |
| Validación de input del sync (F4) | ✅ + ids numéricos del FE del jefe normalizados (resync) |
| Rutinas de apertura server-side (T6) | ✅ idempotente, tenant-scoped, catch con log |
| Rutinas horario fijo/bolsa client-side (T15/T16 + fix pre-prod) | ✅ `triggerScheduledRoutines` cableado al tick del motor |
| Wallet endpoints de lectura | ✅ auto-scopeados al usuario autenticado |
| Omit con confirmación + motivo (FE) | ✅ |
| Feedback de rechazo visible (FE) | ✅ mapeado y mostrado en TaskRunner |
| Anti-auto-validación + jerarquía (`validate`, `validateWithPin`) | ✅ (la jerarquía en PIN es correcta; el hueco es throttle/pago, no authz del supervisor) |

## Orden de remediación sugerido

1. **C1** (gate + flagged + ancla en `resolveIncomplete`) — un solo método, riesgo mayor.
2. **A1+A2** (`validateWithPin`: ancla + `throttle:5,1`) — dos líneas + ancla.
3. **A3+M2** (ancla `coins_awarded` en sync y aiValidate) — unifica la regla de pago en las 6
   puertas de depósito.
4. **A4** (un solo schedule de nómina + lookup sin filtro de status / unique compuesto).
5. **A5+M5** (TZ del tenant en el comando nocturno y reschedule).
6. **M1+M2 ownership** (`omit`, `aiValidate`).
7. **M4** (lock del wallet) y después **M3** (UI de inconclusas, ya con C1 cerrado).

Cada punto cabe en el flujo por ronda de siempre: test-first (rojo→verde) + review adversarial.
