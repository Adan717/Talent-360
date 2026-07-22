# Auditoría General — Módulo de Tareas (integración con usuarios, Reloj Checador y Dial)

**Fecha:** 2026-07-22
**Alcance:** `useTaskStore.ts`, `TaskRunner.tsx`, `PanelTareasRutinas.tsx` (frontend) + `TaskSyncController.php`, `TaskAssignmentController.php`, `TaskValidationController.php`, modelos `Task`/`TaskAssignment` (backend, revisado en solo lectura para diagnóstico — no se editó nada de `Backend/app/**`).

Esta auditoría complementa (no repite) `docs/AUDITORIA_RELOJ_TAREAS_2026-07-22.md`. Aquí se profundiza en: la relación con usuarios/roles, los formularios y modales, el flujo de "Omitir", el manual paso a paso, y una calificación general 1-10.

---

## 1. Calificación general (1-10)

| Dimensión | Nota | Justificación breve |
|---|---|---|
| **Seguridad** | 6/10 | El endpoint nuevo por-fila (`TaskAssignmentController`) y el de validación (`TaskValidationController`) están bien resueltos (anti-autovalidación, jerarquía real, anti doble-pago). El endpoint viejo (`TaskSyncController::sync()`) sigue sin control de rol para crear/editar catálogo (§31, ya especificado, pendiente de aplicar). |
| **Frontend** | 7/10 | Buena separación de responsabilidades (store vs. UI), buen manejo de estados de carga/vacío. Puntos débiles: progreso del manual paso a paso no persiste, formulario de creación de tarea es un solo scroll muy largo, IDs de rol hardcodeados en 2 lugares. |
| **Backend** | 7/10 | Lógica de negocio (validación dinámica, costo financiero, monedero) correcta y ahora duplicada de forma consistente en 2 controladores. Le falta el control de rol de §31 y el null-safe de §32. |
| **Funcionamiento/lógica** | 8/10 | Los enganches con el Reloj Checador (check-in dispara rutinas, check-out bloquea y hace spill-over, Ley Silla ofrece tareas sentadas) están bien pensados y confirmé que funcionan en las 3 rutas de fichaje. |
| **Hallazgos (cantidad y severidad)** | — | 1 de seguridad real (§31, ya especificado), 1 bug de datos (§32, ya especificado), 3 nuevos en esta pasada (ver sección 3-5) — ninguno crítico, todos de UX/robustez. |
| **Estructura del módulo** | 6/10 | Bien dividido en 3 piezas (store, panel admin, runner del empleado), pero el formulario admin es monolítico y hay una arquitectura de sincronización "todo o nada" que ya se está migrando (§33). |
| **Peso/desempeño** | 6/10 | Antes de hoy, cada clic operativo reenviaba TODO el catálogo — ya corregido (ver §31 update). Con `PUT /task-assignments/{id}` ya listo en el backend, migrar el frontend elimina el resto del sobrepeso. |
| **Viabilidad multi-tenant** | 6/10 | El diseño en sí es multi-tenant (todo scoped por `tenant_id`), pero encontré 2 lugares con IDs de rol hardcodeados (`handleSpillOver`, `getRoleIdFromRoleName`) que solo tienen sentido para el tenant con el que se construyó — para otro tenant esas reglas aplican a roles arbitrarios o a ninguno. |

**Promedio global: ~6.6/10** — el módulo funciona y la lógica de negocio es sólida, pero necesita esta ronda de ajustes de seguridad, estructura de formularios y generalización multi-tenant antes de escalarlo a muchas empresas con confianza total.

---

## 2. Mapa de integración con usuarios y con el Reloj Checador/Dial (confirmado en código)

