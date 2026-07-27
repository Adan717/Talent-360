# Auditoría General — Plataforma Talent360 (2026-07-24)

**Alcance:** a diferencia de las auditorías previas (enfocadas en Reloj Checador + Tareas), esta pasada cubre la plataforma completa a petición de Francisco: conexión backend↔frontend, seguridad end-to-end (no solo el reloj), y eficiencia de datos — específicamente el reporte de que "tarda mucho en cargar la base de datos". Backend revisado en solo lectura (38 controladores, 71 modelos, 128 migraciones, `routes/api.php`); frontend revisado y corregido donde el hallazgo era puramente de cliente.

**Veredicto general:** encontré **un hallazgo grave y explotable hoy mismo por cualquier visitante anónimo de internet** (XSS almacenado en la página pública del organigrama) — ya mitigado del lado del cliente en esta misma sesión, pendiente de una segunda capa en el servidor. Encontré también **la causa raíz real y verificable del reporte de lentitud**: un endpoint central (`/sync/state`) que hace ~15 consultas separadas a la base de datos en cada llamada, sin caché para datos que casi nunca cambian, con un patrón N+1 confirmado, sondeado (polled) por el frontend cada 60 segundos (cada 5s en modo QA/Matrix) por cada sesión activa — además de duplicar información que ya llega en tiempo real por WebSocket. El resto de la plataforma (aislamiento multi-tenant, CORS, protección de rutas de super-admin) está en buen estado.

---

## 1. Seguridad

### 🔴 Hallazgo 1 (grave) — XSS almacenado en la página pública del organigrama, explotable por cualquier visitante sin cuenta

**Ya mitigado hoy del lado del cliente** (ver sección "Qué se corrigió ya" abajo) — se documenta aquí para que quede el registro y para que Backend agregue la segunda capa de defensa.

`WebPublicaOrganizacion.tsx` sirve dos rutas **públicas, sin `<ProtectedRoute>`, sin sesión**: `/organizacion/:tenantSlug` y `/organizacion/:tenantSlug/:docSlug` (confirmado en `App.tsx`, líneas 826-835). Esa pantalla renderizaba contenido de documentos del vault (`ObsidianDocument.content`) y HTML generado por el asistente de IA de contratos (`scribeResultHtml`) directamente con `dangerouslySetInnerHTML`, sin sanitizar en ningún punto — ni al guardar en el backend, ni al mostrar en el frontend. `OrgVaultManager.tsx` (el editor interno del vault) tenía el mismo patrón sin sanitizar para `activeDoc.content`.

**Impacto real:** cualquier cuenta con permiso de edición del vault de una empresa (o una cuenta de ese tenant comprometida por phishing/credenciales filtradas) podía escribir HTML/JavaScript malicioso en un documento del organigrama, y ese código se ejecutaría en el navegador de **cualquier visitante anónimo de internet** que abriera el enlace público de esa empresa — no hace falta que la víctima tenga cuenta en Talent360 ni haya iniciado sesión. Con eso se puede robar sesiones, superponer formularios de phishing, redirigir a sitios maliciosos, etc. Es el hallazgo más serio de todas las auditorías hechas hasta ahora en esta plataforma, porque el afectado no es un usuario interno del sistema sino el público general que visita la página de empleos/organigrama de una empresa cliente.

### 🟡 Hallazgo 2 (menor, ya mitigado) — Login de Kiosco no ata `employee_id` al tenant del dispositivo

`AuthController::kioskLogin()` (§37) busca el empleado por `employee_id` sin restricción de tenant (`Employee::withoutGlobalScopes()->find($request->employee_id)`) — es una decisión de diseño documentada explícitamente en el código ("no hace falta un mecanismo de tenant del dispositivo: employee_id ya identifica una fila única"), y la autenticación real ocurre por el PIN, no por el ID. Como el PIN es corto (4-6 dígitos), esto se mitiga con `throttle:5,1` (5 intentos por minuto) ya aplicado en la ruta. Riesgo residual bajo: un atacante con mucha paciencia (o varias IPs) podría, en teoría, probar PINs contra `employee_id` de otros tenants — no es explotable en la práctica hoy por el throttling, pero si algún día se quiere cerrar del todo, la opción sería añadir un `tenant_slug`/`device_token` que limite la búsqueda al tenant del kiosco físico. No urgente.

