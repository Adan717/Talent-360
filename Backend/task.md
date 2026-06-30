# ✅ Lista de Tareas — Corrección de Hallazgos Talent360

> Las tareas están ordenadas por severidad: 🔴 Crítico → 🟡 Importante → 🟠 Menor.
> Todas las tareas han sido completadas con éxito.

---

## 🔴 CRÍTICOS — Seguridad y Estabilidad

### HALLAZGO #1 — Sin control de roles en las rutas del API
- `[x]` **H1.1** — Crear `RoleMiddleware.php` en `app/Http/Middleware/` que valide el rol del usuario autenticado
- `[x]` **H1.2** — Registrar el middleware en `bootstrap/app.php` como alias `role`
- `[x]` **H1.3** — Proteger rutas de Platform Admin: envolver `/platform/stats` y `/platform/tenants` con `middleware(['auth:sanctum', 'role:platform_admin'])`
- `[x]` **H1.4** — Proteger rutas de Admin/Supervisor: `employees`, `job-roles`, `admin/vacancies`, `admin/payroll`, `admin/candidates`
- `[x]` **H1.5** — Proteger rutas exclusivas de empleado: `clock/punch`, `clock/evaluations`, `clock/peers`
- `[x]` **H1.6** — Verificar que ningún empleado pueda acceder a endpoints de borrado o reportes

---

### HALLAZGO #2 — TenantScope con fallback peligroso (fuga de datos cross-tenant)
- `[x]` **H2.1** — Abrir `app/Scopes/TenantScope.php` y reemplazar el fallback `tenant_id = 1` por `whereRaw('1 = 0')` (devuelve colección vacía)
- `[x]` **H2.2** — Probar que los endpoints públicos (vacantes) funcionan sin autenticación con el nuevo scope

---

### HALLAZGO #3 — PlatformAdminController no verifica que el usuario sea Platform Admin
- `[x]` **H3.1** — Agregar verificación de rol dentro de `getStats()` and `getTenants()` (doble seguro además del middleware)
- `[x]` **H3.2** — Asegurarse de que el middleware del H1.3 quede aplicado antes de llegar al controlador

---

### HALLAZGO #4 — `ClockController::resetDb()` usa sintaxis SQLite y debe bloquearse en producción
- `[x]` **H4.1** — Reemplazar `PRAGMA foreign_keys=OFF` (SQLite) con la sintaxis correcta de PostgreSQL: `SET session_replication_role = replica;` (o simplemente eliminar esa línea)
- `[x]` **H4.2** — Agregar guard al inicio del método: `if (app()->isProduction()) { return response()->json(['error' => 'Forbidden in production'], 403); }`
- `[x]` **H4.3** — Aplicar el mismo guard a `initDb()` and `resetDay()`
- `[x]` **H4.4** — Marcar estos métodos con comentario `// DEV-ONLY` y considerar moverlos a comandos Artisan

---

## 🟡 IMPORTANTES — Bugs Funcionales

### HALLAZGO #5 — Typo en el rol del admin: `admin_seo` debería ser `admin`
- `[x]` **H5.1** — Crear archivo `app/Enums/UserRole.php` con constantes: `PLATFORM_ADMIN`, `ADMIN`, `SUPERVISOR`, `EMPLOYEE`
- `[x]` **H5.2** — Cambiar `'role' => 'admin_seo'` por `'role' => 'admin'` en `TenantController::store()`
- `[x]` **H5.3** — Actualizar `App.tsx`: la condición de redirección que verifica roles debe usar los valores correctos y consistentes
- `[x]` **H5.4** — Revisar que el `Login.tsx` y el `useAppStore.ts` también usen los mismos valores de rol

---

### HALLAZGO #6 — TenantSeeder asigna datos demo al Tenant #1 en vez del nuevo tenant
- `[x]` **H6.1** — En `TenantController::store()`, agregar `Auth::login($admin)` ANTES de llamar a `$seeder->run()`
- `[x]` **H6.2** — Agregar `Auth::logout()` inmediatamente DESPUÉS del seeder, antes de generar el token
- `[x]` **H6.3** — Probar el registro de una segunda empresa y verificar que sus datos demo no aparecen en la primera
- `[x]` **Componente D: Verificación y Re-auditoría**
  - `[x]` Probar el flujo de fichaje, comida y contingencia
  - `[x]` Probar el aislamiento de la Academia entre empresas distintas
  - `[x]` Generar un reporte de auditoría actualizado módulo por módulo

---

### HALLAZGO #7 — Nómina con penalizaciones hardcodeadas y salario fallback inventado
- `[x]` **H7.1** — Crear migración para tabla `payroll_policies` con campos: `late_penalty`, `absence_penalty`, `period_type` (semanal/quincenal/mensual), `tenant_id`
- `[x]` **H7.2** — Modificar `PayrollController::getPayrollData()` para leer las penalizaciones desde `payroll_policies` en vez de números fijos
- `[x]` **H7.3** — Agregar campo `salary` al formulario de alta de empleado en el frontend (`RecursosHumanos.tsx`)
- `[x]` **H7.4** — Eliminar el fallback `$baseSalary = 3000` — si el empleado no tiene salario configurado, mostrarlo como `null` con una alerta visual

