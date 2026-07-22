# Validación de Viabilidad — `Logica Dial.md` vs. Implementación Actual

**Fecha:** 2026-07-21
**Autor:** Sesión Frontend (Cowork)
**Alcance:** Verifica, estado por estado, si la lógica descrita en `docs/Logica Dial.md` es implementable sobre el Reloj Checador actual de Talent360, qué ya existe, qué falta, qué variables hay que exponer en la configuración del módulo y qué toca del lado de Backend.

---

## Veredicto general

**Sí es viable en su totalidad, y la mayor parte ya está construida.** El documento no describe un rediseño: describe el mismo motor de 23 estados que ya vive en `useClockEngine.tsx` (función `getButtonProps`), pero con reglas más finas y **cinco piezas nuevas** que hoy no existen o existen a medias. Ninguna de esas cinco piezas exige tocar la arquitectura del dial — todas encajan como una capa más sobre lo que ya hay.

El riesgo real no es técnico, es de **coherencia de configuración**: el documento fija horarios rígidos (07:00–18:00 tienda, 08:00–08:40 apertura, 10:30–13:30 comida, etc.) que hoy en el código están unos como constantes hardcodeadas y otros derivados del horario de cada empleado. Antes de "congelar" estos horarios hay que decidir si son **globales de sucursal** (como dice el documento) o **por empleado/puesto** (como está hoy). Esa decisión afecta a casi todos los estados.

---

## Qué ya está implementado y coincide con el documento

Estos estados ya funcionan hoy y su lógica calca lo que pide el documento — no requieren trabajo nuevo, solo verificación visual:

- **Estados 1, 2, 3** (Fichaje Bloqueado por 3 retardos, Día Feriado, Día Descanso) con sus botones secundarios `Ir a la Academia` y `Laborar Horas Extras`.
- **Estado 4** (Reportar Falta/Retardo con ETA) — `isIncidenceReport`, con ventana de encargado vs. empleado común diferenciada.
- **Estado 5** (Llamar Suplente) — como texto principal del dial y como botón secundario `Marcar a Suplente`.
- **Estado 6** (En Camino) — geofencing progresivo con `isApproachingStore()`.
- **Estado 8** (Abrir Tienda) con auto check-in en cascada de quienes marcaron "Ya llegué".
- **Estado 9** (Apertura de Emergencia con co-validación de 2 testigos por PIN).
- **Estado 10/15** (Declarar Evento Offline / Contingencia Activa) con firma HMAC offline y cola de sincronización.
- **Estado 11** (Esperando Apertura), **12** (Fichar Entrada), **13** (Acceso Bloqueado con desbloqueo QR/PIN de supervisor), **14** (Fichar Reingreso).
- **Estado 21** (Entregar Turno / Delegar Llaves) — ahora gateado por `keys_control`.
- **Estado 22** (Fichar Salida con Checklist de Cierre Seguro de 3 puntos).
- **Estado 23** (Fin Jornada / Evaluar Clima) con `Evaluacion360.tsx`.
- **Botón de Pánico** y **Salida Anticipada** como botones secundarios.

También ya existe la infraestructura de configuración: `CompanySettingsPanel.tsx` guarda `clockOpConfig` con `enabledDialerFeatures` (switches por estado), `arrivalWindowMins`, `suplente_travel_time_mins`, etc.; y `shiftConfigs` guarda `start`/`end`/`mealMinutes`/`tolerance` por empleado. Es decir, **ya hay un lugar donde colgar las variables nuevas** — no hay que inventar un panel desde cero.

---

## Los 5 hallazgos: lo que el documento pide y hoy NO existe (o existe a medias)

### Hallazgo 1 — Pase de Lista con calificación por estrellas (Estado 8) ⚠️ Parcial

**Documento:** tras abrir la tienda, el encargado califica uno a uno a los presentes con estrellas (1–5) en tres ejes: *Presentación (Uniforme)*, *Imagen (Aseo)* y *Energía (Actitud)*.

**Hoy:** `handleSubmitPaseLista()` existe pero **solo registra `check_in`** de quienes marca como "presente". No hay estrellas, no hay ejes, no se guarda ninguna calificación.

**Para que funcione:**
- Frontend: agregar al modal de pase de lista tres controles de estrellas por empleado.
- Backend: nueva tabla `pase_lista_ratings` (o columnas en el registro de asistencia) para persistir los 3 valores + fecha + encargado que calificó. **Requiere spec para Claude Code.**
- Config: switch para activar/desactivar la calificación (algunas sucursales quizá solo quieran el check_in sin calificar).

