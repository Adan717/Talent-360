# Auditoría Completa — Reloj Checador Talent360 (2026-07-22)

**Alcance:** seguridad, funcionalidad de los 23 estados del dial, persistencia/base de datos, y UX/UI. Todo verificado leyendo el código real (frontend `Frontend/src/components/reloj/**` y backend de solo lectura), no por memoria de auditorías previas — aunque sí se contrasta contra `RESUMEN_EJECUTIVO_AUDITORIA_RELOJ_CHECADOR.md` (20-21 jul) y `docs/BACKEND_INTERFACES.md` para no repetir lo ya resuelto.

**Veredicto general:** el núcleo funcional está sólido — los 23 estados existen y las 5 funciones nuevas de esta semana (§22-§26) quedaron cableadas de punta a punta. Pero encontré **un hallazgo de seguridad grave** (bloqueo por retardos evadible pese a que el backend real ya existe), **una filtración de datos entre tenants** (canal WebSocket público), y **un problema de UI masivo y 100% verificable** (285 clases de color inválidas que no pintan nada). Ninguno requiere rediseñar nada — son correcciones puntuales.

---

## 1. Seguridad

### 🔴 Hallazgo 1 (grave) — El bloqueo por 3 retardos (Estado #1) sigue siendo 100% evadible, aunque el backend real ya existe

`getButtonProps()` en `useClockEngine.tsx` (línea 3026) todavía calcula el bloqueo así:

```js
const retardosCount = Number(localStorage.getItem('user_retardos_' + currentUser?.id) || 0);
const hasPunctualityBlock = retardosCount >= 3;
```

Esto es exactamente el mecanismo que `docs/BACKEND_INTERFACES.md` §12 diagnosticó como inseguro ("cualquiera lo evade borrando el storage del navegador o entrando desde otro dispositivo") — y por eso se construyó `GET /me/punctuality-status`, ya implementado, con permisos corregidos y con 74/74 tests en verde según el contrato. **El problema es que el frontend nunca se conectó a ese endpoint.** Confirmé por búsqueda en todo el módulo: cero referencias a `punctuality-status` en `Frontend/src/components/reloj/**`. El contador sigue viviendo solo en `localStorage` en dos puntos (línea 2852 al incrementar, línea 3026 al leer), y el botón "Ir a la Academia" no navega a ningún curso específico (`required_course_id` tampoco se usa).

