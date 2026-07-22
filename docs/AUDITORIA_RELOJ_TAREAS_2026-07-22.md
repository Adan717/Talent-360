# Auditoría — Integración Reloj Checador ↔ Módulo de Tareas (2026-07-22)

**Alcance:** cómo el Reloj Checador (`useClockEngine.tsx`, `RelojVisual.tsx`) y el módulo de Tareas (`useTaskStore.ts`, `TaskRunner.tsx`, `PanelTareasRutinas.tsx`) se conectan entre sí, más los endpoints de backend que sostienen esa integración (`TaskSyncController`, `TaskAssignmentController`, `TaskValidationController`). Objetivo: lógica, estructura, seguridad y sugerencias concretas para ambos lados.

**Veredicto general:** los puntos de enganche entre los dos módulos (check-in dispara rutinas, check-out dispara spill-over y bloquea si hay tareas pendientes, Ley Silla ofrece tareas "sentado") están bien pensados y funcionan. El problema de fondo no es la lógica de negocio sino **cómo se sincroniza el dato**: todo el módulo de Tareas usa un único endpoint que reenvía el estado completo en cada clic, y esto tiene consecuencias reales de carrera de datos, desempeño y control de acceso. Encontré y corregí un bug concreto de datos (fechas nunca llegaban al frontend), y documento aquí 1 hallazgo de seguridad real (no de "podría pasar" — lo verifiqué en el código del backend), más las sugerencias de arquitectura para resolver la causa raíz.

---

## 1. Mapa de integración (cómo se conectan hoy)

- **Check-in → rutinas automáticas:** `useClockEngine.tsx` línea 618, dentro de `updateClockState()`, al pasar de `inactive` a `active` llama a `useTaskStore.getState().triggerCheckInRoutines(userId, job_role_id, currentSimTime)`. Corre en los 3 caminos de fichaje (offline, sandbox y backend real) — confirmado leyendo las 3 ramas de `syncToDB()`, así que si funciona en sandbox, funciona en producción.
- **Check-out → spill-over:** misma función, al pasar a `inactive`/`finished` llama a `handleSpillOver(userId, job_role_id)` — las tareas pendientes del usuario se liberan a la Bolsa de Trabajo (o se transfieren si es un rol "jefe", ver Hallazgo 4).
- **Check-out bloqueado por tareas pendientes:** `handleClockOutRequest()` (línea 2728) consulta `useTaskStore.getState().assignments` y, si hay algo `pending`/`in_progress` asignado al usuario, bloquea la salida con un modal de override por PIN de supervisor (`pendingTasksBlocker`).
- **Ley Silla ↔ Tareas "sentado":** al iniciar un descanso Ley Silla, `RelojVisual.tsx` (línea 5166) ofrece las tareas con `canBeDoneSitting: true` para hacer durante el break; `startBreakWithSittingTask()` (`useClockEngine.tsx` línea 2559) crea la asignación y arranca el descanso en la misma acción.
- **Punto de entrada del panel admin:** `PanelTareasRutinas.tsx`, montado en `App.tsx` cuando `activeModule === 'operativo'` — visible según el tier del tenant, sin ningún filtro de rol de usuario (ver Hallazgo 2).

Esta integración en sí está bien resuelta — el problema está un nivel más abajo, en cómo cada acción llega al backend.

---

## 2. 🔴 Hallazgo 1 (corregido) — el frontend nunca leía `date` ni `points_awarded` de la respuesta del backend

Backend implementó `§14.1` (poblar `date` y `points_awarded` en cada `TaskAssignment`) hace unos días. Pero `useAppStore.ts`, al hidratar `data.assignments` desde `/sync/state` (líneas 644-657), nunca mapeaba esos dos campos al estado local — el objeto `TaskAssignment` de Zustand siempre tenía `date: undefined`.

Esto rompía en silencio dos cosas en `TaskRunner.tsx`:
- El filtro de **"Historial de Hoy"** (línea 407: `a.date === undefined || a.date === todayStr`) — como `date` siempre llegaba `undefined`, el filtro tomaba la rama de "tolerar registros viejos" para **absolutamente todos** los registros, mostrando el historial completo de todos los días como si fuera el de hoy.
- El conteo de **"puntos de hoy"** (línea 489), que suma sobre ese mismo listado mal filtrado — sumaba puntos de tareas completadas en cualquier día pasado, no solo hoy.