**Viabilidad:** Alta. Es UI + un endpoint nuevo.

---

### Hallazgo 2 — "Enviar Mensaje" push al encargado para empleados comunes (Estados 7 y 11) ⚠️ Falta

**Documento:** el empleado común sin llaves **no puede llamar**; su botón secundario es `💬 Enviar Mensaje`, que manda una notificación push al encargado en camino ("Sofía López está esperando en puerta") y registra la presencia.

**Hoy:** el botón secundario de llaves (`📞 Llamar Encargado`) se filtra correctamente por `isUserKeyholder`, pero **no existe la variante `Enviar Mensaje`** para el empleado común — simplemente no ve botón de contacto.

**Para que funcione:**
- Frontend: botón secundario `Enviar Mensaje` visible cuando `!isUserKeyholder` y la tienda está cerrada.
- Backend: endpoint que reciba el aviso y dispare la notificación push al encargado responsable + registre el reporte de presencia. **Requiere spec para Claude Code** (y depende de que exista infraestructura de push real, no solo las notificaciones locales que hoy usa el reloj).

**Viabilidad:** Media — depende de si hay push server-side. Si no lo hay, se puede degradar a un registro en la Matrix + aviso in-app al encargado cuando abra la app.

---

### Hallazgo 3 — "Apartar Turno" como cola secuencial (Estado 16b) ⚠️ Diferente a lo actual

**Documento:** a las 10:10 AM se activa **Apartar Turno**, con una **cola de selección uno-a-uno**: el orden lo define quién llegó primero (o aleatorio, según configuración); cuando el primero elige su slot, se **habilita el dialer del siguiente** de la fila.

**Hoy:** `MealReservation.tsx` es **selección libre de slots** — cualquiera reserva cualquier slot disponible mientras haya lugar (con bloqueo por mismo puesto). No hay cola, no hay orden por llegada, no hay "se habilita al siguiente".

**Para que funcione:**
- Frontend: nueva máquina de turnos que respete el orden y bloquee el dialer hasta que sea "tu turno de elegir".
- Backend: el endpoint `/meal-reservations/slots` necesitaría exponer el **orden de la cola** y a quién le toca elegir; probablemente un estado de "ronda de selección" por día/sucursal. **Requiere spec para Claude Code.**
- Config: modo de ordenamiento (`por_llegada` | `aleatorio`), minuto de disparo (default 10:10), ventana de comida.

**Viabilidad:** Media-alta. Es el cambio de mayor esfuerzo de los cinco porque reemplaza un flujo existente por otro con estado compartido entre empleados. Conviene decidir si se reemplaza o si "cola" es un modo opcional que convive con "selección libre".

---

### Hallazgo 4 — Evidencia fotográfica del comedor (Estados 17 y 18b) ⚠️ Falta

**Documento:** al **iniciar** comida exige subir una foto del comedor limpio; al **terminar**, otra foto del comedor limpio. Sin la foto no avanza el estado.

**Hoy:** no existe ninguna captura ni validación de foto en el flujo de comida.

**Para que funcione:**
- Frontend: captura de cámara (la PWA ya tiene permisos de cámara para reconocimiento facial, así que el acceso es reutilizable) + preview antes de confirmar.
- Backend: almacenamiento de las imágenes (2 por evento de comida) ligadas al registro. **Requiere spec para Claude Code** (definir storage: ¿S3/disco?, retención, tamaño).
- Config: switch `require_meal_photo_evidence` on/off por sucursal.

**Viabilidad:** Alta técnicamente, pero **ojo con el peso**: dos fotos por empleado por día es almacenamiento real que crece rápido. Recomiendo que sea opcional y con compresión en cliente.

---

### Hallazgo 5 — Ley Silla: aprobación de supervisor + Tareas Sentadas + control de aforo (Estado 19) ⚠️ Parcial

**Documento:** al acumular 120 min de pie, el dial pasa a **Solicitar Silla**; el supervisor aprueba (PIN/QR presencial o un clic remoto); recién ahí se desbloquea el descanso de 15 min con un panel de **Tareas Sentadas** (LMS, ATS, auditoría de inventario, checklist). Además: **control de aforo** con `sillas_maximas_simultaneas` — si se llena, encola y avisa el turno de espera.

**Hoy:** existe `startBreakWithSittingTask(taskId)` y la detección de los 120 min (`consecutiveMinutes` en `leySillaConfig`), y el supervisor recibe alerta. Pero **el flujo de aprobación formal previa** (bloquear hasta que el supervisor apruebe) y sobre todo el **control de aforo/cola de sillas** no están implementados.