---

### HALLAZGO #8 — Token de autenticación en localStorage (vulnerable a XSS)
- `[x]` **H8.1** — Configurar Laravel Sanctum para usar `stateful domains` y cookies HttpOnly
- `[x]` **H8.2** — Modificar `Login.tsx` para NO guardar el token en `localStorage` (que Sanctum maneje la cookie automáticamente)
- `[x]` **H8.3** — Agregar rate limiting al endpoint de login: `Route::middleware('throttle:5,1')->post('/login', ...)` en `api.php`
- `[x]` **H8.4** — Como medida mínima inmediata: agregar `secure` y `samesite` flags a las cookies de sesión en `config/session.php`

---

### HALLAZGO #9 — Enrutamiento con query params en vez de React Router real
- `[x]` **H9.1** — Instalar React Router v6: `npm install react-router-dom` en `/Frontend`
- `[x]` **H9.2** — Crear estructura de rutas en `App.tsx`: `/login`, `/register/:plan`, `/vacantes/:slug`, `/app/*`, `/empleado`, `/superadmin`
- `[x]` **H9.3** — Crear componente `ProtectedRoute.tsx` que verifique rol antes de renderizar
- `[x]` **H9.4** — Reemplazar todos los `window.location.href = '/?platform=true'` por `navigate('/superadmin')`
- `[x]` **H9.5** — Eliminar los detectores de query params en `App.tsx` (líneas 61-65)

---

## 🟠 MENORES — Mejoras Funcionales

### HALLAZGO #10 — Módulos con datos mock hardcodeados (no conectados al backend)
- `[x]` **H10.1** — Eliminar datos mock del `useAppStore.ts`: `saasTenants` hardcodeados, `currentTier: 'enterprise'` fijo
- `[x]` **H10.2** — Conectar `SaaSPlatformAdmin.tsx` al endpoint real `GET /platform/tenants`
- `[x]` **H10.3** — Conectar `DashboardTalent360.tsx` al endpoint real `GET /admin/dashboard/stats`
- `[x]` **H10.4** — Conectar `GestorAcademia.tsx` al CRUD real de `/academy/courses`
- `[x]` **H10.5** — Conectar `ReportesManager.tsx` al endpoint real `GET /admin/payroll`
- `[x]` **H10.6** — Conectar `WebPublica.tsx` a `GET /public/vacancies/:slug` con el slug real del tenant

---

### HALLAZGO #11 — La Landing Page no tiene pasarela de pago real (solo hace `alert()`)
- `[x]` **H11.1** — Elegir pasarela de pago: Stripe (recomendado) o MercadoPago/Conekta
- `[x]` **H11.2** — Instalar SDK en backend: `composer require stripe/stripe-php` (o equivalente)
- `[x]` **H11.3** — Crear `SubscriptionController.php` con endpoints: `POST /subscriptions/checkout-session` y `POST /webhooks/payment`
- `[x]` **H11.4** — Agregar campos de suscripción a la tabla `tenants`: `stripe_customer_id`, `subscription_status`, `trial_ends_at`, `current_period_end`
- `[x]` **H11.5** — Reemplazar el formulario de checkout en `SaaSLandingPage.tsx` con Stripe Elements o SDK de la pasarela elegida
- `[x]` **H11.6** — Conectar el botón "Mejorar Plan" del modal de upsell al flujo de pago real (eliminar el `alert()`)

---

### HALLAZGO #12 — No existe catálogo global de puestos predefinidos
- `[x]` **H12.1** — Crear migración para tabla `job_role_templates` (sin `tenant_id`): `name`, `area`, `industry`, `default_config JSON`
- `[x]` **H12.2** — Poblar la tabla con ~30 puestos genéricos cubriendo industrias: retail, restaurante, manufactura, oficina
- `[x]` **H12.3** — Crear `JobRoleTemplateController.php` con: `GET /job-role-templates?industry=X` y `POST /job-role-templates/{id}/import`
- `[x]` **H12.4** — Crear UI en el módulo de RRHH: sección "Importar Plantilla de Puestos" que muestre el catálogo global con filtro por industria
- `[x]` **H12.5** — Integrar la importación en el flujo de onboarding (`CompanyOnboardingSettings.tsx`) como paso sugerido post-registro

---

### HALLAZGO #13 — Portal de vacantes público usa `tenant_id` por query string (inseguro e incómodo)
- `[x]` **H13.1** — Agregar campo `public_slug` a la tabla `tenants` (migración + único)
- `[x]` **H13.2** — Agregar campos de branding al tenant: `brand_color`, `logo_url`, `public_portal_enabled`
- `[x]` **H13.3** — Modificar `RecruitmentController::getPublicVacancies()` para buscar el tenant por `slug` en vez de `tenant_id`
- `[x]` **H13.4** — Actualizar la ruta pública en `api.php`: `GET /public/vacancies/{slug}` (en vez de query param)
- `[x]` **H13.5** — Actualizar `WebPublica.tsx` para leer el slug desde la URL/ruta y hacer la petición con el slug
- `[x]` **H13.6** — Crear tabla `vacancy_alerts` y endpoint `POST /public/vacancy-alerts` para notificaciones de "avísame cuando haya una vacante"