### ✅ Buenas prácticas confirmadas (no requieren cambios)

- **CORS bien configurado** (`config/cors.php`): orígenes restringidos a `FRONTEND_URL` + localhost/IPs privadas para desarrollo, `supports_credentials: true` ya preparado para la migración a cookies (§43).
- **Rutas de `platform_admin` correctamente protegidas**: todo el grupo `/platform/*` (incluida la suplantación de tenants) exige `middleware(['auth:sanctum', 'role:platform_admin'])` — confirmado en `routes/api.php`.
- **169 usos de `withoutGlobalScopes()`** revisados de forma sistemática (script que busca `tenant_id` cerca de cada uso): la gran mayoría son seguros porque el ID que buscan viene de una fila ya validada por tenant más arriba (ej. `$employee->user_id`) o son del panel de `platform_admin`, que legítimamente necesita ver todos los tenants. No se encontró ningún caso de fuga de datos entre tenants distinto al Hallazgo 2 ya descrito.
- Sin claves/API keys hardcodeadas en el frontend (el único match de un patrón de clave de Google fue un `placeholder` de un `<input>`, no una clave real).

---

## 2. Eficiencia de datos — la causa real de "tarda mucho en cargar la base de datos"

### 🔴 `/sync/state` (`ClockController::getState()`) — endpoint monolítico, sin caché, sondeado cada 60s por cada sesión activa

Este es el endpoint que alimenta prácticamente toda la app (`useAppStore.fetchState()`). En cada llamada ejecuta, sin excepción, **~15 consultas separadas** a la base de datos, todas por tenant completo (no filtradas por usuario):

- `time_entries`, `store_logs`, `contingencies`, `internal_messages`, `audit_logs` — 5 consultas, cada una escaneando la última semana completa del tenant.
- `employees`, `role_permissions` + join `permissions`, `job_roles`, `ui_rbac_rules`, `role_clock_policies`, `permissions`, `role_permissions` (sí, dos veces — línea 212-213 repite prácticamente la misma consulta de la línea 195-200), `system_settings`, `tasks` (con join a `academy_courses`), `routines`, `task_assignments` — otras ~10 consultas.

**Y esto se repite cada 60 segundos, por cada sesión de usuario activa** (`useClockEngine.tsx:272`, `setInterval(fetchState, 60000)`) — y **cada 5 segundos** cuando el Simulador Matrix está corriendo (`PanelSimulador.tsx:274`). Con varios usuarios de la misma tienda conectados simultáneamente (el caso normal de uso — varios celulares fichando a la vez), cada uno dispara su propia ronda de 15 consultas cada minuto, todas pidiendo esencialmente los mismos datos del mismo tenant.

**Problemas concretos identificados dentro de esta misma función:**