**Corregido** en `Frontend/src/store/useAppStore.ts`: se agregó `date: a.date` y `pointsAwarded: a.points_awarded` al mapeo. Verificado con `tsc --noEmit`: 0 errores. No requiere nada de backend — el dato ya estaba disponible, solo no se leía.

---

## 3. 🔴 Hallazgo 2 (seguridad, sin corregir — requiere decisión de Backend) — crear/editar Tareas y Rutinas no tiene control de rol en el servidor

`PanelTareasRutinas.tsx` (el panel de administración de tareas y rutinas) no tiene ningún filtro de rol dentro del componente — lo confirmé revisando el archivo completo (sin coincidencias de `role`, `system_role` ni checks de permisos). Es visible según el **tier** del tenant (`App.tsx` línea 728, dentro de `activeModule === 'operativo'`), no según quién inició sesión. En la práctica, un colaborador con rol `empleado` normalmente no llega a esta pantalla porque `RootRoute` (`App.tsx` línea 100) lo redirige a `/empleado` — pero esa redirección es **solo de navegación en el navegador**, no un control de acceso real.

Confirmé en el backend (`Backend/routes/api.php` línea 292 y `TaskSyncController::sync()`) que `POST /sync/tasks` — el único endpoint que usa `addTask`/`updateTask`/`addRoutine`/`updateRoutine` para crear y editar tareas y rutinas — está registrado bajo el grupo de middleware `role:empleado,employee,admin,supervisor,platform_admin`, es decir, **cualquier colaborador autenticado del tenant puede llamarlo**, y el controlador (`TaskSyncController.php`, sin ningún chequeo de `$request->user()->role`) acepta y guarda cualquier `tasks`/`routines` que le manden — solo valida que pertenezcan al `tenant_id` correcto, no quién las mandó.

Esto significa que un colaborador común, con una petición HTTP directa (no necesita ni siquiera abrir el panel), podría crear tareas, cambiar el `validation_mode` de una tarea existente (por ejemplo ponerse a sí mismo en modo `'auto'`, sin validación de supervisor) o modificar `points`/`estimated_mins` de cualquier tarea del tenant.

**Contraste con una buena práctica que sí existe en el mismo módulo:** `SillaController::approve()/reject()` (aprobar descansos Ley Silla) delega a `ClockService::approveSillaRequest()`, que **sí** valida `in_array($approver->role, ['admin','supervisor','platform_admin'])` a nivel de servicio, además de verificar que la solicitud pertenezca al tenant correcto. Ese es el patrón correcto — `TaskSyncController::sync()` simplemente no lo tiene para las porciones `tasks`/`routines` del payload (las porciones de `assignments` operativas, como completar/pausar una tarea propia, sí tienen sentido para cualquier empleado).

**Sugerencia concreta para Backend:** en `TaskSyncController::sync()`, antes de procesar `$request->input('tasks')` y `$request->input('routines')`, agregar un chequeo tipo:
```php
if ($request->has('tasks') || $request->has('routines')) {
    if (!in_array(auth()->user()->role, ['admin', 'supervisor', 'platform_admin'])) {
        return response()->json(['message' => 'No autorizado para crear o editar tareas/rutinas.'], 403);
    }
}
```
La porción de `assignments` (operar sobre tareas ya asignadas) puede seguir abierta a cualquier empleado, ya que ahí sí hay lógica de negocio legítima para que cualquiera tome/complete/pause su propia tarea.

---

## 4. 🟡 Hallazgo 3 (estructural, el más importante de fondo) — todo el módulo de Tareas sincroniza reenviando el estado completo, no el cambio

`useTaskStore.ts`'s `syncToBackend()` hace esto en **cada** acción operativa (tomar de la bolsa, iniciar, pausar, completar, liberar, omitir, crear tarea/rutina):

```js
await axiosInstance.post('/sync/tasks', {
    tasks: state.tasks,
    routines: state.routines,
    assignments: state.assignments   // el arreglo COMPLETO, no solo lo que cambió
});
```

Y en el backend, `TaskSyncController::sync()` recorre **cada** tarea, rutina y asignación del arreglo recibido con `find()`/`update()` individuales, dentro de una sola transacción. Confirmé que ya existen endpoints más específicos sin usar: `GET /task-assignments` y `PUT /task-assignments/{id}` (`TaskAssignmentController.php`), con scoping por tenant y fecha — pero el frontend nunca los llama.

