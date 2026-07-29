# Hallazgos — prueba de alta de empresa desde cero (2026-07-29)

**Entorno:** instancia V2 en el servidor del jefe (`http://46.225.153.115:3002`), commit `99b7fce`.
**Escenario:** registro público → plan Enterprise (checkout simulado) → wizard de giro (Repostería) →
alta de 3 colaboradores desde Directorio Digital.
**Resultado del flujo:** funciona de punta a punta. Empresa aprovisionada, 7 puestos + 92 checklists
cargados por el wizard, 3 colaboradores creados. Los hallazgos de abajo son defectos encontrados
DURANTE ese recorrido; ninguno impide operar, pero el #1 afecta dinero.

---

## 🔴 H1 — El sueldo capturado en el alta NUNCA llega a la nómina ni al costo de tareas

**Qué pasa:** el formulario "Alta de Colaborador" envía el sueldo en el campo `salary`
(`RecursosHumanos.tsx:1132`) y `EmployeeController::store` lo persiste tal cual. Pero **todo el
cálculo de dinero lee `base_salary`**, que queda `NULL`:

- `TaskAssignmentController` (update / aiValidate / validateWithPin / resolveIncomplete) y
  `TaskSyncController`: `$employee->base_salary > 0 ? $employee->base_salary : 300.00`
- El costo financiero de cada tarea = `(base_salary / 480) * minutos`

**Consecuencia:** todo colaborador dado de alta por esta pantalla se calcula con el **salario por
defecto de $300**, sin importar lo que se haya capturado. Verificado en vivo:

```
Francisco Vega  | salary=14000.00 | base_salary=NULL
Adán Cuéllar    | salary= 9000.00 | base_salary=NULL
Marisol Herrera | salary=18000.00 | base_salary=NULL
```

**Fix sugerido:** decidir UNA columna de verdad. Lo menos invasivo: que `store`/`update` escriban
`base_salary` cuando llegue `salary` (o que el FE mande ambos), más una migración que copie
`salary → base_salary` donde esté nulo. Conviene test que cubra "alta con sueldo → el costo de
tarea usa ese sueldo, no 300".

## 🟠 H2 — El wizard de giro NUNCA se abre solo en una empresa nueva

**Qué pasa:** `App.tsx` está bien (`if (!onboarding_completed) setShowOnboarding(true)`), pero
`ClockController::getState` trae un fallback:

```php
if (!isset($systemSettings['onboarding_completed'])) {
    $hasJobRoles = DB::table('job_roles')->where('tenant_id', $tenantId)->exists();
    if ($hasJobRoles) { $systemSettings['onboarding_completed'] = true; }
}
```

y **la creación del tenant siembra 4 puestos por defecto** (Gerente de Sucursal, Asesor de Ventas,
Cajero, Almacenista). Entonces el fallback se cumple desde el primer login → el wizard se marca
como completado sin que nadie lo haya corrido.

**Consecuencia:** el asistente que precarga puestos/tareas/cursos por giro —la puerta de entrada
del producto— no aparece para NINGUNA empresa nueva. Hay que descubrir a mano el botón
"Comenzar Configuración". (Con el wizard abierto manualmente todo funcionó bien: giro Repostería →
sub-giro "Insumos para Repostería & Panadería" → 7 puestos + 92 checklists.)

**Fix sugerido:** anclar el fallback a algo que solo exista DESPUÉS del wizard (p. ej. `tasks`
del tenant > 0), o marcar `onboarding_completed=false` explícitamente al crear el tenant.

## 🟠 H3 — Los correos autogenerados conservan acentos

**Qué pasa:** `RecursosHumanos.tsx:1129` arma el correo con
`name.toLowerCase().replace(/\s/g,'')` + dominio, sin normalizar diacríticos:

```
Adán Cuéllar → adáncuéllar@pruebaqa360.com
```

**Consecuencia:** un correo con acentos rompe el envío real de mail (SMTP) y puede fallar al
teclearlo en el login. Además, si dos nombres difieren solo por acentos colisionan.

**Fix sugerido:** normalizar (`NFD` + quitar diacríticos) antes de armar el correo.

## 🟡 H4 — El alta no permite elegir el rol y arrastra el puesto anterior

- `role: 'empleado'` está **hardcodeado** en el payload del alta: todo colaborador nace como
  empleado y hay que editar su ficha después para volverlo supervisor/admin.
- Al reabrir "Alta de Colaborador" tras guardar, el `<select>` de puesto **conserva el puesto del
  alta anterior** (el nombre sí se limpia). Riesgo de dar de alta a alguien con el puesto
  equivocado por descuido.

**Fix sugerido:** exponer el selector de rol en el alta (el backend ya valida
`in:admin,supervisor,empleado`) y limpiar el estado del formulario al abrirlo.

---

---

# Segunda tanda — prueba del RELOJ CHECADOR (misma sesión)

