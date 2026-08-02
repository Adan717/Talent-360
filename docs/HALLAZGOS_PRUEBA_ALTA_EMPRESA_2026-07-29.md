# Hallazgos — prueba de alta de empresa desde cero (2026-07-29)

**Entorno:** instancia V2 en el servidor del jefe (`http://46.225.153.115:3002`), commit `99b7fce`.
**Escenario:** registro público → plan Enterprise (checkout simulado) → wizard de giro (Repostería) →
alta de 3 colaboradores desde Directorio Digital.
**Resultado del flujo:** funciona de punta a punta. Empresa aprovisionada, 7 puestos + 92 checklists
cargados por el wizard, 3 colaboradores creados. Los hallazgos de abajo son defectos encontrados
DURANTE ese recorrido; ninguno impide operar, pero el #1 afecta dinero.

---

## 🔴 H1 — El sueldo capturado en el alta NUNCA llega al dinero — ✅ CORREGIDO (`50125cc`)

**Fix en 3 piezas:** (1) helper `espejarSueldo()` en `EmployeeController::store/update` que
sincroniza `salary` ↔ `base_salary` venga la que venga (si llegan ambas, `base_salary` manda);
(2) migración `2026_07_29_120000_backfill_base_salary_from_salary` que repara a los
colaboradores ya existentes sin pisar valores capturados a mano; (3) `EmployeeSalaryMirrorTest`
(4 casos, rojo→verde).

**Verificado en la instancia V2 tras desplegar** (con respaldo previo de la BD): los 3
colaboradores quedaron con `base_salary` = su sueldo real, y una tarea de 60 min de Marisol
(sueldo 18,000) registró:

```
costo = $2,250.00   (18000/480*60, el sueldo REAL)
antes = $37.50      (300/480*60, el default)
```

Una diferencia de 60×. Suite: 837 passed / 0 failed.

### Descripción original del defecto

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

## 🟠 H2 — El wizard de giro NUNCA se abre solo — ✅ CORREGIDO (`595412c`)

**Fix sin tocar la heurística del fallback** (que sigue protegiendo a las empresas antiguas):
toda empresa nueva nace con `onboarding_completed = false` EXPLÍCITO, sembrado desde
`TenantInitializationService` — que corre en el hook `Tenant::created`, o sea para **cualquier**
vía de alta (checkout, `TenantController`, cloner). Con la clave presente, el `!isset(...)` del
fallback ya no aplica a las nuevas.

**Verificado en vivo** registrando "Panaderia La Espiga QA" en la instancia V2:

```
#3 Panaderia La Espiga QA | onboarding_completed='false' | puestos=4
```

es decir, el escenario exacto del bug (4 puestos sembrados) y aun así pendiente. Al entrar,
el asistente **"¡Bienvenido a Talent 360! — Configura tu sucursal en 4 sencillos pasos"** se
abre solo. Antes había que descubrir el botón a mano.

`OnboardingWizardAppearsForNewTenantTest` (4 casos) cubre las dos direcciones: que aparezca en
empresa nueva incluso con puestos sembrados, que NO reaparezca en las antiguas sin la clave, y
que quede marcado al completarlo.

### Descripción original del defecto

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

## 🟠 H3 — Los correos autogenerados conservan acentos — ✅ CORREGIDO (`cf04214`)

Estaba en **cinco** generadores, no en uno. Se corrigió en dos capas:

- **Backend** (`App\Support\EmailNormalizer`): normaliza la parte local al dar de alta **y al
  editar**, venga el correo autogenerado o escrito a mano. Es la red de seguridad final.
- **Frontend** (`lib/emailSlug`): lo genera bien desde el origen, para que el admin vea en
  pantalla el mismo correo que se guardará. Incluye el panel de plataforma, donde el **dominio**
  salía con acentos si la empresa los tenía en el nombre (`@panadería.com`).

**Verificado en producción:** se envió `joséramírezpeña@pruebaqa360.com` y quedó guardado como
`joseramirezpena@pruebaqa360.com`.

### Descripción original del defecto

**Qué pasa:** `RecursosHumanos.tsx:1129` arma el correo con
`name.toLowerCase().replace(/\s/g,'')` + dominio, sin normalizar diacríticos:

```
Adán Cuéllar → adáncuéllar@pruebaqa360.com
```

**Consecuencia:** un correo con acentos rompe el envío real de mail (SMTP) y puede fallar al
teclearlo en el login. Además, si dos nombres difieren solo por acentos colisionan.

**Fix sugerido:** normalizar (`NFD` + quitar diacríticos) antes de armar el correo.

## 🟡 H4 — El alta no permite elegir el rol y arrastra el puesto anterior — ✅ CORREGIDO (`f2a5745`)

Se añadió el selector **Nivel de Acceso** (Colaborador / Supervisor / Administrador) al alta —
el backend ya validaba `in:admin,supervisor,empleado`, sólo faltaba exponerlo— y el formulario
ahora se limpia **completo** al guardar (antes sólo nombre y sueldo, así que el siguiente
colaborador heredaba el puesto del anterior). Test que fija que el backend honra los tres roles.

### Descripción original del defecto

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

## 🟠 H6 — La autorización aprobada NO desbloquea el dial — ✅ CORREGIDO (`efcf0cc`, `be3b971`)

`/sync/state` expone ahora `late_authorized_user_ids` (aprobadas de HOY, en la zona horaria del
tenant y scopeadas por empresa) y el dial levanta el candado para esa gente.

**Verificado end-to-end en la instancia V2**, con el ciclo completo:

1. Marisol llega 43 min tarde (tolerancia 10) → dial con "🔒 ACCESO BLOQUEADO"
2. Solicita autorización desde el propio dial → queda `pending`
3. El admin la aprueba en el panel del Monitor 360 → `approved`
4. Marisol recarga → **el candado desapareció** y el dial quedó operativo

### Descripción original del defecto

`ClockService` (l.848) respeta correctamente la aprobación: si existe una fila `approved` en
`late_authorization_requests` para ese usuario y fecha, permite el `check_in` pese al Retardo
Extremo. **Verificado por API:** `POST /clock/punch` respondió `200` y registró el fichaje con
404 min de retardo e incidencia LFT de descuento.