1. **Datos casi estáticos, refrescados en cada llamada:** `job_roles`, `permissions`, `role_permissions`, `ui_rbac_rules`, `role_clock_policies` cambian con muy poca frecuencia (solo cuando un admin edita la configuración de puestos/permisos), pero se vuelven a leer de la base de datos en cada ciclo de 60s de cada usuario. Son candidatos directos a caché (Redis o `Cache::remember()` con TTL corto, invalidado cuando el admin efectivamente los edita).
2. **N+1 confirmado en `routines`:** por cada rutina del tenant, se hace una consulta aparte a `routine_task` (`DB::table('routines')->get()->map(fn($r) => DB::table('routine_task')->where('routine_id', $r->id)->pluck('task_id')...)`) — con N rutinas son N+1 consultas en vez de 2 (una para rutinas, una con `whereIn('routine_id', [...])` agrupada en PHP).
3. **Consulta de `role_permissions` duplicada** dentro de la misma función (líneas ~195 y ~213 hacen prácticamente la misma consulta).
4. **Redundancia con WebSocket:** el reloj ya tiene eventos en tiempo real (`TimeEntryRecorded`, `StoreOpened`, etc., §20/§27) empujando actualizaciones al instante — el polling de 60s es un respaldo "por si acaso" razonable, pero sondear TODO el estado (las 15 consultas) en vez de solo verificar "¿algo cambió?" es más caro de lo necesario.
5. **Índices insuficientes:** `time_entries`, `store_logs`, `contingencies`, `internal_messages`, `audit_logs` obtuvieron su columna `tenant_id` vía una migración genérica (`add_tenant_id_to_all_tables.php`) que solo agrega `foreignId()->constrained()` — eso indexa `tenant_id` solo, pero las consultas reales filtran por **`tenant_id` + `date`/`created_at`** a la vez. Sin un índice compuesto, la base de datos usa el índice de `tenant_id` y luego escanea secuencialmente para aplicar el filtro de fecha — cada vez más lento conforme crecen esas tablas (son, justamente, las tablas que más crecen con el uso diario del reloj).

**Esto explica directamente el síntoma reportado:** conforme una empresa cliente acumula meses de fichajes/logs y tiene varios empleados usando el reloj a la vez, cada minuto se disparan docenas de consultas pesadas y redundantes contra las mismas tablas que están creciendo sin los índices adecuados para ese patrón de acceso.

---

## 3. Aislamiento de identidades: Landing / Plataforma Talent / Plataforma de Empresa / Soporte

**Contexto (a petición de Francisco, 2026-07-24):** el modelo de producto tiene 4 superficies con audiencias distintas — la **Landing Page** (marketing público, alta de cuenta con Google, cada cuenta puede crear N empresas), la **Plataforma de Empresa** (el SaaS multi-tenant, un panel administrativo aislado por empresa), la **Plataforma Talent** (super-admin, ve y administra todas las empresas), y la **Página de Soporte** (agentes de soporte, algunos con permisos equivalentes a super-admin para atender incidencias). Pidió verificar que estas identidades vivan separadas y no se puedan cruzar, buscar credenciales hardcodeadas, y evaluar seguridad reforzada (MFA, cuenta de respaldo, "botón de pánico") para la cuenta de super-admin.

**Confirmando el modelo correcto primero (nomenclatura para el resto del documento):**

- **Landing Page** → cuentas de empresa (dueños/admins de cada tenant). Tabla `users`, con `tenant_id` (nulo justo después del alta con Google, hasta que el usuario efectivamente crea su empresa — "estado de pre-registro").
- **Plataforma de Empresa** → mismas cuentas de `users`, ya con `tenant_id` asignado — aisladas entre sí por `TenantScope` (un global scope de Eloquent que filtra automáticamente cualquier consulta por `tenant_id` del usuario autenticado).
- **Plataforma Talent** (súper-admin) y **Página de Soporte** (agentes) → tabla **separada**, `platform_users` (con su propio modelo `PlatformUser`, no `User`) — esto ya está bien pensado en el diseño original: `platform_admin` y `support_agent` NO deberían vivir en la misma tabla que los empleados/admins de las empresas.

**🔴 Hallazgo grave — credenciales hardcodeadas en el repositorio, incluyendo el correo personal de Francisco**

`database/seeders/DatabaseSeeder.php` inserta, cada vez que se corre el seeder (incluyendo en cualquier entorno donde alguien ejecute `php artisan db:seed`), tres cuentas de `platform_users` con contraseñas en texto plano dentro del código fuente:

| Correo | Contraseña (hardcodeada) | Rol |
|---|---|---|
| `master@talent360.com` | `Master` | `platform_admin` |
| `pcmasterirapuato@gmail.com` (**el correo real de Francisco**) | `Master` | `platform_admin` |
| `support@talent360.com` | `Support123` | `support_agent` |

Esto es exactamente lo que preguntabas si existía — sí existe, y es grave por tres razones: (1) las contraseñas son trivialmente adivinables (palabras de diccionario, sin símbolos ni números en dos de los tres casos), (2) cualquiera con acceso de lectura al repositorio (incluyendo, con el tiempo, colaboradores, contratistas, o una filtración de código) tiene automáticamente credenciales de super-admin válidas, y (3) una de esas cuentas usa tu correo personal real como identificador — si en algún momento alguien corre el seeder contra una base de datos de producción sin haber cambiado antes esa contraseña, tu cuenta de super-admin queda con `Master` como contraseña real y funcional.

**🟡 Hallazgo medio — el 2FA existe a medias y explícitamente NO aplica a cuentas de plataforma**

Hay columnas `two_factor_enabled`/`two_factor_secret` en `users` y el `login()` calcula `requires_2fa` en la respuesta — pero **no existe ningún endpoint en `routes/api.php` que reciba y valide un código de 6 dígitos**. El campo se puede activar/desactivar desde ajustes de seguridad, pero no hay una segunda llamada que la app deba hacer para completar el login: el `token` que regresa `login()` ya es válido y funcional en la misma respuesta que dice `requires_2fa: true`, así que hoy ese flag es principalmente informativo para que el frontend decida si *mostrar* una pantalla — no es una barrera real de autenticación. Y más importante para lo que pediste: la línea `$requires2fa = !$isPlatformUser && $user->two_factor_enabled` **excluye explícitamente a las cuentas de plataforma** (super-admin y soporte) de siquiera considerar el 2FA. Es decir, la cuenta que más protección necesita según tu propio criterio es, literalmente en el código, la única que el sistema exime de pedirlo.

**🟡 Hallazgo medio — arquitectura dual para "platform_admin": puede vivir en dos tablas distintas**

El enum `UserRole` (que se usa en varias partes del código, incluyendo la propia tabla `users`) incluye `PLATFORM_ADMIN` y `SUPPORT_AGENT` como valores posibles — es decir, el código todavía contempla que una fila de la tabla `users` (la de las empresas) tenga `role = 'platform_admin'`, además de la tabla nueva y correcta `platform_users`. Confirmé en `TenantScope::apply()` que si un usuario de la tabla `users` tiene `role === 'platform_admin'`, el scope de aislamiento por tenant se desactiva por completo para él (ve todo, de cualquier empresa). El propio `DatabaseSeeder` tiene que borrar manualmente cualquier fila de `users` con el correo de Francisco "para que no colisione con `platform_users` al iniciar sesión con Google" — es decir, el propio código reconoce el riesgo de colisión entre las dos tablas y lo resuelve con un parche puntual en el seeder, no con una regla permanente. Mientras ambos caminos sigan siendo válidos en el código, technically alguien podría (por error de datos, migración vieja, o bug futuro) terminar con una fila en `users` con permisos de plataforma completa, fuera de la tabla que se supone es la única fuente de verdad para eso.

**🟡 Hallazgo medio — confirmado con Francisco: "1 cuenta de Google = 1 empresa", y hay un camino que lo rompe**

Francisco confirmó la regla de negocio: la Landing Page da de alta con Google y cada cuenta solo puede crear una empresa. En el camino normal (usuario ya autenticado con Google) esto se cumple — `SubscriptionController::createPreference()` manda a cualquier usuario que ya tiene `tenant_id` por la rama de "actualizar mi plan", nunca crea una empresa nueva. Pero en la rama sin sesión (`provisionTenant()`, registro clásico por formulario) encontré que si alguien manda el correo de una persona que YA es dueña de otra empresa, el sistema le reasigna esa cuenta a la empresa nueva sin más validación — dejando huérfana la empresa original. Detalle y fix pedido en §50 del contrato.