## 🔴 H5 — REGRESIÓN DEL RESYNC (ya corregida, commit `8623d26`): los 5 paneles de resolución quedaron huérfanos

Al fusionar la línea del jefe, su rediseño cambió `App.tsx` para que el módulo `dashboard`
renderice `MonitorActividadesTiempoReal` (Monitor 360) en lugar de `DashboardTalent360`. El
conflicto DENTRO de `DashboardTalent360` se resolvió bien (los paneles se conservaron), pero **ese
componente ya no se renderiza en ningún lado** → `LateAuthorizationsPanel`, `PanicIncidentsPanel`,
`LateJustificationsPanel`, `ContingenciesPanel` e `IncompleteTasksPanel` desaparecieron de la app.

**Reproducido en vivo:** Marisol solicitó autorización de entrada tardía desde el dial; la
solicitud quedó `pending` en BD y `GET /admin/late-authorizations` devolvía 200 con ella, pero
**ninguna pantalla la mostraba** → el admin no tenía dónde aprobarla. Sin este fix, todo lo que un
colaborador declara desde el dial entra a la base y se queda ahí para siempre.

**Corregido:** los 5 paneles se montaron en `MonitorActividadesTiempoReal`, la pantalla real del
dashboard. Verificado: el panel aparece con "Marisol Herrera · Retardo de 392 min" y el botón
Autorizar deja la solicitud en `approved`.

**Lección para futuros resyncs:** no basta con resolver el conflicto dentro de un archivo; hay que
verificar que el archivo **siga montado** cuando el otro lado reemplaza pantallas completas.

## 🟠 H6 — La autorización aprobada NO desbloquea el dial del colaborador

`ClockService` (l.848) respeta correctamente la aprobación: si existe una fila `approved` en
`late_authorization_requests` para ese usuario y fecha, permite el `check_in` pese al Retardo
Extremo. **Verificado por API:** `POST /clock/punch` respondió `200` y registró el fichaje con
404 min de retardo e incidencia LFT de descuento.

Pero **el dial nunca consulta ese estado**: tras la aprobación sigue mostrando "🔒 ACCESO
BLOQUEADO / TOLERANCIA VENCIDA" y no ofrece botón para fichar. El colaborador autorizado queda
sin poder registrar su entrada aunque el servidor ya se lo permite.

**Fix sugerido:** exponer el estado de la solicitud al dial (o incluirlo en el payload de
`/clock/state`) y levantar el bloqueo del FE cuando esté `approved`.

## 🟠 H7 — Deadlock de apertura: si nadie abrió la sucursal a tiempo, nadie puede fichar

Configuración por defecto de una empresa nueva: `storeSchedule = {openTime: 08:00, closeTime:
20:00}`, `clockOpConfig.arrivalWindowMins = 30`. Si la sucursal no se abre dentro de esa ventana,
el dial de **todos** —incluido el encargado aperturador— queda en "ESPERANDO APERTURA POR:
ENCARGADO" + "ACCESO BLOQUEADO", y en la pestaña Herramientas no aparece ninguna vía de escape
(la apertura de emergencia con testigos y el "reportar tienda cerrada" existen en el backend pero
no se ofrecen en esa pantalla).

**Verificado:** Marisol tiene el puesto correcto (`esAperturador: true`, `portadorLlaves:
'apertura'`, `jerarquiaLlaves: 1`) y aun así no puede abrir ni fichar desde la UI.

**Consecuencia:** el primer día de uso de cualquier empresa (o cualquier día en que el encargado
llegue tarde) el Reloj queda inoperable hasta que alguien intervenga por backend.

## 🟡 H8 — El horario del colaborador no llega a la etiqueta del dial

`RelojVisual.tsx:3777` pinta el turno con `shiftConfigs[user.id]?.shiftStart || '09:00'`, mientras
que el cálculo de estado (l.840) usa `shiftConfigs[user.id]?.start || currentUser?.shiftStart`.
Dos claves distintas (`shiftStart` vs `start`) sobre el mismo mapa, y `shiftConfigs` viene `NULL`
en `system_settings` de una empresa nueva.

**Verificado:** `GET /me` devolvía `shiftStart: "08:00:00"` y el dial seguía mostrando
"Turno de hoy: 09:00 - 18:00 hrs" (el default hardcodeado).

---

# Tercera tanda — flujo completo con el entorno desbloqueado

## ✅ Lo que quedó VERIFICADO end-to-end (con `b6b4709` aplicado)

Jornada completa de Marisol (Administrador Gerente) en la instancia V2:

| Paso | Resultado en BD |
|---|---|
| Apertura de sucursal | `store_daily_opening_statuses.status = opened` |
| Fichar entrada | `check_in @ 15:56:56`, sin retardo (turno 15:49) |
| Reservar comedor | `meal_reservation @ 16:00` (con aforo y validación "tiempo insuficiente") |
| Tomar comida | `meal_start @ 16:06:42` |
| Terminar comida | `meal_end @ 16:07:35` |
| Crear y asignar tarea (admin) | tarea 94 "Preparar vitrina de pasteles", 30 pts |
| Completar tarea | `completed`, `points_awarded=30`, `coins_awarded=3.00`, **1** `wallet_transaction`, wallet = 3.00 coins / 30 XP |
| **Reenviar "completed" 3 veces** | **Sigue 1 transacción y 3.00 coins** — el ancla anti-doble-pago (A3) aguanta en producción |