Pero **el dial nunca consulta ese estado**: tras la aprobación sigue mostrando "🔒 ACCESO
BLOQUEADO / TOLERANCIA VENCIDA" y no ofrece botón para fichar. El colaborador autorizado queda
sin poder registrar su entrada aunque el servidor ya se lo permite.

**Fix sugerido:** exponer el estado de la solicitud al dial (o incluirlo en el payload de
`/clock/state`) y levantar el bloqueo del FE cuando esté `approved`.

## 🟠 H7 — Deadlock de apertura — ✅ CORREGIDO (`efcf0cc`, `be3b971`)

**Causa:** el candado por tolerancia se evaluaba ANTES de la rama de tienda cerrada, así que el
encargado de apertura que llegaba fuera de tolerancia nunca alcanzaba el botón de abrir la
sucursal. Nadie abría → con la tienda cerrada nadie del equipo podía fichar.

**Fix:** con la tienda cerrada, quien puede abrirla (el responsable del día o cualquiera con
`esAperturador`) pasa a la rama de apertura — la acción que destraba a todo el equipo. Su retardo
se sigue registrando server-side al fichar; lo que se elimina es el candado sin salida.

La regla se extrajo a `Frontend/src/components/reloj/logic/accessBlock.ts` (función pura, junto a
las otras 5 del Reloj) con **12 tests** que fijan tanto los arreglos como que no se abrieron
agujeros:

- el colaborador **sin** llaves sigue bloqueado aunque la tienda esté cerrada
- ser aperturador **no** da paso libre si la tienda ya está abierta (ahí no hay deadlock)
- la autorización de OTRO colaborador no levanta tu candado

Antes esa decisión vivía enterrada en ~500 líneas de condiciones dentro de `useClockEngine` y
sólo se podía comprobar abriendo la app a la hora exacta del escenario.

### Descripción original del defecto

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

## 🟠 H10 — El dial se vuelve a bloquear después de `meal_end` — ✅ CAUSA LATENTE CORREGIDA (`cf04214`)

**Nota honesta:** el síntoma exacto **no se pudo reproducir** al reintentarlo con datos
consistentes — se sembró la jornada completa (`check_in → meal_start → meal_end`) con retardo y
se verificó por API que llega correcta al frontend (`date = '2026-07-30'`, secuencia íntegra).

Pero sí se encontró una **causa latente que produce exactamente ese síntoma**:
`useAppStore.fetchState` filtraba los fichajes del día con la fecha del **dispositivo**
(`new Date()`), mientras el backend los fecha con la zona horaria del **tenant**. Cuando ambas
no coinciden —un colaborador de viaje, un dispositivo con la zona mal puesta, una empresa que
opera en otra región— el filtro descarta **todos** los fichajes del día: sin `check_in` el motor
cae a `inactive` y, si hay retardo, aparece el candado sin salida. Es la misma familia del bug
que ya se corrigió en el backend (corte del día por tenant, A5/M5), y el mismo patrón estaba
también en el filtro de la bitácora del día.

**Fix:** `lib/jornadaDelDia` (función pura, 10 tests) corta el día con la zona del tenant y
tolera que la fecha venga en ISO completo.

**Defensa en profundidad añadida:** quien ya registró su entrada hoy no puede ver el candado de
"no puedes entrar" — ese candado existe para impedir ENTRAR fuera de tolerancia, no aplica a
quien ya está dentro. Si el estado del motor se recalcula mal por cualquier motivo, el dato
autoritativo del backend manda (3 casos nuevos en `accessBlock.test.ts`).

### Descripción original del síntoma

Tras terminar la comida (secuencia correcta en BD: `check_in → meal_reservation → meal_start →
meal_end`), el dial regresó a "🔒 ACCESO BLOQUEADO / TOLERANCIA VENCIDA" pese a que el
colaborador está en turno y el backend tiene todo consistente. Se destraba recargando/renovando
el estado, pero es el mismo síntoma que H8: el recálculo posterior al regreso de comida vuelve a
usar una referencia horaria equivocada.

## 🟡 H12 — Una empresa nueva ve el nombre de OTRA — ✅ CORREGIDO (`039cb9d`)

**Backend:** `TenantInitializationService` siembra `company_name` con el nombre del tenant. Va
FUERA del bucle de `$defaults` a propósito — aquel usa `updateOrInsert` y re-aplica el valor en
cada llamada, y este dato es editable por el cliente desde Configuración: una re-inicialización
no debe revertir su marca (hay test que lo fija). Migración de reparación para las empresas ya
creadas, sólo donde falta la clave.

**Frontend:** el nombre ajeno estaba hardcodeado en 3 lugares, y uno era más serio de lo que
parecía:

- `MonitorActividadesTiempoReal`: el saludo — ahora usa el nombre real y cae a algo neutro.
- **`AtsPortalSettings`**: el estado inicial traía `name: 'DecorArte 360'` y
  `public_slug: 'decorarte360'`. Si la carga de configuración fallaba, el admin veía —**y podía
  GUARDAR**— la marca y el slug público de otra empresa como suyos.
- `WebPublicaOrganizacion`: la web **pública** enseñaba la marca equivocada a visitantes
  externos mientras cargaba la real.

**Verificado en vivo:** la Panadería ahora saluda con *"Bienvenido a Panaderia La Espiga QA"*.

*Nota de formato:* `company_name` convive en dos formatos en BD — `json_encode` (lo que siembran
el servicio y la migración) y texto crudo (lo que guarda `POST /sync/settings`). El backend ya
tolera ambos (`json_decode` con fallback al crudo en `getState`), pero conviene unificarlo si
algún día se toca ese endpoint.

### Descripción original del defecto

Detectado al verificar H2: al registrar "Panaderia La Espiga QA", el encabezado del Monitor 360
saludaba con **"Bienvenido a DecorArte 360"** — el nombre del tenant 1. La causa es que el alta
no siembra `company_name` en `system_settings` (queda NULL) y el frontend cae a un default
hardcodeado con el nombre de la empresa original del producto.

**Fix sugerido:** sembrar `company_name` con el nombre del tenant en
`TenantInitializationService` (donde ya se siembra el resto), y cambiar el default del FE por
algo neutro (p. ej. el `tenant.name` del usuario, o "Tu Empresa").

## 🔴 H11 — El Monitor 360 estaba funcionalmente MUERTO — ✅ CORREGIDO (`f2a5745`)