**Para que funcione:**
- Frontend: estado intermedio "Solicitar Silla" que espera aprobación antes de habilitar "Descanso"; panel de tareas sentadas (ya hay semilla); indicador de "esperando lugar" cuando el aforo está lleno.
- Backend: log de aprobaciones con firma del supervisor (compliance LFT) y contador de sillas activas por sucursal en tiempo real. **Requiere spec para Claude Code.**
- Config: `sillas_maximas_simultaneas` (nuevo), `consecutiveMinutes` (ya existe), `breakMinutes` (ya existe), modo de aprobación (`presencial_pin` | `remoto_clic` | `automatico`).

**Viabilidad:** Media. La parte de aprobación es directa; el control de aforo en tiempo real necesita coordinación (idealmente por el mismo WebSocket `tenant.{id}.clock` que ya migramos, para que el contador de sillas sea live).

---

## Variables que hay que exponer en la configuración del módulo Reloj Checador

El documento asume varias constantes que hoy están **hardcodeadas o derivadas**, no configurables. Para que la lógica sea "configurable desde el módulo" como pides, estas deben vivir en `clockOpConfig` (panel `CompanySettingsPanel.tsx`):

| Variable | Valor en el documento | Estado hoy | Acción |
|---|---|---|---|
| Horario global de tienda (apertura/cierre) | 07:00–18:00 | Hardcode `420` y derivados; no hay bloqueo global fuera de rango | Exponer `storeOpenMins` / `storeCloseMins` + aplicar bloqueo global |
| Ventana de apertura (uso de llaves) | 08:00–08:40 | Derivada de `shiftStart` | Exponer `openingWindowStart` / `openingWindowEnd` |
| Tolerancia de apertura | 08:45 | `maxLateMinsAllowed` (existe) | Reutilizar el existente |
| Ventana de comida | 10:30–13:30 | Parcial (slots) | Exponer `mealWindowStart` / `mealWindowEnd` |
| Duración de comida | 45 min | `mealMinutes` por empleado (existe) | Decidir: ¿global o por empleado? |
| Disparo "Apartar Turno" | 10:10 (20 min antes) | No existe | Exponer `mealQueueTriggerOffset` |
| Orden de la cola de comida | por llegada / aleatorio | No existe | Exponer `mealQueueOrder` |
| Minutos de pie para Ley Silla | 120 | `consecutiveMinutes` (existe) | Reutilizar |
| Duración descanso Ley Silla | 15 min | `breakMinutes` (existe) | Reutilizar |
| **Aforo máximo de sillas** | `sillas_maximas_simultaneas` | **No existe** | **Crear** |
| Foto de comedor obligatoria | sí | No existe | Crear switch `require_meal_photo_evidence` |
| Calificación en pase de lista | sí | No existe | Crear switch `require_pase_lista_rating` |
| Modo de aprobación de silla | presencial/remoto/auto | No existe | Crear `sillaApprovalMode` |

**Decisión de fondo — YA TOMADA (2026-07-21):** ver la sección siguiente. Se resolvió con jerarquía global→empleado y un modo de operación de sucursal con opción de 24 horas.

---

## ✅ Decisiones tomadas (para cuando se programe — NO implementar aún)

> Estas decisiones están **acentuadas como pendientes de programación**. No hay que codificarlas todavía. Quedan aquí registradas para retomarlas cuando se construya el módulo de configuración del Reloj Checador, el **Wizard de Onboarding** y la **Landing Page** (registro + compra de licencia).

### 1. Jerarquía: global de sucursal → empleado (se componen, no compiten)

Hay dos tipos de tiempo, ortogonales:

- **Horarios globales = envase de la sucursal.** Definen *cuándo es posible* cada evento a nivel tienda: apertura/cierre físico, ventana de apertura con llaves, ventana en que se *puede* comer, ventana de cierre. Propiedad del **lugar**.
- **Horario del empleado = contenido.** El turno individual: entrada/salida, tolerancia, duración de comida. Propiedad de la **persona**.

Regla: el horario global gobierna el reloj y los **eventos de tienda**; el turno individual gobierna las **obligaciones personales** de cada quien (tolerancia, retardo, su comida, su salida). El global es el **default/envolvente**; el empleado es el **override dentro del envolvente**. Un empleado de turno corto (ej. 4 h) simplemente no cruza las ventanas globales que caen fuera de su turno — se resuelve solo, sin reglas especiales.