**Impacto real:** cualquier colaborador puede evitar el bloqueo por mal comportamiento de puntualidad simplemente borrando datos del navegador, usando el modo incógnito, o fichando desde otro dispositivo/celular. Es un hueco de cumplimiento (la razón de negocio del estado #1) no solo técnico.

**Corrección:** reemplazar la lectura/escritura de `localStorage` por una llamada a `GET /me/punctuality-status` al montar el motor (cachear en memoria, no en `localStorage`, como ya recomendaba el propio contrato), usar `blocked` para el gate y `required_course_id` para la navegación del botón "Ir a la Academia". Es un cambio acotado — el endpoint y las reglas de negocio ya están decididas y probadas, solo falta consumirlas.

### 🔴 Hallazgo 2 (grave) — El canal WebSocket del reloj es público: cualquiera puede escuchar los fichajes de OTRO tenant

Los 4 eventos en tiempo real del reloj (`StoreOpened`, `TimeEntryRecorded`, `DoorNoticeCreated`, `MealQueueTurnChanged`) transmiten sobre:

```php
new Channel('tenant.' . $this->tenantId . '.clock')
```

`Channel` (no `PrivateChannel`) es un canal **público** — no exige autenticación para suscribirse. Confirmé que el frontend también se suscribe así (`echoInstance.channel(channelName)`, no `.private()`, en `useClockEngine.tsx`). Comparen con `NewChatMessage` y `MonitorUpdated`, que sí usan `PrivateChannel` correctamente (exigen pasar por `/broadcasting/auth`).

**Impacto real:** como `tenant_id` es un entero secuencial pequeño (he visto `tenant_id: 1` referenciado como el tenant demo en todo el código), cualquiera que sepa o adivine un `tenant_id` ajeno puede conectarse al WebSocket público de Reverb y suscribirse a `tenant.{id}.clock` **sin haber iniciado sesión en ese tenant**, y ver en vivo: quién fichó entrada/salida y a qué hora, cuándo abre/cierra la tienda, los avisos de "Enviar Mensaje" (con nombres de empleados), y los turnos de la cola de comida. No se filtran contraseñas ni PINs, pero sí nombres de empleados y patrones operativos de un negocio ajeno — inaceptable para un SaaS multi-tenant que cobra por esto.

**Corrección:** cambiar los 4 eventos a `PrivateChannel('tenant.' . $tenantId . '.clock')` en el backend, y el frontend a `echoInstance.private(channelName)`. Requiere que el backend tenga (o agregue) la ruta de autorización del canal en `routes/channels.php` verificando que el usuario autenticado pertenece a ese `tenant_id` — patrón que ya existe para los canales de `NewChatMessage`/`MonitorUpdated`, así que es extender lo mismo, no inventar de cero. Esto es trabajo de Backend — lo dejo listo como spec si quieres que lo mande al contrato.

### 🟡 Hallazgo 3 (medio) — El token de sesión vive en `localStorage`, no en cookie httpOnly

`axios.ts` guarda `talent_auth_token` en `localStorage` y lo inyecta como `Authorization: Bearer`. Es el patrón más común en SPAs, pero tiene una debilidad estructural: cualquier XSS futuro (aunque hoy no encontré ninguno — cero usos de `dangerouslySetInnerHTML` en todo el módulo del reloj) podría robar el token completo con una línea de JS. Noto que `axiosInstance` también manda `withCredentials: true`, lo cual sugiere que ya existe infraestructura de cookies de Sanctum — sería más seguro migrar a autenticación 100% por cookie httpOnly (el navegador nunca expone el token a JS) y dejar de mandar el Bearer manual. No es urgente dado que no hay XSS conocido hoy, pero es la causa raíz que convertiría un XSS futuro en robo de sesión completo.

### 🟡 Hallazgo 4 (medio) — El logout no limpia el caché local del reloj; riesgo en dispositivos compartidos

`handleLogout()` (`App.tsx`) solo borra `talent_auth_token`. El reloj usa **más de 30 llaves de `localStorage`** como caché (`store_opening_assignments`, `store_daily_opening_status`, `clock_break_start_times`, `user_retardos_<id>`, checklists de apertura/cierre, cola offline, etc.) que nunca se limpian al cerrar sesión. Como el Reloj Checador está diseñado explícitamente para dispositivos compartidos en tienda (varios empleados fichan desde el mismo celular/tablet), y además ya existe el Simulador Matrix para probar distintos tenants desde el mismo navegador, hay una ventana real donde datos de un usuario/tenant anterior quedan visibles momentáneamente (hasta que el primer `fetchState()` los sobreescribe) al iniciar sesión otra persona en el mismo dispositivo.

**Corrección sugerida:** en `handleLogout()`, limpiar todas las llaves con esos prefijos (o, mejor a mediano plazo, namespacing con `tenant_id`/`user_id` en el nombre de la llave, no solo `user_id` como ya hacen algunas).

### ✅ Buenas prácticas confirmadas (no requieren cambios)

- El secreto HMAC offline se cachea **en memoria**, nunca en `localStorage` — correcto, documentado explícitamente así para reducir superficie de robo.
- Los PIN (supervisor, testigos, silla) **nunca se persisten**: viven en `useState`, se limpian después de usarse (`setSupervisorPin('')`), y todos los `<input>` de PIN usan `type="password"`.
- Cero `console.log`/`console.error` que expongan PIN, token o contraseña.
- Cero `dangerouslySetInnerHTML` en todo el módulo.
- El bypass de validación GPS (`allowManualCheckIn`, `gpsValidationEnabled`) es una config de admin, no un hack de cliente — aunque vale recordar que el GPS del navegador es inherentemente falsificable por cualquier usuario (limitación de cualquier sistema de geolocalización web, no un bug de Talent360; por eso `face_validation` como segundo factor tiene sentido si se corrige el hallazgo #1 de `FEATURE_TIERS.md`, el flag muerto que nunca se desbloquea).
- Rate limiting en `/clock/punch`, `/clock/punch-batch` y validación de PIN de testigos: implementado y confirmado en el contrato (§16).
- Validación de secuencia de eventos en `processPunch()` (no puedes fichar `meal_end` sin `meal_start`, etc.): implementado (§15).
- `keys_control` ya no es un flag fantasma — corregido esta misma sesión.

---

## 2. Funcionalidad de los 23 estados del dial

Estado actual, cruzando `docs/Logica Dial.md`, el trabajo de esta sesión y el código real:

- **Los 23 estados base están implementados y funcionales** — confirmado en la auditoría de viabilidad del 21-jul y no hay regresiones nuevas detectadas hoy.
- **Las 5 funciones avanzadas (§22-§26) quedaron completas de punta a punta esta semana**: calificación de pase de lista con estrellas, evidencia fotográfica de comedor, cola secuencial de comida, Ley Silla con aprobación de supervisor + control de aforo, y aviso "Enviar Mensaje" con push real (FCM).
- **Pendiente menor ya anotado en el contrato:** §25b — falta `GET /clock/silla/requests?status=pending` para que el supervisor pueda listar y aprobar solicitudes de silla dentro de la app (hoy solo puede aprobar si le llega el `request_id` por push).
- **Decisiones de producto deliberadamente diferidas** (no son bugs): horarios globales de sucursal vs. por empleado, modo `fixed`/`24h`, cableado del Wizard de onboarding — todo esto quedó anotado en `docs/VIABILIDAD_LOGICA_DIAL.md` a petición tuya, sin programar aún.

No encontré ningún estado roto o regresado en esta pasada.

---

## 3. Persistencia y base de datos

- **Cola offline (IndexedDB) + firma HMAC + sincronización en lote:** arquitectura sólida, ya verificada en la auditoría anterior y sin cambios que la comprometan.
- **Aislamiento del Simulador Matrix:** confirmado consistente — por ejemplo, la cola de comida (§24) excluye explícitamente `simulation_session_id` al calcular elegibilidad, seguiendo el mismo patrón que el resto del sistema.
- **Patrón de riesgo estructural (no es un bug puntual, es un patrón repetido):** el reloj usa `localStorage` no solo como cache de UI sino, en varios puntos, como **fuente de verdad temporal** mientras no hay red (`store_opening_assignments`, `store_daily_opening_status`). Esto ya causó un bug real corregido en esta sesión (el PUT parcial del organigrama que no persistía) y es la misma familia de riesgo que el Hallazgo 4 de seguridad (datos viejos sobreviviendo entre sesiones). No hace falta quitar el patrón — es necesario para que la PWA funcione offline — pero sí conviene, a mediano plazo, una limpieza sistemática al logout y una revisión de qué llaves deberían tener expiración.

---

## 4. UX/UI

### 🔴 Hallazgo mayor — 285 clases de color de Tailwind que no existen y no pintan nada

Busqué todos los usos de escalas de color fuera de los pasos estándar de Tailwind (50, 100, 200...900, 950) en el módulo del reloj. `tailwind.config.js` no define ninguna paleta personalizada (`theme.extend` está vacío), así que estas clases son inválidas y Tailwind simplemente no genera CSS para ellas — el elemento queda sin ese color, silenciosamente.

| Archivo | Ocurrencias |
|---|---:|
| `RelojVisual.tsx` | **233** |
| `DialPrincipal.tsx` | 26 |
| `Academia.tsx` | 10 |
| `NominaColaborador.tsx` | 5 |
| `useClockEngine.tsx` | 3 |
| `MobileBottomNav.tsx` / `iOSInstallGuide.tsx` / `PanelSimulador.tsx` | 3+3+2 |

Ejemplos reales encontrados: `text-slate-350`, `text-violet-505`, `bg-purple-650`, `dark:text-rose-455`, `dark:bg-violet-955/20`, `border-slate-202`, `text-teal-650`. Muchos parecen typos por "desliz de +5" (`755` en vez de `700` o `750`, `505` en vez de `500`), posiblemente de una etapa de generación/escritura rápida del código base, previa a esta sesión.

**Impacto real:** en el mejor caso el elemento se queda sin color (se ve "apagado" o hereda el color del padre); en el peor, texto que debería tener contraste (ej. `dark:text-emerald-455` sobre fondo oscuro) puede quedar casi invisible en modo oscuro. Es probable que expliquen algunos "se ve raro pero no sé por qué" que hayas notado visualmente sin poder señalar la causa exacta.

**Corrección:** es mecánico — mapear cada valor inválido al paso válido más cercano (750→700 u 800 según el diseño pretendido, 455→400 o 500, etc.). Dado el volumen (285), recomiendo hacerlo como una pasada dedicada con verificación visual tuya después, no a ciegas.

### 🟡 Paleta de color sin criterio semántico definido

Más allá de las clases inválidas, el color usado sí resuelve a algo válido en la mayoría de los casos, pero **no sigue un sistema**: el mismo color se reutiliza para conceptos distintos y conceptos relacionados usan colores distintos sin razón aparente. Ejemplo: violeta se usa tanto para "llamar a suplente" como para íconos de reloj de arena de apertura; ámbar se usa para "reportar incidencia", "iniciar comida" y "tienda cerrada" simultáneamente — tres significados distintos (alerta, acción neutral, bloqueo) con el mismo color. Esto es justo lo que preguntabas sobre la paleta — ver la propuesta abajo.

### ✅ Accesibilidad — parcial, ya con una base

El Dial principal ya tiene `aria-label` en los controles clave (trabajo de una sesión anterior, task #37) y el cronómetro nuevo de esta semana también lo lleva. No audité accesibilidad a fondo en modales secundarios (pase de lista, checklist de cierre, etc.) — si quieres, lo agrego como punto de seguimiento separado, es un trabajo acotado pero distinto al resto de esta auditoría.

---

## 5. Propuesta de paleta de colores profesional para el Dial

Objetivo: que el color comunique **qué tipo de acción es**, no que sea decorativo. Propongo 6 categorías fijas, reutilizables en los 23 estados:

| Categoría | Color base | Cuándo usarlo | Estados de ejemplo |
|---|---|---|---|
| **Acción disponible (positiva)** | `emerald` (esmeralda) | El colaborador puede avanzar su jornada ahora mismo | Fichar Entrada, Abrir Tienda, Terminar Comida/Descanso |
| **Atención / ventana activa** | `amber` (ámbar) | Algo requiere acción pronto pero no es un bloqueo | Reportar Ausencia/Retardo, Iniciar Comida, Apartar Turno |
| **Bloqueo / crítico** | `rose` (rosa/rojo) | El sistema bloquea o es una emergencia | Fichaje Bloqueado, Acceso Bloqueado, Apertura de Emergencia, Botón de Pánico |
| **Informativo / en espera** | `slate` (gris pizarra) | Estado pasivo, sin acción disponible | Esperando Apertura, Día Feriado/Descanso, Fin de Jornada |
| **Personal de llaves** | `violet` (violeta) | Exclusivo de titulares/suplentes de llaves — reservar SOLO para esto | Llamar a Suplente, Entrega de Turno, Apertura de Emergencia (como badge secundario) |
| **Bienestar / descanso** | `cyan` o `teal` (cian/verde azulado) | Comida y Ley Silla — familia separada de "acción positiva" para que no se confundan con fichaje | Comiendo, Descanso Ley Silla, Solicitar Silla |

Reglas para que se vea profesional y no "arcoíris":
- Máximo 6 colores con propósito en toda la app del reloj (más `slate` para neutro/inactivo). Hoy cuento al menos 9 tonalidades distintas usadas sin criterio (amber, violet, indigo, purple, cyan, teal, rose, emerald, orange) — orange y purple son redundantes con amber y violet respectivamente; se pueden retirar.
- Un color = un significado, siempre. Si "violeta" es de llaves, no debe aparecer en ningún estado que no sea de llaves (hoy aparece también en la animación del reloj de arena de apertura, sin relación con llaves).
- Los grados de intensidad (600 para el fondo del botón activo, 100 para badges suaves, 50 para fondos de tarjeta) ya son razonables — el problema no es la intensidad, es la variedad de tonos base.
- Mantener `indigo` reservado para acentos de marca/navegación general (fuera del dial), no mezclado con los estados operativos, para que el dial se lea como un sistema aparte y consistente.

Si quieres, puedo aplicar esta paleta como parte de la limpieza de las 285 clases inválidas — sería la misma pasada de trabajo, matando dos pájaros: arreglo lo que no pinta Y unifico el criterio de color al mismo tiempo.

---

## 6. Plan de acción priorizado

1. **Urgente — Hallazgo 1:** conectar el estado #1 al endpoint real `GET /me/punctuality-status` (frontend, ya tengo todo el contexto para hacerlo).
2. **Urgente — Hallazgo 2:** migrar los 4 eventos del canal del reloj a `PrivateChannel` (requiere backend — puedo redactar la spec para Claude Code).
3. **Medio — Hallazgo 4:** limpiar `localStorage` del reloj al hacer logout.
4. **Medio — UI:** corregir las 285 clases de color inválidas, aplicando de una vez la paleta semántica de la sección 5.
5. **Opcional — Hallazgo 3:** evaluar migrar a auth por cookie httpOnly (cambio más grande, no urgente sin XSS conocido hoy).
6. **Opcional:** pasada de accesibilidad en modales secundarios.
7. **Ya en el contrato:** §25b (listar solicitudes de silla pendientes) — pendiente de Claude Code.

Dime cuáles quieres que ataque y en qué orden.

---

## ✅ Implementado (2026-07-22) — Punto 4: clases de color inválidas + consolidación de familias redundantes

Barrido mecánico sobre las 12 subcarpetas/archivos de `Frontend/src/components/reloj/**` (incluye `hooks/`), no solo `RelojVisual.tsx`. El conteo real detectado por script fue **367 ocurrencias inválidas** (más que las 285 estimadas a ojo en la sección 4 — el conteo manual original no cubrió todos los archivos del módulo).

**Qué se hizo:**
1. Cada shade inválido (`955, 850, 202, 750, 505, 350, 250, 650, 450, 550, 105, 55, 655, 605, 455, 405, 855, 555, 705, 805, 905, 665, 660, 205, 150, 255, 755`, etc.) se mapeó al paso válido más cercano de la escala estándar de Tailwind (`50‑950`). En los casos de empate exacto entre dos pasos válidos (ej. `650` entre `600` y `700`), se redondeó siempre hacia abajo por consistencia — es una decisión mecánica razonable, no una revisión visual por color.
2. Se renombraron las familias redundantes que la sección 5 marcó para retirar: **todo `purple-*` → `violet-*`** y **todo `orange-*` → `amber-*`** (mismo número, solo cambia el nombre de familia).
3. **No se tocó** la asignación semántica más fina de la paleta (ej. sacar `indigo` de los estados operativos del dial y dejarlo solo para navegación/marca, o que `violet` aparezca únicamente en contexto de llaves) — esa parte requiere criterio visual por componente, no es mecánica, y la propia auditoría recomendaba hacerla con verificación visual tuya. Queda pendiente si la quieres como siguiente pasada.

**Archivos tocados:** `RelojVisual.tsx` (2047 sustituciones), `Academia.tsx` (236), `PanelSimulador.tsx` (165), `NominaColaborador.tsx` (144), `useClockEngine.tsx` (85), `MobileBottomNav.tsx` (56), `MealQueue.tsx` (46), `MealReservation.tsx` (39), `Evaluacion360.tsx` (28), `iOSInstallGuide.tsx` (25), `CertificadoImprimible.tsx` (17), `MealPhotoCapture.tsx` (18). Total: 3,182 sustituciones.

**Verificación:** re-barrido con el mismo script confirmó 0 clases inválidas y 0 `purple`/`orange` restantes en el módulo; `tsc --noEmit -p tsconfig.app.json` → 0 errores.

**Pendiente real de este punto (no mecánico):** revisión visual tuya para confirmar que ningún color quedó "raro" tras el redondeo de empates, y — si quieres ir más allá — la reasignación semántica fina de `indigo`/`violet`/`cyan`/`teal` descrita en la sección 5.

---

## ✅ Implementado (2026-07-22) — Punto 3: limpiar localStorage del reloj al cerrar sesión

Creé `Frontend/src/lib/clockCache.ts` con `clearClockLocalCache()`, y la conecté en los **5 puntos reales de logout** del sistema (no solo `App.tsx` — encontré que `RelojVisual.tsx` tiene **4 botones de "Cerrar Sesión" propios** dentro del reloj móvil/tablet que hacían `localStorage.removeItem('talent_auth_token')` directo, sin pasar por `handleLogout()` de `App.tsx`. Como el escenario de riesgo del Hallazgo 4 es justo el dispositivo compartido de tienda, esos 4 botones del reloj eran el punto de fuga más probable, así que también se corrigieron).

**Se limpian al cerrar sesión:** `clock_break_start_times`, `clock_break_end_times`, `clock_meal_start_times`, `clock_meal_end_times`, `clock_checkout_times`, `clock_pending_break_requests`, `store_opening_assignments`, `store_daily_opening_status`, `store_opening_settings`, `opening_checklist_completed`, `opening_roll_call_completed`, `closing_checklist_completed`, y el prefijo `commute_confirmed_*` (esta última no estaba namespaced por usuario — es un hallazgo adicional, no solo el logout).

**Exclusiones deliberadas (documentadas en el propio código):**
- `clock_sync_queue` — cola de fichajes offline sin sincronizar. Limpiarla en logout perdería fichajes reales pendientes de subir al servidor.
- `user_retardos_<id>` — el mecanismo local (inseguro) de bloqueo por 3 retardos del Hallazgo 1. Limpiarlo en logout lo haría *más* fácil de evadir (bastaría cerrar sesión y volver a entrar). Se debe retirar por completo cuando se conecte el estado #1 a `GET /me/punctuality-status` — ese es el próximo punto del plan (punto 1).

**Verificación:** `tsc --noEmit -p tsconfig.app.json` → 0 errores.

---

## ✅ Implementado (2026-07-22) — Punto 1: bloqueo por retardos conectado a GET /me/punctuality-status

- `useAppStore.ts`: nuevo estado `punctualityStatus` (cacheado **solo en memoria**, nunca localStorage) y acción `fetchPunctualityStatus()` que llama a `GET /me/punctuality-status`.
- `useClockEngine.tsx`: al montar (fuera de sandbox) hace el fetch inicial. `getButtonProps()` ya no lee `localStorage.user_retardos_<id>` para decidir el bloqueo del estado #1 — usa `punctualityStatus.blocked` del backend. El modo sandbox/Matrix conserva el mecanismo local (no hay backend real de puntualidad que simular ahí).
- Reordené el flujo de "Entrada Tardía Autorizada": ahora hace `syncToDB('check_in')` primero y **después** refresca `punctuality-status`, para que el conteo mostrado en el toast y en el evento de la Matrix incluya el retardo recién registrado (antes el contador local se incrementaba a ciegas, sin depender del backend).
- Botón "Ir a la Academia": no existía como tal (el dial solo mostraba texto, sin acción — confirmado el hallazgo de la auditoría). Agregué un CTA secundario debajo del dial (`DialPrincipal.tsx`, visible solo cuando `iconKey === 'blocked'`) que navega a la pestaña Academia. `Academia.tsx` recibe `autoOpenCourseId` (el `required_course_id` real del backend) y abre ese curso automáticamente en cuanto carga la lista, en vez de dejar al empleado buscarlo.
- Al completar el curso, `Academia.tsx` ya no compara el título por texto ("incluye 'puntualidad'") — compara `activeCourse.id` contra `systemSettings.punctuality_course_id` (el que configuró el tenant) y refresca `punctualityStatus` desde el backend en vez de resetear `localStorage` a mano.

**Verificación:** `tsc --noEmit -p tsconfig.app.json` → 0 errores. Pendiente de tu verificación visual/funcional en el simulador o en un tenant real con retardos acumulados.

---

## 🟡 En curso (2026-07-22) — Punto 2: canal WebSocket privado del reloj

Este punto necesita cambios en **ambos lados** (backend + frontend) sincronizados en el mismo despliegue — no es seguro activarlo solo de un lado.

Ya hice mi parte: redacté la spec completa en `docs/BACKEND_INTERFACES.md` §27, con los 4 archivos y líneas exactas de backend que hay que tocar (`app/Events/StoreOpened.php`, `TimeEntryRecorded.php`, `DoorNoticeCreated.php`, `MealQueueTurnChanged.php` — cambiar `Channel` por `PrivateChannel`) y la entrada nueva que falta en `routes/channels.php`. Quedó en la tabla de pendientes del contrato (§27) para que Claude Code la recoja con "revisa pendientes del contrato".

**Deliberadamente NO cambié** `useClockEngine.tsx:308` (`echoInstance.channel` → `.private`) todavía: si el frontend pide un canal privado antes de que exista la autorización en el backend, el reloj se queda sin tiempo real para todos los usuarios hasta que ambos lados coincidan — es peor que dejarlo como está mientras se coordina. En cuanto confirmes que Claude Code implementó §27, hago ese cambio de una línea de inmediato.

---

## 🔎 Evaluado, no implementado (2026-07-22) — Punto 5: migración a auth por cookie httpOnly

Revisé el backend (solo lectura) para saber qué tan armado está esto antes de dar una recomendación:

- `config/sanctum.php` y `config/cors.php` ya tienen `supports_credentials: true`, `SANCTUM_STATEFUL_DOMAINS` con los dominios de desarrollo, y `sanctum/csrf-cookie` en los `paths` de CORS — parece scaffold preparado, pero **no está en uso real**.
- Confirmé en `AuthController.php` (líneas 71, 234, 261) que login/registro emiten un token Sanctum clásico (`$user->createToken('auth_token')->plainTextToken`), no una sesión. Es decir, hoy el flujo es 100% Bearer token, y el `withCredentials: true` / la config de CORS de arriba no se está aprovechando para autenticación — probablemente quedó de un scaffold inicial de Laravel Breeze/Sanctum sin terminar de cablear.

**Por qué no lo implementé directo, solo lo evalúo:** este cambio no es del módulo del reloj — toca el login/logout de **toda la aplicación** (RRHH, reportes, facturación, todo lo que usa `axiosInstance`), no solo `Frontend/src/components/reloj/**`. Requiere:
1. Backend (Claude Code): cambiar los 3 puntos de `AuthController.php` para usar `Auth::login($user)` + sesión en vez de `createToken()`, registrar el middleware `EnsureFrontendRequestsAreStateful` en el grupo de rutas API, y confirmar que `SESSION_DRIVER` no sea `array` (necesita persistir sesiones entre requests).
2. Frontend: quitar el interceptor de `Authorization: Bearer` en `Frontend/src/lib/axios.ts`, agregar una llamada a `GET /sanctum/csrf-cookie` antes del login, y manejar el header `X-XSRF-TOKEN` en cada request mutante.
3. Consecuencia operativa: **fuerza a cerrar sesión a todos los usuarios activos** el día que se despliegue (los tokens Bearer existentes dejan de sincronizar con el nuevo esquema) — necesita ventana de mantenimiento avisada, no es un cambio transparente.

**Un matiz que vale la pena que consideres antes de decidir:** el Reloj Checador está pensado para tablets/celulares en modo PWA instalada en tienda, posiblemente en uso días seguidos sin cerrar sesión. Las cookies (sobre todo en Safari/iOS con Intelligent Tracking Prevention) pueden expirar o purgarse más agresivamente que un token en `localStorage` en ese escenario de kiosco de larga duración — no es una mejora estrictamente superior para ese caso de uso específico, es un trade-off real entre "más resistente a XSS" y "más frágil en PWA de kiosco". Como hoy no hay ningún XSS conocido (confirmé 0 usos de `dangerouslySetInnerHTML` en todo el módulo), mi recomendación es dejarlo en el radar pero no priorizarlo — si en algún momento aparece una superficie de XSS real (ej. contenido enriquecido de terceros, editor de texto libre visible a otros usuarios), ahí sí se vuelve urgente y yo mismo lo subiría de prioridad.

No se tocó ningún archivo de auth en esta pasada — es evaluación, no implementación.

---

## ✅ Implementado (2026-07-22) — Punto 6: accesibilidad en modales secundarios

Barrido sobre todos los overlays de tipo modal (`fixed`/`absolute inset-0` con backdrop + encabezado `<h2>`/`<h3>`) en `RelojVisual.tsx`, `Academia.tsx`, `DialPrincipal.tsx`, `MealPhotoCapture.tsx` y `MealQueue.tsx`. A cada uno le agregué `role="dialog"` `aria-modal="true"` y `aria-label`/`aria-labelledby` (usando el título visible del propio modal, o un texto descriptivo cuando el título es condicional/dinámico y no se puede extraer de forma segura). Casos especiales:
- El overlay de "Sucursal en Paro de Emergencia" (Botón de Pánico) usa `role="alertdialog"` + `aria-live="assertive"` en vez de `role="dialog"`, por ser una alerta crítica que debe anunciarse de inmediato.
- El menú flotante de perfil (no es un modal de pantalla completa, es un popover) recibió `role="menu"` en vez de `role="dialog"`.
- Los overlays puramente decorativos (glows, gradientes, máscaras de imagen en hover) se dejaron sin tocar — no son diálogos, etiquetarlos habría sido ruido para lectores de pantalla.

**Total:** 32 modales/diálogos con `role="dialog"` en `RelojVisual.tsx`, más 1 en `Academia.tsx`, 1 en `MealPhotoCapture.tsx`, 1 en `MealQueue.tsx`. No cubrí el 100% de los overlays del archivo (algunos títulos son puramente dinámicos y no encontré un texto seguro de extraer sin revisar cada uno a mano) — es una mejora sustancial, no una garantía de cobertura absoluta. Si quieres el 100%, dime y hago una pasada manual de los que quedaron sin etiquetar.

**Verificación:** `tsc --noEmit -p tsconfig.app.json` → 0 errores.