**Era mucho más que "un 404 molesto".** Las llamadas del monitor llevaban `/api/v1/`
**duplicado** (`axiosInstance` ya trae esa base), así que salían como
`/api/v1/api/v1/admin/dashboard/monitor` → 404. Y no era sólo el sondeo: eran **5 llamadas**,
es decir el módulo entero:

| Llamada | Qué dejaba de funcionar |
|---|---|
| `GET /admin/dashboard/monitor` | El sondeo cada 5s → el monitor siempre en ceros |
| `POST .../suggest-work-plan` | Sugerir el plan del día con IA |
| `POST .../create-task` | Crear tarea desde el monitor |
| `POST .../vendors` | Registrar proveedor en sitio |
| `POST .../send-message` | Enviar mensaje al equipo |

Como el `catch` sólo hacía `console.error`, todo fallaba **en silencio**: los botones parecían
no hacer nada y el panel se veía vacío como si no hubiera actividad.

**Verificado en vivo tras el fix:** el sondeo responde `200`, el monitor muestra datos reales
("Personal 1/1", "Eficiencia 94%", Marisol Herrera / Administrador Gerente en el listado) y el
registro de proveedores funciona de punta a punta — `POST .../vendors → 200` y el proveedor
aparece en el panel con su hora de llegada y quién lo atendió. Cero errores en consola.

### Descripción original del síntoma

La consola del navegador registra `Error al cargar datos del monitor: 404` repetido cada pocos
segundos mientras el Reloj está abierto. No rompe la pantalla, pero ensucia el log y consume
peticiones; conviene revisar qué endpoint del monitor está pidiendo el dial que ya no existe.

---

# Cuarta tanda — jornada completa de regresión (2026-07-30, con los 12 fixes aplicados)

Se corrió la jornada de punta a punta con día en blanco. **Todo lo verificado funciona**, y de
paso quedaron a la vista los controles de negocio (que actúan como deben):

| Paso | Resultado |
|---|---|
| Login y contexto | Marisol / Admin / DecorArte S.A. de C.V. |
| Fichar entrada | `check_in @ 10:06:58`, **sin retardo**, cronómetro corriendo |
| Crear y completar tarea (40 pts) | `completed` · **4.00 coins / 40 XP** · **1 sola transacción** |
| Comida | **Bloqueada correctamente**: "Disponible a partir de las 11:36" — la ventana respeta el horario |
| Salida anticipada | **Bloqueada correctamente**: exige motivo **y** validación por QR del supervisor |
| Salida ordinaria | **Bloqueada** hasta completar el checklist de cierre; tras completarlo (luces, caja fuerte, alarma) → `check_out @ 10:14:09` |
| Bitácora | 2 eventos registrados |
| Pre-nómina semanal | 4 empleados, **0 errores**, cada uno con SU sueldo real (14,000 / 9,000 / 18,000 / 8,500) y Marisol con una falta menos por haber trabajado hoy |

Los tres "bloqueos" no son fallos: son los candados de negocio del Reloj haciendo su trabajo.

## 🟡 H13 — Estado de apertura contradictorio — ✅ CORREGIDO (`189eee9`, `df01826`)

Nueva `logic/estadoSucursal.ts` (función pura, 8 tests) que **combina las dos fuentes** en vez
de mirar sólo el horario:

| Situación | Antes | Ahora |
|---|---|---|
| En horario y alguien abrió | Abierto | **Abierto** (verde) |
| En horario, sin registro aún | Abierto | **Pendiente de apertura** (ámbar) |
| En horario, la apertura falló | Abierto ❌ | **Sin abrir** (rojo) |
| Fuera de horario | Cerrado | **Cerrado** — el registro `opened` es del EVENTO, no significa que siga operando |

El texto de abajo también se ancla al registro e informa cuando la apertura no se completó a
tiempo, en vez de decir "Apertura a cargo de X" como si todo estuviera bien.

**Dos correcciones que salieron de probar el propio fix:**

1. Un test propio cazó que mi primera versión priorizaba el registro sobre el horario (decía
   "Abierto" a las 23:00 si se había abierto en la mañana).
2. Al verificar en vivo, el bug **seguía intacto**: en la V2 el registro existía (`failed`)
   mientras `isFeatureUnlocked('store_opening')` devolvía `false`, así que la lógica caía al
   camino "sólo horario". Ahora, si el día **tiene** registro, ese dato manda; el flag premium
   sólo decide qué hacer cuando no hay registro que contrastar.

**Verificado en vivo:** la píldora pasó de decir "ABIERTO" a **"SIN ABRIR"**, coherente con el
`failed` que tenía el sistema.

### Descripción original del defecto

Durante esta jornada, `store_daily_opening_statuses.status` quedó en **`failed`** (nadie abrió
dentro de la ventana) mientras el dial mostraba **"ESTADO DE LA SUCURSAL: ABIERTO"**, porque esa
etiqueta se deriva del HORARIO configurado, no del registro real de apertura.