### 2. Modo de operación de sucursal (interruptor maestro): `storeOperationMode`

Cada sucursal se configura en uno de dos modos:

- **`fixed` (horario fijo):** la tienda tiene apertura y cierre definidos. Los horarios globales gobiernan todo (apertura con llaves, ventana de comida, cierre). El reloj solo funciona dentro del envase. *(Es el caso de uso actual.)*
- **`24h` (continuo):** no hay candado global; el reloj funciona todo el día y la tienda nunca "cierra". En este modo:
  - Se **desactiva el bloqueo global de horario** (resuelve el edge case del "candado absoluto" — un turno válido nunca queda bloqueado).
  - Los **horarios de comida y eventos personales caen al horario de cada colaborador** (config individual), no a una ventana global.
  - Los **eventos de tienda con llaves** (apertura con 2 testigos, pase de lista, checklist de cierre) se **desactivan por defecto** (una tienda que no cierra no tiene "apertura del día"), pero se deja un **sub-switch** (`keyEventsInContinuousMode`) por si un cliente 24h rota encargados por turno y quiere conservar la entrega de llaves.

### 3. Granularidad: a nivel sucursal (default a nivel tenant)

Como Talent360 es multi-sucursal y el flujo de apertura ya es por sucursal, este modo debe vivir **a nivel sucursal**, con default heredado del tenant. Así una empresa puede tener una tienda de plaza comercial en `fixed` y una tienda `24h` al mismo tiempo. Si al programar solo se maneja config por empresa, arrancar a nivel tenant es aceptable y luego se refina.

### 4. Dónde se captura: el Wizard de Onboarding (YA EXISTE — no es módulo por construir)

Flujo real: Landing Page → el usuario elige y compra su versión → se verifica el pago → se le mandan sus accesos → entra a la plataforma → **le aparece el Wizard de primera configuración de su empresa** (`OnboardingWizard.tsx`). Ahí es donde debe capturarse la información de operación de la tienda.

**Lo que el Wizard YA hace hoy (verificado en código 2026-07-21):** su estructura ya calca el modelo de dos capas de la decisión #1, sin haberse planeado así:
- **Paso 1 (Sucursal):** ya pregunta **Hora de Apertura** y **Hora de Cierre** de la tienda. Es la capa **global de sucursal**. Se guarda como `updateSetting('storeSchedule', { openTime, closeTime })`.
- **Paso 2 (Puestos):** ya captura `shiftStart` / `shiftEnd` / `tolerance` por puesto. Es la capa **por empleado/puesto**.

**Lo que falta agregar al Wizard cuando se programe (NO ahora):**
- En el Paso 1: el toggle **`storeOperationMode`** (`fixed` / `24h`). Si `fixed`, mostrar además las **ventanas de comida globales** (opcional). Si `24h`, ocultar apertura/cierre y avisar que la comida se configura por colaborador. Y el sub-switch **`keyEventsInContinuousMode`**.

**⚠️ Desajuste a corregir al programar (matizado 2026-07-21):** el Wizard guarda las horas en la llave `storeSchedule`. El **flujo de apertura sí la lee** (`useStoreOpening.ts` línea 101: `systemSettings.storeSchedule?.openTime`). Pero las **ventanas del dial** en `getButtonProps` (`useClockEngine.tsx`) NO — usan el `420` (07:00) y deadlines derivados del `shiftStart`, hardcodeados. Es decir: la apertura ya respeta la hora del Wizard, pero el resto de ventanas del dial no. Al implementar la Fase 1 hay que **cablear las ventanas del dial a `storeSchedule`/`clockOpConfig`** (o unificar ambas llaves), o el reloj seguirá usando horarios hardcodeados para todo lo que no sea la apertura.

**Pendiente de programación asociado:** agregar al Paso 1 del Wizard los campos `storeOperationMode`, `keyEventsInContinuousMode` y las ventanas globales de la tabla de variables; y cablear la captura del Wizard hacia `clockOpConfig` para que el reloj arranque con el comportamiento correcto desde el primer día.

---

## Detalles menores a alinear (no bloqueantes)

