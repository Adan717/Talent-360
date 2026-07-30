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