La aritmética del pago es exacta (30 pts × 0.10 = 3 monedas) y el ciclo del Reloj registra cada
ponche con su hora real.

## 🔴 H9 — El TaskRunner celebra la recompensa pero NO la persiste — ✅ CORREGIDO (`c7e06d8`)

**Causa raíz:** `useAppStore.isSandboxMode` arrancaba en **`true`**. Con el sandbox encendido,
`syncToBackend` y `syncAssignmentRow` (useTaskStore) retornan sin escribir ("guardado en RAM
solamente"). El **único** lugar de la app que lo apagaba era el módulo Matrix QA al montarse
(`PanelSimulador`), así que un usuario normal —que nunca entra ahí— trabajaba todo el día en
sandbox sin saberlo.

**Fix:** el default pasa a `false` (se persiste de verdad); el modo de pruebas se enciende
explícitamente con el toggle de `RelojVisual` o entrando a Matrix QA.

**Verificado end-to-end tras el fix**, completando "Hornear pan de la tarde" (50 pts) desde el
TaskRunner sin tocar la API:

```
Preparar vitrina de pasteles -> completed | pts=30 coins=3.00
Hornear pan de la tarde      -> completed | pts=50 coins=5.00
TRANSACCIONES: 2   ·   WALLET Marisol: 8.00 coins / 80 xp
```

Y tras recargar la página: siguen 2 transacciones y 8.00 monedas — el sync automático **no
duplica** el pago (el ancla `coins_awarded` sigue haciendo su trabajo con el sandbox apagado).

### Descripción original del síntoma

Al pulsar **Completar** en el TaskRunner, la UI muestra el modal "¡Recompensa Obtenida! +$3.00
monedas, +30 XP" y el monedero de la pantalla salta a $3.00… pero **no se dispara ninguna
petición de red**. En BD la asignación seguía `pending` con `coins_awarded = 0.00`, cero
`wallet_transactions` y el monedero real en 0.00.

Sólo al invocar `POST /sync/tasks` a mano se persistió todo correctamente (y ahí el pago fue
correcto y único). Es decir: **el backend está bien; el que no sincroniza es el cliente**.

**Consecuencia:** el colaborador ve que ganó monedas y al recargar las ha perdido; el supervisor
nunca ve la tarea como completada. Es el defecto más grave encontrado en esta sesión, porque
rompe la confianza en la gamificación (el usuario cree que cobró).

**Dónde mirar:** el flujo `completeTask` del `useTaskStore` y su llamada a `syncToBackend`
(comprobar si se está omitiendo el sync cuando la acción viene del TaskRunner del Reloj, y si
algún `catch` silencioso se está tragando el error).

## 🟠 H10 — El dial se vuelve a bloquear después de `meal_end`

Tras terminar la comida (secuencia correcta en BD: `check_in → meal_reservation → meal_start →
meal_end`), el dial regresó a "🔒 ACCESO BLOQUEADO / TOLERANCIA VENCIDA" pese a que el
colaborador está en turno y el backend tiene todo consistente. Se destraba recargando/renovando
el estado, pero es el mismo síntoma que H8: el recálculo posterior al regreso de comida vuelve a
usar una referencia horaria equivocada.

## 🟡 H11 — `GET` del monitor devuelve 404 en bucle

La consola del navegador registra `Error al cargar datos del monitor: 404` repetido cada pocos
segundos mientras el Reloj está abierto. No rompe la pantalla, pero ensucia el log y consume
peticiones; conviene revisar qué endpoint del monitor está pidiendo el dial que ya no existe.

---

## Contexto operativo de la prueba

- El checkout simulado requirió el opt-in `ALLOW_SIMULATED_CHECKOUT` (commit `99b7fce`): la
  instancia V2 corre con `APP_ENV=production` y el guard F3 devuelve 404 en producción. La
  variable está encendida SOLO en el `.env` de esta instancia; producción real sigue protegida.
- Datos de la prueba (tenant 2 de la BD `talent360_v2_saas`): empresa "DecorArte S.A. de C.V.",
  admin `prueba.qa360@test.local`, colaboradores Francisco Vega (Supervisor de Producción,
  supervisor), Adán Cuéllar (Asesor de Ventas, empleado), Marisol Herrera (Administrador Gerente,
  admin), todos con contraseña por defecto `password123`.
- ⚠️ El nombre coincide a propósito con la DecorArte real del jefe, pero vive en OTRA base
  (la V2 es `talent360_v2_saas`, aislada de la producción del puerto :3000).