Esto tiene dos consecuencias reales, no hipotéticas:

**(a) Carrera de datos ("lost update"):** si el celular de un empleado tiene una copia local desactualizada de una asignación (por ejemplo, alguien más ya la tomó de la bolsa, pero a este celular no le ha llegado la actualización todavía) y ese empleado hace *cualquier otra* acción (pausar una tarea distinta, por ejemplo), su `syncToBackend()` reenvía **también** su copia vieja de la asignación ajena dentro del arreglo completo — y el backend la sobrescribe sin ningún control de versión/concurrencia (el único resguardo que tiene es "si ya estaba `completed`, no la puede regresar" — para `pending`/`in_progress` no hay ninguna protección). Con varios empleados operando la Bolsa de Trabajo al mismo tiempo (el caso de uso explícito de la Bolsa), esto puede revertir en silencio la asignación de otra persona.

**(b) Costo que crece con el historial:** cada sync recorre y golpea la base de datos por **cada** tarea/rutina/asignación que el navegador tenga cargada — no solo la que cambió. Con un tenant que acumula meses de historial de tareas, un simple clic de "pausar" en un celular puede terminar procesando cientos de filas históricas en el backend, cada vez.

**Sugerencia para Frontend + Backend (requiere coordinar ambos lados, por eso no lo apliqué yo):**
- Migrar las acciones operativas de `useTaskStore.ts` (`startTask`, `pauseTask`, `completeTask`, `releaseTask`, `omitAssignment`, `grabTaskFromPool`, `reserveTaskFromPool`) para que llamen a `PUT /task-assignments/{id}` (que ya existe) en vez de `syncToBackend()` completo.
- Para que ese cambio sea seguro, `TaskAssignmentController::update()` necesita primero incorporar la misma lógica de recálculo de `requiresValidation`/`points_awarded`/`coins_awarded`/`task_cost` que hoy solo vive en `TaskSyncController::sync()` — si no, mover `completeTask` a este endpoint perdería la validación server-side que hoy sí funciona bien (ver Hallazgo 5).
- Dejar `POST /sync/tasks` únicamente para la creación/edición de definiciones de tareas y rutinas (que cambian con poca frecuencia), no para el trajín operativo de cada clic.
- Considerar un `updated_at`/versión por fila para que `PUT /task-assignments/{id}` pueda rechazar con 409 si alguien más ya la modificó, en vez de sobrescribir a ciegas.

---

## 5. ✅ Buena práctica confirmada — la validación de supervisor SÍ se aplica en el servidor

Antes de reportar esto como vulnerabilidad, lo verifiqué directamente en `TaskSyncController::sync()` (líneas 151-214): aunque el frontend calcula su propia versión de "¿esta tarea necesita validación de supervisor?" en `completeTask()` (incluyendo el sorteo aleatorio del modo `'dynamic'`), el **backend recalcula esa misma decisión de forma independiente** (con su propio `mt_rand()`, no confía en el resultado del cliente) y sobrescribe el `status` que mandó el frontend si corresponde exigir validación. Un cliente modificado que intente mandar `status: 'completed'` a la fuerza no logra saltarse la validación real.

**Único efecto secundario (cosmético, no de seguridad):** como el sorteo del modo `'dynamic'` se calcula dos veces por separado (una vez en el navegador para la UI optimista, otra vez en el servidor para lo que realmente se guarda), pueden no coincidir — el usuario ve "tarea completada" un instante y, al refrescar, aparece como "esperando validación". Sugerencia menor: quitar el cálculo de `validationMode`/`Math.random()` del lado del cliente en `completeTask()` y simplemente mostrar un estado "enviado, confirmando..." hasta que la respuesta del backend confirme el estado real — evita la sensación de que el estado "cambia solo".

---

## 6. 🟡 Hallazgo 4 — tarea "monitoreo de seguridad" en Ley Silla usa un ID inventado (9999) sin tarea real detrás

En `RelojVisual.tsx` (línea 5181), el botón "Monitoreo de seguridad desde silla" del modal de Ley Silla llama a `startBreakWithSittingTask(9999)` — un ID fijo que **no corresponde a ningún registro real** en `tasks`. La asignación que se crea (`useClockEngine.tsx` línea 2559-2577) queda con `taskId: 9999` y se sincroniza igual al backend vía `syncToBackend()`.