- **Estado 16 — cronómetro en el dial:** el documento pide que el centro del dial muestre un cronómetro vivo `HH:MM:SS` de tiempo trabajado. Hoy se calcula `elapsedMins` y se muestran "horas trabajadas", pero no como contador que corre segundo a segundo en el centro del dial. Es un cambio puramente visual.
- **Estado 18 — congelar acumulador durante comida:** el documento lo exige explícitamente. Hoy hay detección de comida excedida, pero conviene confirmar que el acumulador de jornada efectivamente **se pausa** y no cuenta la comida como trabajada.
- **Marcas `silla_start` / `silla_end`:** el documento nombra así las marcas de Ley Silla; el código usa `break_start` / `break_end`. No es un problema, pero conviene unificar el vocabulario para que los reportes de nómina distingan una silla de un descanso normal.
- **Estados 4/6/7 con horarios distintos entre encargado y empleado común:** el documento da rangos ligeramente distintos por rol (p. ej. "En Camino" 07:45–08:00 encargado vs. 08:00–08:15 común). El código ya diferencia encargado/común, pero conviene revisar que los cortes coincidan una vez que los horarios sean configurables.

---

## Plan de implementación sugerido (por fases)

**Fase 0 — Decisión de producto (tú):** ¿horarios globales de sucursal o por empleado? ¿"cola de comida" reemplaza la selección libre o convive como modo opcional? Sin esto, las fases siguientes se pueden construir sobre un supuesto equivocado.

**Fase 1 — Solo configuración (bajo riesgo, sin backend):** mover a `clockOpConfig` las variables que hoy están hardcodeadas (horarios de tienda, ventana de apertura/comida, disparadores) y aplicarlas en `getButtonProps`. Añadir los switches nuevos aunque todavía no hagan nada. Esto ya te da control sin cambiar comportamiento.

**Fase 2 — Piezas frontend puras:** cronómetro vivo del estado 16, botón `Enviar Mensaje` (degradado a registro in-app si aún no hay push), estado intermedio "Solicitar Silla".

**Fase 3 — Piezas con backend (specs para Claude Code, una por una):** calificación de pase de lista, evidencia fotográfica de comida, cola secuencial de comida, aprobación + aforo de sillas, push real de "Enviar Mensaje". Cada una entra al contrato `docs/BACKEND_INTERFACES.md` antes de tocar código de backend.

**Fase 4 — Verificación:** `tsc --noEmit` en cada paso + prueba visual en el Simulador Matrix cambiando de rol (encargado vs. común) y avanzando el reloj por las ventanas horarias.

---

## Revisión final (2026-07-21) — hallazgos nuevos del modo dual

Tras cerrar la decisión de horarios globales + modo dual, quedan dos hallazgos estructurales nuevos (que emergen precisamente del modo `24h`) y dos decisiones de producto aún abiertas. Ninguno bloquea, pero deben diseñarse desde el inicio, no parcharse:

1. **La matriz de 23 estados NO es monolítica bajo el modo dual.** En modo `24h`, la coreografía de apertura se vuelve inaplicable: los estados **4, 5, 7, 8, 9, 11 y 21 (entrega de llaves)** dependen de que la tienda "abra el día". Una tienda que nunca cierra no tiene apertura → esos ~7 estados quedan dormidos. "Programar los 23 eventos" significa entonces: **matriz completa para `fixed`, matriz reducida para `24h`**. Diseñar la máquina de estados consciente del modo desde el arranque.

2. **`storeStatus` en modo 24h.** Casi todo el dial se decide con `storeStatus === 'open' / 'closed'`. En 24h no hay cierre → **`storeStatus` debe forzarse a `'open'` permanente** cuando el modo es `24h`, o una tienda 24h mostraría "Esperando Apertura" indefinidamente. Cable pequeño pero obligatorio.

**Decisiones de producto todavía abiertas (gatean 2 de los 5 pendientes):**
- **Cola de comida (Hallazgo 3):** ¿reemplaza la selección libre de slots actual o convive como modo opcional? Sin decidir.
- **"Enviar Mensaje" push (Hallazgo 2):** ¿existe infraestructura de push server-side? Si no, degradar a aviso in-app al encargado + registro en Matrix.

Con esos dos puntos de diseño resueltos (matriz-por-modo + forzado de `storeStatus`), no queda ningún otro detalle que se escape para programar todo el dial en el orden por fases de arriba.

---

## Resumen en una línea

Todo el documento es implementable; **18 de los 23 estados ya están y coinciden**; los 5 pendientes (calificación de pase de lista, mensaje push, cola de comida, foto de comedor, aprobación+aforo de sillas) son adiciones limpias, no reescrituras — pero necesitan **una decisión tuya sobre horarios globales vs. por empleado** y **cinco specs pequeñas para Backend** antes de tocar código.