- **Alta de tareas por rol:** cada `Task` tiene `targetType`/`targetId` (rol, usuario, bolsa, departamento). Al fichar entrada, `triggerCheckInRoutines()` busca rutinas `on_checkin` que coincidan con el `job_role_id` del usuario y genera asignaciones nuevas con ID único (`chk_<user>_<rutina>_<tarea>_<timestamp>`) — confirmé que esto es lo que garantiza que la tarea de hoy y la de mañana queden como registros independientes (tu pregunta de la vez pasada).
- **Check-out bloqueado por tareas pendientes:** `handleClockOutRequest()` en `useClockEngine.tsx` consulta `useTaskStore` y bloquea la salida si hay `pending`/`in_progress` sin completar — el override lo hace un supervisor con PIN/QR, y ESE camino sí queda registrado en `/clock/uncompleted-tasks-log` (auditable).
- **Ley Silla ↔ Tareas sentadas:** al iniciar el descanso, se ofrecen tareas con `canBeDoneSitting: true`. Encontré que el botón "Monitoreo de seguridad" usa un `taskId: 9999` sin tarea real de respaldo (§32, ya especificado).
- **Spill-over al fichar salida:** las tareas de un usuario que se va se liberan a la Bolsa de Trabajo, salvo que su rol esté en una lista fija de IDs (`[1,2,3,4]`) considerados "jefes" — no genérico por tenant (hallazgo ya en la auditoría anterior, reforzado aquí porque encontré el mismo patrón en `getRoleIdFromRoleName`/`getRoleNameFromRoleId` de `PanelTareasRutinas.tsx`, con IDs de respaldo como "5 = Cajera", "6 = Ayudante").

---

## 3. 🔴 Hallazgo — "Omitir Tarea" no notifica a nadie ni pide confirmación

Verificado en código: cualquier empleado, para cualquier tarea propia que **no sea bloqueante**, puede tocar "Omitir esta Tarea" en el modal de detalle (`TaskRunner.tsx` línea ~1458) y el estado cambia a `omitted` de inmediato — sin modal de confirmación, sin pedir motivo, y sin ninguna notificación real. La única "traza" es un evento en el timeline de la Matrix (`addMatrixEvent`), que es una función que **solo existe para el Simulador Matrix** — en producción real esa llamada no le llega a nadie, ni push, ni badge, ni correo. La única forma en que un supervisor se entera es si abre la lista de tareas y nota la tarjeta en rosa con el badge "Omitida" (sí es visible ahí, solo que no es proactivo).

Esto contrasta con el otro camino de "omitir" que sí existe en el código: cuando un supervisor autoriza la salida con tareas pendientes vía PIN/QR, ESO sí queda registrado en `/clock/uncompleted-tasks-log` — es decir, el patrón de "avisar/registrar" ya existe en el proyecto para un caso análogo, solo que no se usó también para el botón de Omitir individual.

**Tu idea coincide con lo que yo hubiera sugerido.** Antes de programar nada, aquí van las opciones concretas (necesito que elijas o mezcles):

- **Confirmación:** ¿un modal simple tipo "¿Seguro que quieres omitir esta tarea?" con un campo opcional de motivo, o prefieres que el motivo sea obligatorio (como ya es obligatorio el motivo al *rechazar* una tarea en validación)?
- **Notificación al supervisor:** ¿push real (ya existe infraestructura Firebase/FCM en el proyecto, usada para el aviso de puerta §26), un badge/contador en el Centro de Mando, o ambos?
- **¿Aplica a todas las tareas o solo a las que tengan `priority: 'bloqueante'` visibles a un supervisor?** (recuerda que hoy las bloqueantes ni siquiera muestran el botón de Omitir — eso ya está bien).

---

## 4. 🟡 Hallazgo — El manual paso a paso YA hace lo que sugeriste, pero tiene 2 bugs reales

Antes de proponer nada nuevo, verifiqué el componente del stepper (`TaskRunner.tsx` líneas 1134-1188): **ya está implementado exactamente como lo describiste** — solo se muestra el detalle del paso actual, los pasos futuros aparecen bloqueados/atenuados (sin su instrucción visible) y los completados se colapsan con un check. No hace falta programar el "ir revelando paso por paso", ya existe.

Lo que sí encontré, verificando el código con cuidado:

- **Bug 1 — el progreso no se guarda en ningún lado.** `completedSteps`/`activeStepIndex` viven solo en memoria del componente y `handleSelectAssignment()` los resetea a cero **cada vez que se abre O se cierra el modal** (línea 286-294) — hasta para la misma tarea. Si el empleado cierra el detalle a medias (por accidente, o porque lo interrumpieron) y lo vuelve a abrir, pierde su progreso marcado y tiene que reiniciar el checklist visual desde el paso 1, aunque la tarea siga "en curso" de verdad.
- **Bug 2 — completar los pasos es solo decorativo.** El botón "Completar" (o "Enviar Evidencia y Completar") no valida en ningún momento que `activeStepIndex` haya llegado al final del manual — un empleado puede completar la tarea sin haber marcado ni un solo paso del SOP.