Consecuencias: en `TaskSyncController::sync()`, esa asignación busca `Task::find($mappedData['task_id'])` (línea 161) — como no existe, `$task` es `null`, así que si esa "tarea sentado" se marca completada más adelante, `$task->points` truena `$basePoints = $task->points ?? 10` — en realidad esto es seguro porque PHP con `?->`/null-safe... **cuidado:** en la línea 227, es `$task->points ?? 10` **sin** el operador null-safe (`$task?->points`) — si `$task` es `null`, esto es un error fatal de PHP (`Attempt to read property "points" on null`), no un fallback silencioso. Si alguna vez esta asignación llega a marcarse `completed` (por ejemplo si más adelante se conecta un botón "Terminar" genérico para tareas sentado), el sync completo fallaría con 500 para ese usuario.

**Sugerencia:** crear una Tarea real en el seed/semilla de cada tenant (ej. "Monitoreo de seguridad desde silla", `canBeDoneSitting: true`, categoría `operativo`, puntos bajos) y usar su ID real en vez de `9999`; o agregar `$task?->points` (null-safe) en el backend como resguardo mínimo mientras tanto. Lo documento aquí en vez de tocarlo yo mismo porque toca `Backend/app/Http/Controllers/TaskSyncController.php`.

---

## 7. 🟡 Hallazgo 5 — `handleSpillOver` decide "quién es jefe" con IDs de rol hardcodeados

`useTaskStore.ts`, `handleSpillOver()` (línea 398): `if ([1, 2, 3, 4].includes(roleId) && task.priority === 'bloqueante')` — decide si las tareas bloqueantes de un usuario se transfieren (en vez de caer a la Bolsa) comparando su `job_role_id` contra una lista fija de IDs. Como `job_role_id` es el puesto del organigrama, **personalizable por cada tenant** (mismo tipo de supuesto implícito que ya se corrigió en otras partes de este proyecto para "DecorArte"), esos IDs 1-4 solo tienen sentido para el tenant con el que se construyó esta lógica — para cualquier otro tenant, esta regla aplica a puestos arbitrarios (o a ninguno, si esos IDs no existen).

**Sugerencia:** reemplazar por una propiedad real del puesto — el proyecto ya tiene `reports_to_role_id`/`reports_to_role_ids` en `JobRole` (usado en otras partes para saber jerarquía); un puesto "jefe" podría definirse como "ningún otro puesto le reporta a él" o agregar un campo explícito `is_leadership_role` en `job_roles`. Es un cambio pequeño y ya hay precedente de cómo resolverlo en este mismo proyecto.

---

## 8. Resumen para Francisco

| # | Hallazgo | Dónde | Estado |
|---|---|---|---|
| 1 | `date`/`points_awarded` nunca se leían del backend — rompía "Historial de Hoy" y puntos del día | `useAppStore.ts` | ✅ Corregido ahora |
| 2 | Crear/editar tareas y rutinas no valida rol en el servidor — cualquier empleado podría hacerlo vía API directa | `TaskSyncController.php` | ⏳ Requiere backend (spec arriba) |
| 3 | Sincronización de tareas reenvía el estado completo en cada clic — riesgo de carrera de datos y de desempeño a futuro | `useTaskStore.ts` + `TaskSyncController.php` | ⏳ Requiere frontend + backend coordinados |
| 4 | Tarea placeholder de Ley Silla usa ID inventado (9999) sin registro real — riesgo de error 500 si se completa | `RelojVisual.tsx` / `TaskSyncController.php` | ⏳ Requiere backend (crear tarea real o null-safe) |
| 5 | "Quién es jefe" para el spill-over usa IDs de rol hardcodeados, no genérico por tenant | `useTaskStore.ts` | ⏳ Sugerencia, sin aplicar |
| — | La validación de supervisor SÍ se recalcula en el servidor, no es spoofable | `TaskSyncController.php` | ✅ Confirmado, buena práctica |

Lo urgente para mí es el punto 2 (control de acceso real) — es el único que calificaría como vulnerabilidad de seguridad, no solo deuda técnica. El resto son mejoras de robustez que no urgen pero conviene planear, sobre todo antes de que el módulo de Tareas se use con tenants de plantilla grande (ahí el punto 3 se sentiría primero).