---

### HALLAZGO #14 — `@ts-nocheck` en `App.tsx` (TypeScript desactivado en el archivo raíz)
- `[x]` **H14.1** — Eliminar la línea `// @ts-nocheck` del inicio de `App.tsx`
- `[x]` **H14.2** — Resolver los errores de tipo que aparecen: principalmente tipar `currentUser`, `upsellModule`, y el `settingsTab`
- `[x]` **H14.3** — Crear interfaces TypeScript en `src/types/index.ts`: `User`, `Tenant`, `Module`, `AppState`
- `[x]` **H14.4** — Reemplazar todos los `any` en `useAppStore.ts` por los tipos definidos

---

## 🔵 MEJORAS ADICIONALES — Para llegar al 9.5/10

### PLUS #A — WebSockets para el Reloj Checador (Evento `StoreOpened`)
- `[x]` **PA.1** — Instalar Laravel Reverb: `php artisan install:broadcasting`
- `[x]` **PA.2** — Crear evento `StoreOpened` que transmita en canal `tenant.{id}.clock`
- `[x]` **PA.3** — Instalar `laravel-echo` en Frontend: `npm install laravel-echo pusher-js`
- `[x]` **PA.4** — Conectar el componente `RelojChecador.tsx` a los eventos de WebSocket

### PLUS #B — Notificaciones Push (Firebase)
- `[x]` **PB.1** — Agregar campo `fcm_token` a la tabla `users`
- `[x]` **PB.2** — Instalar `kreait/laravel-firebase`: `composer require kreait/laravel-firebase`
- `[x]` **PB.3** — Crear `NotificationService.php` con métodos: `sendToUser()`, `sendToRole()`, `sendBroadcast()`
- `[x]` **PB.4** — Implementar captura de FCM token en la PWA del empleado

### PLUS #C — Exportación de Reportes (Excel/PDF)
- `[x]` **PC.1** — Instalar: `composer require maatwebsite/excel` y `barryvdh/laravel-dompdf`
- `[x]` **PC.2** — Crear endpoint `GET /admin/reports/export?format=xlsx&period=2026-06`
- `[x]` **PC.3** — Agregar botón "Exportar" en `ReportesManager.tsx`

### PLUS #D — Health Check y Monitoreo
- `[x]` **PD.1** — Agregar endpoint `GET /api/health` que verifique BD, caché y servicios externos
- `[x]` **PD.2** — Agregar API versioning: prefijo `v1` a todas las rutas actuales

---

## 🟢 FASE DE ACTUALIZACIÓN — Renovación del Centro de Control Global

### Búsqueda, Filtros y Acciones de Inquilino
- `[x]` **SaaS.1** — Crear migración para campos de suspensión (`suspension_reason` y `suspended_at`) en `tenants`
- `[x]` **SaaS.2** — Crear middleware `CheckTenantActive` para bloquear peticiones de inquilinos inactivos/suspendidos
- `[x]` **SaaS.3** — Registrar alias `tenant.active` en `bootstrap/app.php` y aplicarlo a rutas de empresa en `api.php`
- `[x]` **SaaS.4** — Implementar endpoints en `PlatformAdminController.php` para obtener detalles, toggle de suspensión, reseteo de password e impersonación de inquilino
- `[x]` **SaaS.5** — Actualizar login en `AuthController.php` para bloquear accesos de empresas inactivas
- `[x]` **SaaS.6** — Renovar la tabla de inquilinos en `SaaSPlatformAdmin.tsx` para agregar buscador por nombre/subdominio y filtros por plan/estado en tiempo real
- `[x]` **SaaS.7** — Crear el panel lateral Slide-over de detalles de empresa en `SaaSPlatformAdmin.tsx` con barras de progreso de recursos consumidos, información de facturación y restablecimiento de contraseña integrado
- `[x]` **SaaS.8** — Integrar botón de retorno a Super Admin en el header principal de `App.tsx` para finalizar sesiones impersonadas
- `[x]` **SaaS.9** — Crear y pasar con éxito las pruebas de integración en `tests/Feature/TenantSuspensionTest.php`

---

## 📊 Resumen de Progreso

| Categoría | Total | Completadas | Pendientes |
|-----------|-------|-------------|------------|
| 🔴 Críticas (H1-H4) | 13 | 13 | 0 |
| 🟡 Importantes (H5-H9) | 22 | 22 | 0 |
| 🟠 Menores (H10-H14) | 30 | 30 | 0 |
| 🔵 Mejoras Extra (A-D) | 14 | 14 | 0 |
| 🟢 Consola Global & SaaS | 9 | 9 | 0 |
| **TOTAL** | **88** | **88** | **0** |