**Antes de programar, dime qué prefieres:**
- ¿Exigir que se marquen todos los pasos con `verification_required: true` antes de habilitar "Completar"? (los que no lo requieren podrían saltarse)
- ¿Guardar el progreso para que sobreviva cerrar/abrir el modal? Puedo hacerlo con una clave por `assignment.id` en memoria del store (rápido, se pierde si cierras la app) o persistirlo de verdad contra el backend (requeriría un campo nuevo, coordinar con Claude Code).

---

## 5. 🟡 Hallazgo — el formulario de creación/edición de tareas es un solo formulario largo

`PanelTareasRutinas.tsx` (el "Constructor de Operaciones"): todo — título, categoría, objetivo, puesto ejecutor, frecuencia, tiempo estimado, evidencia, autocaptura, Ley Silla, prioridad, modo de supervisión, mini-asistente y pasos del SOP — vive en un solo modal de scroll continuo (`max-h-[90vh] overflow-y-auto`, ~10 secciones). Funciona, pero es el tipo de formulario que se siente pesado la primera vez que lo usas.

**Propuesta concreta (sin programarla todavía):** dividirlo en pasos cortos dentro del mismo modal (no páginas nuevas, solo secciones con "Siguiente"/"Atrás"):
1. **Lo esencial:** título, categoría (ya autodetectada), puesto ejecutor, tiempo estimado, prioridad.
2. **Cómo se valida:** evidencia, mini-asistente, modo de supervisión.
3. **Manual de ejecución (SOP):** los pasos — aquí es donde de verdad se agradece no ver todo el formulario junto, porque agregar pasos ya es una lista que crece.
4. **Confirmación:** vista previa final (ya existe una mini vista previa, la ampliaría) y botón de guardar.

Esto resuelve directamente tu pedido de "específico y detallado pero que no ocupe toda la pantalla, bien estructurado" sin quitarle ningún campo — solo los organiza en pasos.

---

## 6. Buenas noticias encontradas en esta pasada (no las esperaba)

- **§33 (arquitectura) ya tiene su prerequisito de backend implementado.** Al revisar `TaskAssignmentController::update()` para este audit, encontré que Claude Code **ya portó** ahí la lógica completa de recálculo de validación/costo/puntos/monedero que antes solo vivía en `TaskSyncController::sync()` — incluido el null-safe (`$task?->points`) que pedí para el §32. Esto significa que la migración del frontend (que las acciones operativas de `useTaskStore.ts` llamen a `PUT /task-assignments/{id}` en vez de reenviar todo) **ya se puede hacer con seguridad** — el bloqueador que yo mismo había señalado ya no existe. Puedo empezar esa migración en cuanto me confirmes que quieres que avance.
- **La validación de supervisor no es falsificable desde el cliente** (ya confirmado en la auditoría anterior, lo reverifiqué aquí en el nuevo controlador: se recalcula todo del lado servidor).
- **Anti doble-pago funciona correctamente** en los 2 controladores que tocan `points_awarded`/`coins_awarded`.

---

## 7. Resumen de decisiones que necesito antes de programar

1. Omitir tarea → ¿confirmación con motivo opcional u obligatorio? ¿notificar por push, badge, o ambos?
2. Manual paso a paso → ¿exigir completarlo antes de poder completar la tarea? ¿persistir el progreso, y con qué nivel (memoria vs. backend)?
3. Formulario de creación de tareas → ¿lo divido en los 4 pasos propuestos?
4. §33 → ¿procedo ya con la migración del frontend a `PUT /task-assignments/{id}`, ahora que el backend está listo?
5. IDs de rol hardcodeados (`handleSpillOver`, `getRoleIdFromRoleName`) → ¿los generalizamos ahora usando `reports_to_role_id`/`is_leadership_role` (mismo patrón que ya se usó en otra parte del proyecto) o lo dejamos para después?