**Recomendaciones concretas (van al contrato, §47-§50, para que Backend las implemente):**

1. Quitar las contraseñas hardcodeadas del seeder — generarlas aleatoriamente en el primer arranque y forzar cambio de contraseña en el primer login, o (mejor aún) no seedear cuentas de plataforma en absoluto fuera de entornos de desarrollo, y crearlas manualmente en producción por un canal seguro.
2. Completar el flujo de 2FA de verdad (endpoint que valida el código antes de emitir el token, no después), y quitar la excepción que exime a `platform_users` — al revés de como está hoy, estas cuentas deberían ser las que lo exijan de forma obligatoria, no opcional.
3. Eliminar `PLATFORM_ADMIN`/`SUPPORT_AGENT` como valores válidos de `role` en la tabla `users` (que ese enum solo aplique a `platform_users`), para que la separación entre "identidad de empresa" e "identidad de plataforma" sea una regla del esquema, no una convención de código que hay que recordar mantener.
4. Cuenta de respaldo + "botón de pánico" (revocar todos los tokens de plataforma de golpe): esto no requiere borrar y recrear tablas (eso rompería auditoría/trazabilidad e integridad referencial de forma innecesaria) — el equivalente seguro y estándar es un endpoint `POST /platform/security/revoke-all-sessions` que borre todos los `personal_access_tokens` cuyo `tokenable_type` sea `PlatformUser` (Sanctum ya soporta esto de forma nativa, es una sola query), forzando a todos —incluido el atacante si ya entró— a volver a autenticar. Combinado con el 2FA obligatorio del punto 2, es el equivalente funcional de lo que describías, sin destruir datos.

---

## 4. Conexión backend ↔ frontend

El contrato (`docs/BACKEND_INTERFACES.md`) está sano — 43 secciones numeradas, todas implementadas y ninguna inconsistencia de payload detectada al revisar `/sync/state`, `/clock/*` y `/task-*` contra lo que el frontend consume. El único punto de fricción real es el ya descrito arriba: un solo endpoint concentra demasiada responsabilidad y se llama con demasiada frecuencia. No encontré endpoints "fantasma" (que el frontend llame y no existan) ni mismatches de forma de payload nuevos — los que había (§28, §29, §30 de auditorías previas) ya están cerrados.

---

## Qué se corrigió ya en esta misma sesión (frontend)

- **Hallazgo 1 (XSS público) — mitigado del lado del cliente:** nuevo `Frontend/src/lib/sanitizeHtml.ts`, un sanitizador de HTML con lista blanca de etiquetas/atributos (usa `DOMParser` nativo del navegador — no se pudo instalar `dompurify` porque este entorno no tiene acceso de red al registro de npm, `npm install` devuelve 403; queda documentado en el propio archivo para migrar a DOMPurify en cuanto haya acceso). Aplicado en los 4 sitios de riesgo: `WebPublicaOrganizacion.tsx` (contenido de documento público, HTML del asistente de contratos en pantalla, y el mismo HTML al imprimir en una ventana nueva) y `OrgVaultManager.tsx` (editor interno del vault).
- **Nota de defensa en profundidad:** esta mitigación es del lado del cliente — un cliente modificado o una llamada directa a la API podría saltársela. Se manda a Backend (§44 abajo) sanitizar también al guardar, que es la capa que de verdad cierra el hueco para todos los consumidores del dato.

## Qué se manda al contrato para Backend

Ver `docs/BACKEND_INTERFACES.md`, nuevas secciones §44-§49 (§44 sanitización del vault, §45 índices compuestos, §46 optimización de `/sync/state`, §47 quitar credenciales hardcodeadas del seeder, §48 completar 2FA real y obligatorio para `platform_users`, §49 separación estricta de `platform_admin`/`support_agent` fuera de la tabla `users` + endpoint de revocación masiva de sesiones de plataforma).