Es exactamente la contradicción que el propio código advierte en `logic/storeSchedule.ts`
("esto NO decide por sí solo si la tienda está operando… quien consuma esto debe combinar
ambas"). No bloqueó la operación en esta prueba, pero deja el tablero del gerente diciendo lo
contrario de lo que registró el sistema.

**Fix sugerido:** que la etiqueta combine ambas fuentes — horario **y**
`store_daily_opening_status` — mostrando algo como "En horario, pendiente de apertura" cuando
el registro no exista o haya quedado `failed`.

## 🟡 H14 — El dial no reacciona al cambio de horario del turno — ✅ CORREGIDO (`189eee9`)

El `useEffect` que hidrata `shiftConfigs` sólo rellenaba huecos (`if (!merged[userId])`), así
que si RRHH corregía el horario de alguien a media jornada el dial seguía con el viejo hasta
recargar. Ahora se guarda una huella del valor que mandó el **servidor**: cuando cambia, se
adopta el nuevo; lo que el usuario editó en la sesión se conserva mientras el servidor no
cambie.

### Descripción original del defecto

Al mover el fin de turno para probar la salida ordinaria, el dial siguió ofreciendo "Salida
Anticipada" y "Tomar Comida" con la ventana vieja: conserva la configuración horaria de la carga
inicial. En operación normal el horario no cambia a media jornada, así que el impacto es bajo,
pero conviene que el motor relea la configuración cuando el estado se refresca.

---

## 🔴 H15 — Abrir la sucursal escribía en la sucursal de OTRA empresa — ✅ CORREGIDO

**Encontrado en la SEGUNDA jornada de regresión (2026-07-30), después de dar H13 por cerrado.**

### Cómo salió

Con el escenario limpio (tienda 11:18–19:23, turno 11:20, día en blanco) el dial mostró
correctamente **"PENDIENTE DE APERTURA"** — el arreglo de H13 funcionando. Al llamar
`POST /store-opening/open-and-clock-in` el backend respondió **200 "Tienda abierta con éxito y
entrada registrada"**… y el dial pasó a **"SIN ABRIR"**.

La tabla del día tenía **dos filas contradictorias para el mismo tenant**:

```
id=8  store_id=1  tenant=2  status=opened   prog=11:18
id=7  store_id=2  tenant=2  status=failed   prog=11:18
fichajes: check_in @ 11:24:06
```

### Causa

`R52` (merge F3) había quitado el `store_id` del cliente en la **LECTURA**
(`getTodayStatus` → `TenantStore::defaultIdFor($tenantId)`), pero las **ESCRITURAS** se quedaron
con `$request->input('store_id', 1)`. El dial no manda ese campo, así que **las 9 escrituras
caían al `1` hardcodeado**, que es la sucursal del tenant 1.

Resultado: la empresa 2 abría su tienda escribiendo sobre la sucursal de **otra empresa**,
mientras su propio tablero —que sí lee la suya— la seguía viendo sin abrir. La apertura era
funcionalmente **imposible de reflejar**, y el registro propio quedaba en `failed` al vencer la
ventana. Esto explica también por qué en la primera jornada la apertura se quedó en `failed`.

Doble consecuencia:
1. **Funcional**: abrir la sucursal no surtía efecto para nadie.
2. **Aislamiento**: con `stores` de ids GLOBALES, el `store_id` del cliente era además una
   superficie de escritura cross-empresa.

### Arreglo

Un único resolvedor `sucursalDelTenant()` en `StoreOpeningController`, cableado en los **9**
puntos de escritura: `createAssignment`, `openStoreAndClockIn`, `reportAbsence`, `reportLate`,
`doorNotice`, `submitPaseListaRatings`, `closingChecklist`, `emergencyOpen`,
`reportStoreStillClosed`. El `store_id` que mande el cliente se ignora a propósito.

El mismo patrón vivía en `MealReservationController` (2) y `SillaController` (3). Ahí lectura y
escritura coincidían en el `1`, así que no rompía la función, pero la fila apuntaba igual a una
sucursal ajena — unificados al mismo criterio.

Cubierto por `StoreOpeningStoreIdIsolationTest` (4 casos), incluido uno que comprueba que **lo
que se abre es lo que el dial lee** —el extremo que faltaba— y otro que un `store_id: 999`
enviado por el cliente no desvía la escritura.

### Limpieza del histórico (2026-07-30)

El arreglo evita que se creen filas nuevas, pero **no borra las que el bug ya había dejado**. Se
barrió la instancia V2 con este criterio: fila cuyo `tenant_id` NO coincide con el dueño de su
`store_id`.

De las **8 tablas** con columna `store_id` (`contingency_declarations`, `meal_queue_rounds`,
`pase_lista_ratings`, `silla_requests`, `store_daily_opening_statuses`,
`store_opening_assignments`, `store_opening_events`, `store_opening_settings`), sólo 3 tenían
filas cruzadas — **7 en total**, todas del tenant 2 sobre la sucursal del tenant 1:

| Tabla | Filas | Detalle |
|---|---|---|
| `store_daily_opening_statuses` | 1 | id 8 (`opened`, 30-jul) |
| `store_opening_events` | 5 | ids 2, 3, 6, 8, 9 (open_store / closing_checklist / failed) |
| `store_opening_settings` | 1 | id 3 (duplicado de la id 2, que sí está en la sucursal correcta) |

Antes de borrar se comprobó que **ninguna era el único registro de nada**: el tenant 2 ya tenía
su `status` (id 7), sus `settings` (id 2) y sus `events` (10 y 11) en su propia sucursal. Y que
**cero foreign keys** apuntan a esas tres tablas, así que el borrado no arrastra nada.

Respaldadas en el servidor antes del `DELETE` (`/root/respaldos/h15_huerfanas_*.csv`) y borradas
en una sola transacción. Resultado: **0 filas cruzadas en las 8 tablas**, y la app sigue leyendo
lo suyo (`status` id 7 `opened`, `settings` id 2).

⚠️ Si el bug estuvo activo en la producción del jefe, **ahí hay que repetir este barrido** — el
código corregido no lo hace solo. Un tenant sin fila en `stores` no reabre el agujero:
`TenantStore::defaultIdFor()` siembra la sucursal del tenant en vez de caer al `1`.

---

## 🟠 H16 — El dial anunciaba un turno y el sistema cobraba contra otro — ✅ CORREGIDO

**Encontrado en la SEGUNDA jornada de regresión (2026-07-30), al cerrar el recorrido.**

### Cómo salió

Con Marisol ya fichada y su expediente en **11:20 – 19:23**, el encabezado del dial mostraba
**"Turno de hoy: 09:00 - 18:00 hrs"**. El mismo `check_out` de las 11:50 devolvió del backend:

```json
"early_departure": { "minutes_early": 452, "authorized": false }
```

452 minutos antes de las 11:50 son las **19:22** — el backend estaba midiendo contra el turno
REAL del expediente, no contra el 18:00 que el colaborador tenía delante.

### Causa

Discrepancia de grafía dentro del mismo objeto. `useClockEngine` construye cada configuración
como `{ start, end, ... }` (`initialShifts`, línea 433), y la etiqueta la leía como
`shiftConfigs[id]?.shiftStart` / `?.shiftEnd`. Esas claves **no existen nunca**, así que la
etiqueta caía **siempre** a su literal `'09:00'`/`'18:00'` — para todos los colaboradores de
todas las empresas, con cualquier horario. Era el único sitio con la grafía mala: los otros diez
usos de `shiftConfigs` del módulo ya leían `.start`/`.end`.

No es cosmético: el backend calcula retardo y salida anticipada contra
`employees.shiftStart/shiftEnd`. Un colaborador de turno 11:20–19:23 que leyera "18:00" en su
propio dial y se fuera a esa hora se llevaba **83 minutos de salida anticipada** estampados. La
app le decía una hora y le cobraba por otra.

### Arreglo

`logic/etiquetaTurno.ts` centraliza la cascada que el resto del motor ya usaba
(`?.start || currentUser?.shiftStart || '09:00'`, RelojVisual:841), recorta los segundos que
trae el expediente y marca `esReal: false` cuando está mostrando el valor por defecto — incluido
el caso de un solo extremo conocido, que era justo lo que hacía parecer fiable el dato.

Cubierto por `etiquetaTurno.test.ts` (9 casos), incluido uno que comprueba que **las claves
equivocadas que causaron el bug no cuelan como turno**.

---

## 🟠 H17 — La pantalla del comedor decía "0 ocupados" siempre — ✅ CORREGIDO

**Encontrado en la TERCERA jornada de regresión (2026-07-30), recorriendo el dial como empleado
raso (Adán Cuéllar) en vez de como admin.**

### Cómo salió

Con dos reservas activas en base de datos (13:00 de Marisol, 15:00 de Adán), el endpoint de
bloques respondía `booked: 0` y `available: 5` en **todos** los bloques, y marcaba
`is_my_reservation: false` en el mismo bloque que `my_reservation` sí reconocía como propio.

### Causa

Desajuste de formato entre dos representaciones de la misma hora.
`meal_reservations.slot_start` es de tipo TIME, así que el `groupBy('slot_start')` devuelve
claves **`"13:00:00"`**, mientras los bloques configurados
(`meal_capacity_settings.available_slots`) son **`"13:00"`**. El `keyBy` compara STRINGS en PHP,
así que `$reservationCounts['13:00']` nunca acertaba y `$booked` caía a su `?? 0`. Igual en
`is_my_reservation`: `'15:00:00' === '15:00'` es false.

Misma clase de defecto que H16 (`09:00` vs `09:00:00`): dos grafías de la misma hora comparadas
como texto.

### Alcance honesto

El aforo **sí** se aplicaba al reservar: el chequeo de `store()` cuenta en SQL, y ahí Postgres
castea el literal a TIME y compara bien. El daño era de LECTURA — se ofrecían "5 disponibles" en
un bloque lleno y la reserva moría con un 422 "está lleno", y cualquier panel de ocupación del
comedor mostraba cero permanente.

Pero eso destapó algo peor de fondo: **la regla de aforo dependía de una conversión implícita
del motor**. Se comparaba `where('slot_start', '12:00')` contra una columna TIME y funcionaba
por el cast de Postgres, no por diseño.

### Arreglo

Dos helpers en el controlador: `hhmm()` normaliza a HH:MM todo lo que se compare en PHP, y
`grafiasDelBloque()` hace que los **5** conteos en SQL busquen ambas grafías, para que la regla
de aforo se sostenga sola en vez de depender del motor.

Cubierto por `MealSlotsBookedCountTest` (4 casos), incluido el extremo que faltaba: **lo que se
ofrece en pantalla coincide con lo que el alta acepta**.

### ⚠️ Lo que este hallazgo dice de la suite

Los 4 tests **pasaban antes del arreglo**. La suite corre en **sqlite**, que guarda TIME como el
texto que le des (`'13:00'`), mientras producción es **Postgres**, que lo normaliza a
`'13:00:00'`. En sqlite las dos grafías coinciden por accidente y el bug es invisible.

El test tuvo que insertar la reserva con la grafía real de Postgres (`reservaComoEnProduccion()`)
para reproducirlo. **Toda esta familia de defectos —tipos TIME/DATE, casts implícitos— es ciega
para la suite actual.** Vale la pena valorar una pasada de la suite contra Postgres en CI.

---

## 🔴 H18 — Los colaboradores con acento en el nombre NO PUEDEN INICIAR SESIÓN — ✅ CORREGIDO

**Encontrado en la TERCERA jornada de regresión (2026-07-30), al entrar por el formulario real
en vez de por la API.**

### Cómo salió

Al intentar entrar como Adán Cuéllar (`adáncuéllar@pruebaqa360.com`) desde la pantalla de login,
el formulario no hacía nada. La petición **nunca sale del navegador**: la validación nativa de
`<input type="email">` rechaza el valor.

```
El texto seguido del signo "@" no debe incluir el símbolo "á".
```

### Por qué se pasó por alto la primera vez

H3 corrigió la GENERACIÓN de correos, pero no tocó los ya existentes. Al detectarlo se probó el
login **por API** (curl) y funcionó —el backend compara bytes—, así que se anotó como "dato
sucio pendiente de limpiar". Esa lectura era incorrecta: una persona no entra por curl, entra
por el formulario, y ahí está bloqueada.

En una plantilla mexicana —Adán, José, María, Hernández, Muñoz, Íñiguez— esto deja fuera del
sistema a buena parte de la empresa, sin más síntoma que un botón que no responde.

### Arreglo

Migración `2026_07_30_190000_backfill_emails_con_acentos`: normaliza con el
`EmailNormalizer` de H3 los correos que quedaron atrás, en `users` y en la copia que guarda
`employees`.

**Regla ante colisiones**: si el correo normalizado ya pertenece a otra fila (`josé@x` →
`jose@x` cuando ese `jose@x` ya existe), se deja el original **intacto** y se registra en el log
para resolverlo a mano. Pisar el correo de otra persona —o fusionar dos accesos distintos— es
más grave que el bloqueo que se viene a arreglar.

Cubierto por `BackfillEmailsConAcentosTest` (6 casos): normalización, arrastre del expediente,
no reescribir filas que ya están bien, colisión, idempotencia y el caso de dos empresas.

### Patrón que se repite

Es la **tercera** vez en esta ronda que un arreglo corrige el comportamiento pero deja los datos
viejos como estaban: H15 (filas cruzadas de sucursal), H3/H18 (correos con acentos) y, en su
momento, H1 (`base_salary`, que sí llevó su backfill). Al corregir algo que genera datos, la
pregunta obligada es **qué pasa con lo ya generado**.

---

## 🔴 H19 — El módulo de apertura nunca se pudo configurar (500 al asignar llaves) — ✅ CORREGIDO

**Encontrado en la CUARTA pasada (2026-07-30), al intentar montar la jerarquía de portadores de
llaves para probar la cascada.**

`POST /store-opening/assignments` devolvía **"Server Error"** siempre. Los tres endpoints de
gestión hacían `('employee:id,name,email,role,user_id')` y **`employees` no tiene columna
`role`** — el puesto vive en `job_role_id`. Postgres respondía
`SQLSTATE[42703]: Undefined column: role`.

### Por qué no saltó antes

Dos motivos que se reforzaban:

1. **sqlite lo toleraba.** Ante unas comillas dobles que no resuelven a una columna, sqlite las
   trata como **literal de texto**: devolvía una columna `"role"` con el valor `'role'`. Postgres
   las trata como identificador estricto. Los tests pasaban.
2. `getAssignments` **no fallaba con la lista vacía**, porque Eloquent no ejecuta el eager-load si
   no hay filas. El listado "funcionaba" mientras el módulo estaba muerto.

### Lo que arrastraba

Sin asignaciones, la apertura del día se crea **sin responsable** y el sistema estampa
`failed_no_responsibles`. Ese patrón llevaba **tres jornadas** apareciendo en los eventos sin que
se entendiera de dónde salía. Con ello quedaban también inalcanzables:

- toda la cascada de delegación de llaves (reportar falta/retardo, traspaso al suplente),
- el botón "Llamar a Encargado de Llaves" (R100), que nunca tenía a quién llamar,
- el checklist y el pase de lista de apertura, que sólo se activan para el responsable.

**Arreglo**: se pide `job_role_id` y se carga la relación del puesto. Ojo con la clave: la
relación se carga como `jobRole` pero Eloquent la **serializa en snake_case**, así que al panel
le llega `employee.job_role.name` — que además es mejor dato que el anterior (muestra "Supervisor
de Producción" en vez del rol de sistema). Cubierto por `StoreOpeningAssignmentCrudTest` (5 casos).

---

## 🔴 H20 — El modo OFFLINE estaba roto por UN carácter — ✅ CORREGIDO

`tenant_offline_secrets.secret` se creó como `$table->string('secret')` → **varchar(255)**, y
`Crypt::encryptString()` devuelve **256** caracteres. Postgres rechaza el INSERT con
`value too long`; **sqlite no aplica los límites de longitud de VARCHAR**, así que la suite lo
daba por bueno.

`GET /clock/offline-secret` devolvía error, y sin secreto el dial **no puede firmar la cola
offline** — es decir, fichar sin internet, que es justo el caso para el que esa cola existe. En
una tienda con mala conexión, el reloj se queda sin su red de seguridad.

**Arreglo**: la columna pasa a `text`. El error de origen fue poner un límite fijo a un valor
cuyo tamaño depende del algoritmo de cifrado y del padding, y que puede cambiar al actualizar
Laravel. Cubierto por `OfflineSecretCabeEnLaColumnaTest`.

---

## 🛠️ El arnés que destapó H19 y H20: la suite contra Postgres

`phpunit.postgres.xml` (nuevo) corre la MISMA suite contra Postgres, que es el motor de
producción:

```
php artisan test -c phpunit.postgres.xml
```

Primera ejecución: **10 fallos** que la suite de sqlite daba por verdes. Tres causas raíz:

| Causa | Tests | Veredicto |
|---|---|---|
| `column "role" does not exist` | 3 | **Bug real de producción** (H19) |
| `value too long for varchar(255)` | 3 | **Bug real de producción** (H20) |
| FK `store_opening_assignments_employee_id` | 3+1 | Defecto de los tests |

El tercer grupo merece nota aparte: tres tests de apertura de emergencia asumían que
`users.id == employees.id` "por crearse en el mismo orden". Eso sólo se sostiene si las dos
secuencias van a la par — cierto en sqlite, falso en Postgres con la base ya sembrada. Ahora
resuelven el id real.

Tras los arreglos: **875 tests, 0 fallos contra Postgres**.

**Familias de defectos que sqlite no puede ver**, y que conviene tener presentes: tipos TIME/DATE
(H17), identificadores entre comillas dobles (H19), límites de longitud de VARCHAR (H20),
integridad referencial y orden de secuencias. Merece la pena correr este arnés antes de cada
publicación, aunque sea más lento (~8 min contra ~5).

---

## ✅ Turno partido — VERIFICADO, sin defectos

Probado en vivo con dos colaboradores (entrada → salida → segunda entrada el mismo día):

- El guard de "un check-in por día" **permite** la segunda entrada.
- El retardo **no se vuelve a cobrar** en la segunda mitad (`is_late: false`).
- La nómina cuenta **un solo día asistido**: el sueldo es diario (`base/6`) y se paga por
  `attendedDates`, no por horas, así que un turno partido no puede inflar el pago. Confirmado:
  tras el turno partido, los colaboradores siguen con 5 faltas de 6, no con 4.

---

## 🔴 H21 — Turnos que CRUZAN MEDIANOCHE: una jornada se pagaba como dos días, y los retardos de madrugada no se cobraban — ✅ CORREGIDO

**Encontrado al probar el escenario nocturno (2026-07-30).** Reproducido en vivo con la tienda
en 22:00–02:00 y un colaborador con ese mismo turno.

### Defecto 1: una jornada cuenta como dos días

`processPunch` asigna `$date = $now->format('Y-m-d')` — el día **calendario** en la zona del
tenant, **sin corte de jornada**. La conversión de zona es correcta; lo que falta es el concepto
de "día de negocio".

Una sola noche trabajada queda partida:

```
date        type       time
2026-07-29  check_in   22:00:00
2026-07-30  check_out  02:00:00
```

Como la nómina cuenta `attendedDates` (días con al menos un check_in/check_out), **una noche
genera DOS días asistidos**. Medido en la nómina real:

| | faltas | neto |
|---|---|---|
| antes de la noche | 5 | 1 652.78 |
| después de UNA noche | **4** | **3 305.56** |

El neto se **duplicó** por una sola jornada. Un turno nocturno de 6 noches cobraría días que no
existieron. Y de paso, cada día queda con un turno a medias: el 29 con una entrada sin cerrar
(dispara los flags de turno incompleto) y el 30 con una salida huérfana.

### Defecto 2: los retardos después de medianoche nunca se cobran

Con turno 22:00–02:00, un check-in a las **00:30** —dos horas y media tarde— se registra como
**puntual**:

```
date        type      time      is_late  late_minutes
2026-07-30  check_in  00:30:00  f        0
```

El cálculo compara 00:30 (30 minutos desde medianoche) contra un `shiftStart` de 22:00
(1 320 minutos). Como 30 < 1 320, concluye que llegó **temprano**. En un turno nocturno, todo lo
que se fiche pasada la medianoche es impune.

### Alcance

Los dos defectos **favorecen al trabajador y perjudican a la empresa**, y ninguno deja rastro
visible. Sólo afectan a operaciones con turnos que cruzan medianoche: DecorArte cierra a las
19:23, así que **hoy no le afecta al negocio actual**, pero sí a cualquier cliente 24h, farmacia,
gasolinera o tienda de conveniencia.

### El arreglo

`App\Support\JornadaLaboral` introduce el **día de negocio**. La regla se aplica **sólo** si el
turno del colaborador cruza medianoche (`shiftStart` posterior a `shiftEnd`); en cualquier otro
caso —o si falta el horario— devuelve la fecha calendario, exactamente como antes. Los turnos
diurnos, que son la inmensa mayoría, no pueden verse afectados.

El corte es el **punto medio del hueco** entre el fin de un turno y el inicio del siguiente. Con
22:00–02:00 cae a las 12:00, así que fichar a las 22:00 abre la jornada del día, y a las 02:00
—o a las 03:00 si se salió tarde— la cierra. Se eligió el punto medio en vez de un margen fijo
porque se adapta solo a cualquier turno (con 23:00–07:00 el corte queda a las 15:00) sin números
mágicos por caso.

**El retardo se corrigió de paso, sin tocarlo.** `processPunch` ancla la hora esperada a `$date`
(`"$date $shiftStart"`), así que con la fecha de jornada correcta un fichaje de las 00:30 se
compara contra las 22:00 de AYER y da 150 minutos, en vez de compararse contra las 22:00 del
mismo día y dar cero. Un solo cambio en lugar de dos.

**Efecto colateral detectado y cerrado:** varios puntos reconstruyen un instante pegando
`"$date $time"`. Como la madrugada se guarda con la fecha del día anterior, eso apuntaba a un
instante 24 h antes del real — el mínimo de turno para poder comer daba ~24 h y dejaba pasar la
comida al momento. `JornadaLaboral::instanteDe()` deshace el desfase, y un test de ida y vuelta
comprueba que archivar y reconstruir devuelve el instante intacto.

**Cobertura**: `JornadaLaboralTest` (20 casos: fronteras del corte, 23:59/00:00/00:01, cambio de
mes y de año, horario de verano, ida y vuelta) y `TurnoNocturnoCruzaMedianocheTest` (4 casos de
integración, incluido el control de turno diurno).

---

## 🔴 H22 — Una nómina ya firmada podía recalcularse y revertirse — ✅ CORREGIDO

**Encontrado al recorrer el ciclo de aprobación de nómina (2026-08-01).**

`EmployeePayrollController::approvePayrollWeekly` hacía un `updateOrCreate` que **recalculaba y
sobrescribía todos los campos** —importe incluido— y forzaba `status = 'approved_by_employee'`,
sin mirar en qué estado estaba la fila. Dos daños, medidos en vivo:

1. **Firmar dos veces pisaba la fecha de la primera firma.** Comprobado: `21:58:32` → `21:58:52`.
   `employee_approved_at` es la constancia de que el trabajador aceptó de conformidad ESE cálculo
   en ESE momento; pasaba a decir la fecha de la última pulsación.
2. **Una nómina ya autorizada por la empresa volvía atrás.** Al forzar el estado, un periodo
   aprobado por el administrador regresaba a "firmada por el empleado" con un importe recalculado.

Lo que sí funcionaba: el comando `payroll:calculate-weekly` **respeta** las nóminas firmadas —se
verificó en vivo que un recálculo no las toca—. El agujero estaba sólo en este endpoint.

**Arreglo**: si ya está firmada, la respuesta es idempotente y devuelve la fila original, con su
fecha y su importe. Si la empresa ya autorizó el pago, responde `409` y no toca nada.

---

## 🔴 H23 — La aprobación de nómina del administrador NO SE GUARDABA — ✅ CORREGIDO

`PayrollController::approvePayroll` era un **stub**:

```php
public function approvePayroll(Request $request)
{
    return response()->json([
        'status' => 'success',
        'message' => 'Nómina aprobada y lista para timbrar.'
    ]);
}
```

Devolvía éxito **sin tocar la base de datos**. Verificado en vivo: tras aprobar, el estado seguía
en `approved_by_employee`. La pantalla confirmaba una autorización de pago que no existía — sin
estado, sin fecha y sin responsable.

Es la clase de defecto más difícil de detectar mirando la aplicación: no hay error, no hay
pantalla rota, y el mensaje afirma justo lo que el usuario esperaba leer.

**Arreglo**: migración con `admin_approved_at` y `admin_approved_by`, y el método ahora
(1) exige rol de administrador o supervisor, (2) acota el `employee_id` al tenant —los ids son
globales—, (3) **impide autorizar el pago propio**, (4) exige que el trabajador haya firmado
antes, porque no se autoriza un cálculo que él no ha visto, y (5) es idempotente: reautorizar no
reescribe quién ni cuándo fue la primera vez.

**Cuidado con el consumidor real.** El botón "Aprobar y Timbrar (CFDI)" de `ReportesManager`
llama a este endpoint **sin `employee_id`**: aprueba el periodo completo. Exigir el campo habría
roto el único camino que la empresa usa de verdad. El endpoint soporta los dos modos; el masivo
salta las que aún no ha firmado el trabajador y **dice cuántas quedaron fuera**, en vez de dar un
"listo" que oculte que media plantilla sigue pendiente.

Cubierto por `NominaAprobacionTest` (12 casos).

### Nota aparte, no corregida

`BillingController::timbrarNomina` toma el `net_salary` **del cliente** en vez de leerlo de la
nómina aprobada. Requiere ser administrador y emite un CFDI real ante el SAT, así que conviene
que el importe timbrado salga de la fila autorizada y no del payload. Queda anotado como mejora
de endurecimiento, no se tocó en esta ronda.

---

# Tercera tanda — módulo de TAREAS Y RUTINAS (2026-08-01)

## ✅ Lo que se verificó funcionando

- **Rutina de apertura → asignación automática**: creada la rutina con `trigger='apertura'` y sus
  tareas, al abrir la tienda el sistema repartió las 3 solo, con ids deterministas
  (`open_{task}_{user}_{fecha}`).
- **Idempotencia**: abrir dos veces no duplica asignaciones.
- **El ancla anti-doble-pago** sigue aguantando (verificado en la tanda anterior con 3 re-syncs).

## 🔴 H25 — El listado de tareas se vaciaba las últimas 6 horas de cada día — ✅ CORREGIDO

`GET /task-assignments` filtraba por `Carbon::now()` —la fecha del **servidor**, en UTC— mientras
las asignaciones se guardan con la fecha de la zona del **tenant**. Con una empresa en UTC-6, a
partir de las 18:00 locales el filtro preguntaba por MAÑANA y las asignaciones de la jornada en
curso están bajo HOY.

Medido en vivo con el servidor en `2026-08-02 01:41 UTC` (19:41 local):

| | |
|---|---|
| sin `?date` | **0 filas** |
| con `?date=2026-08-01` (el día del tenant) | **3 filas** |

**A quién le duele**: el dial llena con esto el **checklist de apertura**
(`RelojVisual::fetchOpeningAssignments`). En esa franja el modal sale vacío y, como el "¿ya está
todo hecho?" exige `length > 0`, el checklist tampoco llega a marcarse completo. Una tienda de
horario vespertino abre justo dentro de ese hueco.

El mismo patrón vivía en **cuatro** sitios, dos de lectura y dos de **escritura** —que es peor,
porque graban el dato mal:

| Sitio | Qué hacía |
|---|---|
| `TaskAssignmentController::index` | filtraba el listado por el día del servidor |
| `DashboardMonitorController` (monitor) | el tablero se adelantaba de día a las 18:00 locales |
| `TaskSyncController` | una asignación sin fecha nacía fechada **mañana** |
| `DashboardMonitorController::createTask` | una tarea creada a las 19:00 nacía fechada **mañana** |

Es la MISMA familia ya cerrada en A5/M5 (corte por tenant en el flag nocturno y el reagendado) y
en H10 (el dial filtraba con la fecha del dispositivo). Había quedado viva en estos cuatro.

Cubierto por `TaskAssignmentsDiaDelTenantTest` (4 casos, con el reloj congelado a 01:41 UTC —la
hora exacta del fallo— y un control a mediodía).

### De paso: 4 tests que ya eran inestables

Al alinear la convención saltaron 5 tests. Se comprobó revirtiendo los cambios que **4 de ellos
fallaban igual sin tocar nada**: sembraban la fecha con `now()` en UTC y sólo pasaban fuera de la
franja 00:00–06:00 UTC. Eran flakes preexistentes que en un CI nocturno habrían fallado de forma
aparentemente aleatoria. Ahora siembran con la zona del tenant, como hace producción.

## Observación de producto, sin corregir

El wizard de giro (`configureNicho`) crea los puestos y las **96 tareas** del catálogo, pero
**ninguna rutina** — de hecho no había una sola rutina en toda la base de datos, en ninguna de las
tres empresas. Sin rutinas nada se asigna solo: hay que crearlas a mano desde el panel de Tareas
(la pantalla existe y funciona, se probó).

No se tocó porque es una decisión de producto: puede ser deliberado que cada empresa arme sus
propias rutinas. Pero conviene saber que **una empresa recién configurada no tiene automatización
ninguna** hasta que alguien la cree, y el módulo se anuncia como "Automatiza Rutinas".

---

## 🔴 H26 — La firma del supervisor NO se exigía nunca — ✅ CORREGIDO

**Encontrado al probar la validación jerárquica en vivo (2026-08-01).**

Una tarea en modo `forced` ("requiere firma del supervisor") se completaba y **pagaba de
inmediato**, sin pasar por `awaiting_validation`.

### Causa

`TaskValidationPolicy::requiresValidation` resolvía el puesto del colaborador con
`JobRole::find($id)` — **sin** `withoutGlobalScopes()`. `JobRole` lleva `TenantScope`, y ese
`find` devolvía `null`. Con el puesto invisible, la política concluía que el colaborador "no
reporta a nadie" y devolvía `false`: sin supervisor a quien pedirle la firma, no se exige nada.

Aislado en el servidor, con sesión autenticada y todo lo demás correcto:

```
JobRole::find(4)      : NULL          ← aquí muere
find(4) sin scopes    : 'Asesor de Ventas'
feature supervisor    : true
tarea 7001            : forced
requiresValidation    : false
```

El resto del módulo ya usa el patrón contrario **a propósito** —"lectura directa con filtro
explícito de tenant, no depende del scope"—; aquí se había quedado la versión frágil.

### Alcance

La validación jerárquica es una función de control **y de plan de pago**, y estaba inerte para
todo el mundo: ninguna tarea la exigía, sin importar su `validation_mode`. Con ella muerta, el
pago de una tarea ocurre sin que nadie firme.

### Verificado en vivo, antes y después

Misma tarea, mismo colaborador:

```
val-adan-2 | completed            | 3.00   ← antes: pagó sin firma
val-adan-3 | awaiting_validation  | 0.00   ← después: espera al supervisor
```

Cubierto por `ValidacionSupervisorSeExigeTest` (4 casos), incluido uno que fija que **la respuesta
no puede depender del contexto de ejecución**: la política se consulta también desde comandos y
jobs en cola, donde no hay sesión.

⚠️ **Nota sobre los tests**: en el entorno de pruebas el `TenantScope` está desactivado, así que
este defecto **no era reproducible con la suite** — igual que H17/H19 con sqlite. Se aisló
ejecutando la política contra la base real.

### Lo que este hallazgo destapó de paso

El wizard de giro no construye el organigrama: los **7 puestos que crea quedan sin
`reports_to_role_id`**, mientras los 4 sembrados al dar de alta la empresa sí lo tienen. Aunque el
lookup ya funciona, un colaborador con puesto del giro sigue sin supervisor y por tanto sin
validación. Es coherente con lo ya anotado sobre las rutinas: **el wizard deja la configuración a
medias**, y dos funciones dependen de lo que no crea. Queda como decisión de producto.

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
