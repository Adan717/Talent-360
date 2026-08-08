<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\ClockController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\RecruitmentController;
use App\Http\Controllers\AcademyController;
use App\Http\Controllers\TaskSyncController;
use App\Http\Controllers\TaskAssignmentController;
use App\Http\Controllers\TimeEntryController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\PlatformAdminController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\JobRoleController;
use App\Http\Controllers\CandidateController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\Evaluation360Controller;
use App\Http\Controllers\DashboardMonitorController;
use App\Http\Controllers\InterviewController;
use App\Http\Controllers\JobRoleTemplateController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TaskValidationController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\StoreOpeningController;
use App\Http\Controllers\SillaController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\TeamChatController;
use App\Http\Controllers\IncidentReportController;
use App\Http\Controllers\KeyTransferController;
use App\Http\Controllers\LftSettingController;
use App\Http\Controllers\EmployeePayrollController;
use App\Http\Controllers\ObsidianController;
use App\Http\Controllers\MealReservationController;


Route::prefix('v1')->middleware('device.security')->group(function () {
    // Auth & SaaS Onboarding (Públicas)
    // Merge F3: el anti-fuerza-bruta del login vive EN el controller (R-throttle por cuenta:
    // cuenta sólo intentos FALLIDOS, con backstop de enumeración por IP) — el throttle de ruta
    // estrangulaba también los logins EXITOSOS (el chorro legítimo de la mañana).
    Route::post('/login', [AuthController::class, 'login']);
    // §37: Modo Kiosco — throttle agresivo porque el PIN es corto (4-6 dígitos).
    Route::middleware('throttle:5,1')->post('/clock/kiosk-login', [AuthController::class, 'kioskLogin']);
    // §52: "olvidé mi contraseña" — públicos y con throttle (no revelan si el correo existe).
    Route::middleware('throttle:5,1')->post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::middleware('throttle:5,1')->post('/reset-password', [AuthController::class, 'resetPassword']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login/social', [AuthController::class, 'loginSocial']);
    Route::post('/tenants', [TenantController::class, 'store']); // Checkout / Compra directa
    Route::post('/subscriptions/create-preference', [SubscriptionController::class, 'createPreference']);
    Route::get('/subscriptions/simulated-checkout', [SubscriptionController::class, 'simulatedCheckout']);
    Route::get('/subscriptions/simulated-confirm', [SubscriptionController::class, 'simulatedConfirm']);
    Route::post('/webhooks/mercadopago', [SubscriptionController::class, 'webhook']);
    Route::post('/webhooks/stripe', [\App\Http\Controllers\StripeWebhookController::class, 'handleWebhook']);

    // Pública (Web de Empleos y Onboarding)
    Route::get('/public/vacancies/{slug}', [RecruitmentController::class, 'getPublicVacancies']);
    Route::post('/public/candidates', [CandidateController::class, 'store']);
    Route::post('/public/vacancy-alerts', [RecruitmentController::class, 'storeVacancyAlert']);
    Route::post('/public/onboarding/verify', [OnboardingController::class, 'verifyPin']);
    Route::post('/public/onboarding/complete', [OnboardingController::class, 'completeActivation']);
    Route::get('/public/landing-simulator-settings', [PlatformAdminController::class, 'getPublicSimulatorConfig']);
    
    // Verificación pública de un certificado de la Academia por su folio. Devuelve sólo lo que
    // ya está impreso en el papel; el throttle y los 8 caracteres aleatorios del folio impiden
    // ir probando folios ajenos (lección de AC7).
    Route::middleware('throttle:20,1')->get('/public/certificates/{folio}', [AcademyController::class, 'verifyCertificate']);

    // Pública (Wiki/Organigrama de la Empresa)
    // Academia AC9 (auditoría 2026-08-04): estas 14 rutas viven SIN sesión y no tenían
    // ningún throttle — se podían martillar para adivinar contraseñas del vault, passcodes
    // e ids de puestos, y las tres que llaman a Gemini (copilot/scribe/exam) se pagan con
    // la clave de la empresa o la del .env. Mismo criterio que §52 en login/forgot-password:
    // las puertas de credenciales van a 5/min, la IA a 10/min y la lectura a 60/min.
    Route::middleware('throttle:5,1')->group(function () {
        Route::post('/public/org-vault/{tenantSlug}/login', [ObsidianController::class, 'publicLogin']);
        Route::post('/public/org-vault/{tenantSlug}/register', [ObsidianController::class, 'publicRegister']);
        Route::post('/public/org-vault/{tenantSlug}/validate-passcode', [ObsidianController::class, 'validatePublicPasscode']);
    });

    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/public/org-vault/{tenantSlug}/copilot', [ObsidianController::class, 'copilot']);
        Route::post('/public/org-vault/{tenantSlug}/scribe', [ObsidianController::class, 'scribe']);
        Route::post('/public/org-vault/{tenantSlug}/exam/generate', [ObsidianController::class, 'generateExam']);
        Route::post('/public/org-vault/{tenantSlug}/exam/submit', [ObsidianController::class, 'submitExam']);
    });

    Route::middleware('throttle:60,1')->group(function () {
        Route::post('/public/org-vault/{tenantSlug}/suggestions', [ObsidianController::class, 'getPublicSuggestions']);
        Route::post('/public/org-vault/{tenantSlug}/suggestions/create', [ObsidianController::class, 'createPublicSuggestion']);
        Route::post('/public/org-vault/{tenantSlug}/suggestions/{id}/approve', [ObsidianController::class, 'approvePublicSuggestion']);
        Route::post('/public/org-vault/{tenantSlug}/suggestions/{id}/reject', [ObsidianController::class, 'rejectPublicSuggestion']);
        Route::post('/public/org-vault/{tenantSlug}/progress', [ObsidianController::class, 'recordReadProgress']);
        Route::post('/public/org-vault/{tenantSlug}/exam/status', [ObsidianController::class, 'getExamStatus']);
        // La lectura del documento va al final: su {docSlug?} opcional capturaría a las
        // demás rutas si se declarara antes (Laravel resuelve por orden de registro).
        Route::get('/public/org-vault/{tenantSlug}/{docSlug?}', [ObsidianController::class, 'getPublicDocument']);
    });

    // Plantillas de puestos globales
    Route::get('/job-role-templates', [JobRoleTemplateController::class, 'index']);
    Route::post('/job-role-templates/{id}/import', [JobRoleTemplateController::class, 'import'])->middleware('auth:sanctum');

    // =========================================================================
    // 1. platform_admin (Super Admin del Sistema)
    // =========================================================================
    Route::middleware(['auth:sanctum', 'role:platform_admin'])->group(function () {
        // §53: cumplimiento freemium — revisión desde Plataforma Talent360.
        Route::get('/platform/freemium-compliance', [\App\Http\Controllers\FreemiumComplianceController::class, 'platformIndex']);
        Route::post('/platform/freemium-compliance/{id}/review', [\App\Http\Controllers\FreemiumComplianceController::class, 'review']);

        // §52: configuración de correo a nivel plataforma (remitente real + bienvenida).
        Route::get('/platform/email-settings', [\App\Http\Controllers\EmailSettingsController::class, 'getPlatformConfig']);
        Route::put('/platform/email-settings', [\App\Http\Controllers\EmailSettingsController::class, 'savePlatformConfig']);

        // Platform Stats & Management
        Route::get('/platform/stats', [PlatformAdminController::class, 'getStats']);
        Route::get('/platform/tenants', [PlatformAdminController::class, 'getTenants']);
        Route::get('/platform/tenants/{id}', [PlatformAdminController::class, 'getTenantDetails']);
        Route::post('/platform/tenants/{id}/features', [PlatformAdminController::class, 'updateTenantFeatures']);
        Route::post('/platform/tenants/{id}/toggle-status', [PlatformAdminController::class, 'toggleTenantStatus']);
        Route::post('/platform/tenants/{id}/reset-password', [PlatformAdminController::class, 'resetPassword']);
        Route::post('/platform/tenants/{id}/impersonate', [PlatformAdminController::class, 'impersonateTenant']);
        Route::put('/platform/tenants/{id}/update-profile', [PlatformAdminController::class, 'updateTenantDetails']);
        Route::delete('/platform/tenants/{id}', [PlatformAdminController::class, 'deleteTenant']);
        Route::get('/platform/audits', [PlatformAdminController::class, 'getModuleAudits']);
        
        // Freemium global configurations & Device Security
        Route::get('/platform/freemium-config', [PlatformAdminController::class, 'getFreemiumConfig']);
        Route::post('/platform/freemium-config', [PlatformAdminController::class, 'saveFreemiumConfig']);
        Route::get('/platform/bank-config', [PlatformAdminController::class, 'getBankConfig']);
        Route::post('/platform/bank-config', [PlatformAdminController::class, 'saveBankConfig']);
        Route::get('/platform/landing-simulator-settings', [PlatformAdminController::class, 'getSimulatorConfig']);
        Route::post('/platform/landing-simulator-settings', [PlatformAdminController::class, 'saveSimulatorConfig']);
        // §49: botón de pánico — revocar todas las sesiones de plataforma.
        Route::post('/platform/security/revoke-all-sessions', [PlatformAdminController::class, 'revokeAllPlatformSessions']);
        Route::get('/platform/security/devices', [PlatformAdminController::class, 'getSuspiciousDevices']);
        Route::post('/platform/security/devices/{id}/ban', [PlatformAdminController::class, 'banDevice']);
        Route::post('/platform/security/devices/{id}/unban', [PlatformAdminController::class, 'unbanDevice']);

        // System Health Alerts
        Route::get('/platform/alerts', [PlatformAdminController::class, 'getAlerts']);
        Route::post('/platform/alerts/resolve', [PlatformAdminController::class, 'resolveAlert']);
        Route::get('/platform/security-logs', [PlatformAdminController::class, 'getSaasAuditLogs']);

        // Facturación Global (SaaS Admin)
        Route::get('/platform/billing/invoices', [PlatformAdminController::class, 'getSaaSInvoices']);
        Route::post('/platform/billing/invoice/manual', [PlatformAdminController::class, 'createManualSaaSInvoice']);
        Route::delete('/platform/billing/invoices/{id}', [PlatformAdminController::class, 'deleteSaaSInvoice']);

        // Registros Inconclusos / Pre-registros
        Route::get('/platform/pending-registrations', [PlatformAdminController::class, 'getPendingRegistrations']);
        Route::delete('/platform/pending-registrations/{id}', [PlatformAdminController::class, 'deletePendingRegistration']);

        // Social Grace & Seasonal Promotions Admin
        Route::get('/platform/social-grace-config', [PlatformAdminController::class, 'getSocialGraceConfig']);
        Route::post('/platform/social-grace-config', [PlatformAdminController::class, 'saveSocialGraceConfig']);
        Route::get('/platform/promotions', [PlatformAdminController::class, 'getPromotions']);
        Route::post('/platform/promotions', [PlatformAdminController::class, 'savePromotion']);
        Route::delete('/platform/promotions/{id}', [PlatformAdminController::class, 'deletePromotion']);
        Route::get('/platform/social-claims', [PlatformAdminController::class, 'getSocialClaims']);
        Route::post('/platform/social-claims/{id}/approve', [PlatformAdminController::class, 'approveSocialClaim']);
        Route::post('/platform/social-claims/{id}/reject', [PlatformAdminController::class, 'rejectSocialClaim']);
    });

    // Store & Add-ons (Client / Tenant)
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::get('/store/addons', [\App\Http\Controllers\StoreAddonController::class, 'index']);
        Route::get('/store/promotions/active', [\App\Http\Controllers\StoreAddonController::class, 'activePromotion']);
        Route::post('/store/addons/claim-social-grace', [\App\Http\Controllers\StoreAddonController::class, 'claimSocialGrace']);
        Route::post('/store/addons/subscribe', [\App\Http\Controllers\StoreAddonController::class, 'subscribe']);
    });


    // DB Initialization (QA Simulator helper)
    // ⚠️ Solo platform_admin: initDb hace TRUNCATE de employees/job_roles/permissions
    // sin filtrar por tenant_id (borra estructura organizacional de TODAS las empresas).
    Route::middleware(['auth:sanctum', 'role:platform_admin'])->group(function () {
        if (app()->isLocal() || app()->runningUnitTests() || env('ALLOW_QA_RESET', true)) {
            Route::post('/sync/init', [ClockController::class, 'initDb']);
        }
        Route::post('/sync/purge_archive', [ClockController::class, 'purgeArchive'])->middleware('auth:sanctum');
    });

    Route::post('/support/copilot', [SupportTicketController::class, 'copilot'])->middleware('auth:sanctum');

    // Support and Help Desk tickets for Platform Admins and Support Agents
    Route::middleware(['auth:sanctum', 'role:platform_admin,support_agent'])->group(function () {
        Route::get('/platform/tickets', [SupportTicketController::class, 'index']);
        Route::post('/platform/tickets', [SupportTicketController::class, 'store']);
        Route::get('/platform/tickets/agents', [SupportTicketController::class, 'agents']);
        Route::get('/platform/tickets/{id}', [SupportTicketController::class, 'show']);
        Route::put('/platform/tickets/{id}', [SupportTicketController::class, 'update']);
        Route::post('/platform/tickets/{id}/notes', [SupportTicketController::class, 'addNote']);
        Route::delete('/platform/tickets/{id}', [SupportTicketController::class, 'destroy']);
    });

    // =========================================================================
    // 2. admin/supervisor (Administración y Supervisión de la Empresa/Tenant)
    // =========================================================================
    Route::middleware(['auth:sanctum', 'role:admin,supervisor', 'tenant.active'])->group(function () {
        // Tablero de pendientes del encargado (decisión de producto 2026-08-05: nada bloquea,
        // todo avisa). El aviso es una consulta, no mensajería — ver el controlador.
        Route::get('/supervisor/pendientes', [\App\Http\Controllers\SupervisorPendientesController::class, 'index']);
        Route::post('/supervisor/pendientes/{progressId}/atendido', [\App\Http\Controllers\SupervisorPendientesController::class, 'marcarAtendido']);

        // §53: cumplimiento freemium — el admin del tenant sube su comprobante del mes.
        Route::post('/me/freemium-compliance', [\App\Http\Controllers\FreemiumComplianceController::class, 'submit']);
        Route::get('/me/freemium-compliance', [\App\Http\Controllers\FreemiumComplianceController::class, 'mine']);

        // §52: configuración de correo del tenant (nombre para mostrar + Reply-To) y
        // reenvío de invitación a un colaborador.
        Route::get('/company/email-settings', [\App\Http\Controllers\EmailSettingsController::class, 'getTenantConfig']);
        Route::put('/company/email-settings', [\App\Http\Controllers\EmailSettingsController::class, 'saveTenantConfig']);

        // Sección 2 #1: configuración de nómina y semana laboral por empresa.
        Route::get('/company/payroll-settings', [\App\Http\Controllers\PayrollSettingsController::class, 'get']);
        Route::put('/company/payroll-settings', [\App\Http\Controllers\PayrollSettingsController::class, 'save']);
        Route::post('/employees/{id}/resend-invitation', [EmployeeController::class, 'resendInvitation']);

        // Módulos HR & Empleados (Escritura y Lectura/Deportes)
        Route::post('/employees', [EmployeeController::class, 'store']);
        Route::put('/employees/{id}', [EmployeeController::class, 'update']);
        Route::get('/employees', [EmployeeController::class, 'index']);
        Route::delete('/employees/{id}', [EmployeeController::class, 'destroy']);
        Route::delete('/employees/{id}/force', [EmployeeController::class, 'forceDestroy']);
        // Organigrama Drag & Drop
        Route::patch('/employees/{id}/report-to', [EmployeeController::class, 'updateReportTo']);

        // Módulo Puestos (Job Roles)
        Route::get('/job-roles', [JobRoleController::class, 'index']);
        Route::post('/job-roles', [JobRoleController::class, 'store']);
        Route::get('/job-roles/{id}', [JobRoleController::class, 'show']);
        Route::put('/job-roles/{id}', [JobRoleController::class, 'update']);
        Route::delete('/job-roles/{id}', [JobRoleController::class, 'destroy']);
        
        // Módulo Vacantes & Candidatos (Admin ATS)
        Route::middleware('tenant.module:ats')->group(function () {
            Route::get('/admin/vacancies', [RecruitmentController::class, 'getAdminVacancies']);
            Route::post('/admin/vacancies', [RecruitmentController::class, 'createVacancy']);
            Route::put('/admin/vacancies/{id}', [RecruitmentController::class, 'updateVacancy']);
            Route::get('/admin/tenant/portal-settings', [RecruitmentController::class, 'getPortalSettings']);
            Route::put('/admin/tenant/portal-settings', [RecruitmentController::class, 'updatePortalSettings']);
            
            Route::get('/admin/candidates', [CandidateController::class, 'index']);
            Route::put('/admin/candidates/{id}', [CandidateController::class, 'update']);
            Route::delete('/admin/candidates/{id}', [CandidateController::class, 'destroy']);

            Route::get('/admin/interviews', [InterviewController::class, 'index']);
            Route::post('/admin/interviews', [InterviewController::class, 'store']);
            Route::delete('/admin/interviews/{id}', [InterviewController::class, 'destroy']);
        });

        // Roles & RBAC (Configuración Organizacional)
        Route::put('/sync/roles/{id}', [ClockController::class, 'updateJobRole']);
        Route::post('/sync/rbac', [ClockController::class, 'syncRbac']);
        Route::put('/sync/role-policies/{id}', [ClockController::class, 'updateRolePolicy']);

        // Configuración general del tenant (incluye punctuality_course_id, clockOpConfig,
        // active_modules, etc.) — antes vivía en el grupo de "cualquier colaborador",
        // cualquier empleado podía reescribir configuración de toda la empresa.
        Route::post('/sync/settings', [ClockController::class, 'syncSettings']);

        // Academia (Administración de Cursos LMS)
        Route::middleware('tenant.module:academia')->group(function () {
            Route::post('/academy/courses', [AcademyController::class, 'store']);
            Route::put('/academy/courses/{id}', [AcademyController::class, 'update']);
            Route::delete('/academy/courses/{id}', [AcademyController::class, 'destroy']);
            Route::get('/academy/course-templates', [AcademyController::class, 'getTemplates']);
            Route::post('/academy/course-templates/{id}/import', [AcademyController::class, 'importTemplate']);
        });

        // Archivo Digital (ronda 2026-08): expedientes y manuales corporativos REALES.
        // La pantalla existía pero era un mockup sin backend. Acceso v1 SOLO
        // admin/supervisor (decisión del usuario 2026-08-08); el colaborador no ve su
        // expediente todavía. Los archivos viven en storage privado y solo salen por
        // el endpoint de descarga autenticado.
        Route::middleware('tenant.module:documentos')->group(function () {
            Route::get('/admin/documentos/expedientes', [\App\Http\Controllers\DocumentosController::class, 'expedientes']);
            Route::get('/admin/documentos/expedientes/{employeeId}', [\App\Http\Controllers\DocumentosController::class, 'expediente']);
            Route::post('/admin/documentos/expedientes/{employeeId}', [\App\Http\Controllers\DocumentosController::class, 'subir']);
            Route::get('/admin/documentos/descargar/{docId}', [\App\Http\Controllers\DocumentosController::class, 'descargar']);
            Route::post('/admin/documentos/{docId}/validar', [\App\Http\Controllers\DocumentosController::class, 'validar']);
            Route::delete('/admin/documentos/{docId}', [\App\Http\Controllers\DocumentosController::class, 'eliminar']);
            Route::get('/admin/documentos/corporativos', [\App\Http\Controllers\DocumentosController::class, 'corporativos']);
            Route::post('/admin/documentos/corporativos', [\App\Http\Controllers\DocumentosController::class, 'subirCorporativo']);
            Route::post('/admin/documentos/corporativos/{id}/vincular', [\App\Http\Controllers\DocumentosController::class, 'vincular']);
        });

        // Dashboard Operativo y Monitoreo (Configurable por Puesto vía Matriz de Capacidades)
        Route::get('/admin/dashboard/stats', [DashboardController::class, 'getStats']);
        // Resync 3: el grupo de capacidades §65 del jefe envuelve el dashboard operativo;
        // dentro va también el Kill-Switch de la línea del Reloj.
        Route::middleware(['permission:manage_tasks,approve_operations,manage_store_opening,view_reports'])->group(function () {
            Route::get('/admin/dashboard/monitor', [DashboardMonitorController::class, 'getMonitorData']);
            Route::post('/admin/dashboard/assign-task', [DashboardMonitorController::class, 'assignTask']);
            // Kill-Switch (línea del Reloj): el mando cierra remotamente un turno abierto.
            Route::post('/admin/dashboard/force-close-shift', [DashboardMonitorController::class, 'forceCloseShift']);
            Route::post('/admin/dashboard/create-task', [DashboardMonitorController::class, 'createTask']);
            Route::post('/admin/dashboard/parse-voice-task', [DashboardMonitorController::class, 'parseVoiceTask']);
            Route::post('/admin/dashboard/send-message', [DashboardMonitorController::class, 'sendMessage']);
            Route::post('/admin/dashboard/suggest-work-plan', [DashboardMonitorController::class, 'suggestWorkPlan']);
            Route::post('/admin/dashboard/vendors', [DashboardMonitorController::class, 'storeVendor']);
            Route::post('/admin/dashboard/vendors/{id}/complete', [DashboardMonitorController::class, 'completeVendor']);
        });

        // §39: configuración de la cadena de pedidos (qué puesto en cada etapa) — admin/supervisor
        Route::get('/supply-chain/config', [\App\Http\Controllers\SupplyOrderController::class, 'getConfig']);
        Route::put('/supply-chain/config', [\App\Http\Controllers\SupplyOrderController::class, 'updateConfig']);

        // Ley Federal del Trabajo (LFT) settings
        Route::get('/admin/lft-settings', [LftSettingController::class, 'getSettings']);
        Route::post('/admin/lft-settings', [LftSettingController::class, 'saveSettings']);
        Route::get('/admin/lft-holidays', [LftSettingController::class, 'getHolidays']);
        Route::post('/admin/lft-holidays', [LftSettingController::class, 'saveHoliday']);
        Route::delete('/admin/lft-holidays/{id}', [LftSettingController::class, 'deleteHoliday']);

        // §67.C — incidencias de fichaje (foto omitida por falla de cámara) para el supervisor.
        Route::get('/admin/clock/flagged-punches', [TimeEntryController::class, 'flaggedPunches']);

        // §65: administración de la matriz de capacidades por puesto — INDELEGABLE,
        // solo admin (otorgar permisos es la llave que se queda con el dueño).
        Route::middleware('role:admin')->group(function () {
            Route::get('/admin/permissions/matrix', [\App\Http\Controllers\PermissionMatrixController::class, 'getMatrix']);
            Route::put('/admin/permissions/matrix', [\App\Http\Controllers\PermissionMatrixController::class, 'updateMatrix']);
        });

        // Nómina y Reportes Avanzados
        // §65 (1er bloque sensible migrado a permisos delegables): calcular/cerrar nómina
        // exige la capacidad `manage_payroll`. El admin pasa por bypass; un supervisor ya
        // NO accede por defecto (set conservador) hasta que el admin se lo otorgue en la
        // matriz — es el endurecimiento que pidió Francisco (raíz del §64).
        Route::middleware(['tenant.module:reportes', 'permission:manage_payroll'])->group(function () {
            Route::get('/admin/payroll', [PayrollController::class, 'getPayrollData']);
            Route::get('/admin/reports/export', [PayrollController::class, 'exportReport']);
            Route::post('/admin/payroll/approve', [PayrollController::class, 'approvePayroll']);
            Route::get('/admin/payroll/ticket/{id}', [PayrollController::class, 'printTicket']);
        });

        // Onboarding (Administración e Invitaciones)
        Route::get('/admin/onboarding/settings', [OnboardingController::class, 'getSettings']);
        Route::post('/admin/onboarding/settings', [OnboardingController::class, 'saveSettings']);
        Route::post('/admin/employees/{id}/generate-pin', [OnboardingController::class, 'generateInvitePin']);
        Route::post('/admin/onboarding/send-whatsapp', [OnboardingController::class, 'sendWhatsAppNotifications']);
        Route::post('/admin/onboarding/inject-demo', [OnboardingController::class, 'injectDemoData']);
        // El catálogo del giro con TODOS los campos (momento, estimated_mins): la mitad "de ida"
        // del contrato con el wizard. Ver CONTRATO_API_CATALOGO_2026-08-03.md.
        Route::get('/admin/onboarding/catalogo', [OnboardingController::class, 'catalogo']);
        Route::post('/admin/onboarding/configure-nicho', [OnboardingController::class, 'configureNicho']);

        // Validación de Tareas (Aprobación/Rechazo)
        Route::post('/admin/assignments/{id}/validate', [TaskValidationController::class, 'validateAssignment']);

        // Kiosko: asignar/resetear el PIN de un empleado (admin/supervisor). R54.
        Route::post('/admin/employees/{id}/kiosk-pin', [\App\Http\Controllers\KioskController::class, 'setPin']);

        // Salida Doble Llave (spec:53-55): el supervisor autoriza una salida 'pending_approval'. R75.
        Route::post('/clock/check-out/{id}/authorize', [TimeEntryController::class, 'authorizeCheckout']);

        // Tolerancia con autorización: el admin ve y resuelve las solicitudes de entrada. R56.
        Route::get('/admin/late-authorizations', [\App\Http\Controllers\LateAuthorizationController::class, 'pending']);
        Route::post('/admin/late-authorizations/{id}/resolve', [\App\Http\Controllers\LateAuthorizationController::class, 'resolve']);

        // Botón de Pánico: el mando ve los incidentes ACTIVOS de su tenant. R80.
        Route::get('/admin/panic-incidents', [\App\Http\Controllers\PanicController::class, 'active']);

        // Justificantes de retardo: el admin ve y resuelve las solicitudes. R82.
        Route::get('/admin/late-justifications', [\App\Http\Controllers\LateJustificationController::class, 'pending']);
        Route::post('/admin/late-justifications/{id}/resolve', [\App\Http\Controllers\LateJustificationController::class, 'resolve']);

        // M3: tareas inconclusas flaggeadas por el nocturno — listado para el panel del
        // gerente (los 3 botones resuelven vía POST /task-assignments/{id}/resolve-incomplete,
        // que lleva su propio gate C1).
        Route::get('/admin/task-assignments/flagged-incomplete', [TaskAssignmentController::class, 'flaggedIncomplete']);

        // Contingencias (fuerza mayor): el admin ve y resuelve las declaraciones. R83.
        Route::get('/admin/contingencies', [\App\Http\Controllers\ContingencyController::class, 'pending']);
        Route::post('/admin/contingencies/{id}/resolve', [\App\Http\Controllers\ContingencyController::class, 'resolve']);


        // Respaldos (Exclusivos Pro/Empresas en controlador)
        Route::get('/tenant/backup/export', [BackupController::class, 'export']);
        Route::post('/tenant/backup/import', [BackupController::class, 'import']);
        Route::post('/tenant/backup/google-sync', [BackupController::class, 'googleSync']);

        // Apertura de tienda (Ajustes y Jerarquías)
        Route::get('/store-opening/settings', [StoreOpeningController::class, 'getSettings']);
        Route::put('/store-opening/settings', [StoreOpeningController::class, 'updateSettings']);
        Route::get('/store-opening/assignments', [StoreOpeningController::class, 'getAssignments']);
        Route::post('/store-opening/assignments', [StoreOpeningController::class, 'createAssignment']);
        Route::put('/store-opening/assignments/{id}', [StoreOpeningController::class, 'updateAssignment']);
        Route::delete('/store-opening/assignments/{id}', [StoreOpeningController::class, 'deleteAssignment']);

        // Facturación Electrónica (Tenants)
        // §64: SOLO admin. El grupo padre es role:admin,supervisor, pero el CSD del SAT
        // (sello + llave privada), el RFC/razón social y el timbrado de nómina son la
        // firma fiscal de la empresa — no es un permiso operativo de un supervisor de
        // tienda. Al apilar role:admin sobre el grupo padre, un supervisor pasa el
        // primer filtro pero es rechazado (403) por este; solo admin pasa ambos.
        Route::prefix('billing')->middleware('role:admin')->group(function () {
            Route::post('/tax-data', [BillingController::class, 'updateTaxData']);
            Route::post('/csd', [BillingController::class, 'uploadCsd']);
            Route::get('/invoices', [BillingController::class, 'getInvoices']);
            Route::post('/payroll/timbrar', [BillingController::class, 'timbrarNomina']);
        });

        // Módulo Organizacional Obsidian (Administración y Configuración)
        Route::prefix('org-vault')->group(function () {
            Route::get('/settings', [ObsidianController::class, 'getSettings']);
            Route::post('/settings', [ObsidianController::class, 'saveSettings']);
            // Fijar/resetear los passcodes de la Wiki pública del tenant (seguridad, R-passcodes:
            // antes estaban hardcodeados y eran globales a toda la plataforma).
            Route::post('/passcodes', [ObsidianController::class, 'setVaultPasscodes']);
            Route::post('/sync-local', [ObsidianController::class, 'syncLocal']);
            Route::post('/sync-zip', [ObsidianController::class, 'syncZip']);
            Route::post('/purge', [ObsidianController::class, 'purgeVault']);
            Route::post('/rebuild-cache', [ObsidianController::class, 'rebuildCache']);
            Route::post('/edit', [ObsidianController::class, 'editDocument']);
            Route::get('/suggestions', [ObsidianController::class, 'getSuggestions']);
            Route::post('/suggestions/{id}/approve', [ObsidianController::class, 'approveSuggestion']);
            Route::post('/suggestions/{id}/reject', [ObsidianController::class, 'rejectSuggestion']);
            Route::get('/users', [ObsidianController::class, 'listUsers']);
            Route::post('/users', [ObsidianController::class, 'createUser']);
            Route::put('/users/{id}', [ObsidianController::class, 'updateUser']);
            Route::delete('/users/{id}', [ObsidianController::class, 'deleteUser']);
            Route::get('/progress-summary', [ObsidianController::class, 'progressSummary']);
            Route::post('/reorder', [ObsidianController::class, 'reorderDocuments']);
            Route::get('/matrix', [ObsidianController::class, 'getMatrix']);
            Route::post('/matrix', [ObsidianController::class, 'updateMatrix']);
            Route::get('/admin/exams', [ObsidianController::class, 'getAdminAttempts']);
            Route::post('/admin/exams/{attemptId}/reset', [ObsidianController::class, 'resetAttempt']);
        });
    });

    // Simulador Matrix — Sesiones y Purga (aislado de datos reales por simulation_session_id,
    // ya no es un TRUNCATE global, seguro para admin/supervisor de la propia empresa).
    Route::middleware(['auth:sanctum', 'role:admin,supervisor,platform_admin', 'tenant.active'])->group(function () {
        Route::get('/matrix/session/active', [ClockController::class, 'getActiveSimulatorSession']);
        Route::post('/matrix/session/new', [ClockController::class, 'startNewSimulatorSession']);
        Route::post('/sync/reset', [ClockController::class, 'resetDb']);
    });

    // =========================================================================
    // 3. empleado (General - Operaciones de Colaboradores Autenticados)
    // =========================================================================
    Route::middleware(['auth:sanctum', 'role:empleado,employee,admin,supervisor,platform_admin', 'tenant.active'])->group(function () {
        // Colaborador - Flujo de Aprobación de Asistencias y Nómina
        Route::get('/employee/daily-records', [EmployeePayrollController::class, 'getDailyRecords']);
        Route::post('/employee/daily-records/approve', [EmployeePayrollController::class, 'approveDailyRecord']);
        Route::get('/employee/payroll-weekly', [EmployeePayrollController::class, 'getPayrollWeekly']);
        Route::post('/employee/payroll-weekly/approve', [EmployeePayrollController::class, 'approvePayrollWeekly']);

        // Sesión
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/clock/kiosk-logout', [AuthController::class, 'kioskLogout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/me/update-profile', [AuthController::class, 'updateProfile']);
        Route::post('/me/upload-avatar', [AuthController::class, 'uploadAvatar']);
        Route::post('/me/change-password', [AuthController::class, 'changePassword']);
        Route::post('/me/request-rest-day', [AuthController::class, 'requestRestDay']);
        Route::get('/me/rest-day-requests', [AuthController::class, 'getRestDayRequests']);
        Route::post('/me/fcm-token', [AuthController::class, 'updateFcmToken']);
        Route::post('/me/update-security', [AuthController::class, 'updateSecurity']);
        Route::put('/me/pre-shift-alarm', [AuthController::class, 'updatePreShiftAlarm']);
        // R87 (línea Reloj): variante canónica — persiste en el EXPEDIENTE y devuelve toAuthPayload.
        Route::post('/me/pre-shift-alarm', [AuthController::class, 'preShiftAlarm']);
        // R102: la Academia re-estampa el marcador al aprobar Puntualidad (desbloquea el dial).
        Route::post('/me/punctuality-course-reset', [AuthController::class, 'punctualityCourseReset']);
        Route::put('/me/security-pin', [AuthController::class, 'updateSecurityPin']);
        Route::get('/me/punctuality-status', [AuthController::class, 'punctualityStatus']);
        Route::get('/me/punctuality-streak', [AuthController::class, 'punctualityStreak']);
        Route::get('/user', function (Request $request) {
            return $request->user();
        });

        // Reloj Checador (Registro de asistencia)
        // §16: throttle por usuario autenticado — antes ningún endpoint de fichaje tenía
        // límite de tasa (el único ejemplo en todo el archivo era /login).
        Route::middleware('throttle:20,1')->post('/clock/punch', [TimeEntryController::class, 'punch']);
        // Merge F3: batch offline UNIFICADO (client_stamp/occurred_at del Reloj + HMAC offline_stamp
        // del §16) — vive en PunchBatchController; throttle por USUARIO (Sanctum → key = user id).
        Route::middleware('throttle:30,1')->post('/clock/punch-batch', [\App\Http\Controllers\PunchBatchController::class, 'batch']);
        Route::get('/clock/offline-secret', [TimeEntryController::class, 'offlineSecret']);
        // Protege contra fuerza bruta del PIN de testigos — mismo límite que /login.
        Route::middleware('throttle:5,1')->post('/clock/emergency-open', [StoreOpeningController::class, 'emergencyOpen']);
        // Alias de la línea del Reloj (R86) — mismo dispatcher de doble protocolo.
        Route::middleware('throttle:5,1')->post('/store-opening/emergency-open', [StoreOpeningController::class, 'emergencyOpen']);
        Route::post('/clock/declare-contingency', [TimeEntryController::class, 'declareContingency']);
        Route::post('/clock/meal-photo', [TimeEntryController::class, 'uploadMealPhoto']);

        // Monedero Digital y Recompensas (Wallet) — merge: estaba en el grupo ADMIN, así que el
        // EMPLEADO recibía 403 al abrir su propio monedero en TaskRunner (el catch lo silenciaba y
        // siempre mostraba 0 monedas / nivel 1, aunque el sistema sí le estuviera pagando). Ambos
        // endpoints devuelven EXCLUSIVAMENTE los datos del usuario autenticado (auth()->user()),
        // nunca los de otro, así que exponerlos al colaborador no abre nada.
        Route::get('/wallet/balance', [\App\Http\Controllers\UserWalletController::class, 'getBalance']);
        Route::get('/wallet/transactions', [\App\Http\Controllers\UserWalletController::class, 'getTransactions']);

        // Kiosko: ponche por PIN desde la tableta compartida. La sesión (cualquier usuario del
        // tenant) sólo ancla el TENANT; el PIN identifica al empleado y el enforcement es server-side
        // (can_clock_in + rate-limit). R54.
        Route::post('/kiosk/punch', [\App\Http\Controllers\KioskController::class, 'punch']);

        // Tolerancia con autorización: el empleado solicita autorización cuando el Retardo Extremo
        // le bloquea la entrada (R14). Una aprobación levanta el bloqueo server-side. R56.
        Route::post('/clock/request-late-authorization', [\App\Http\Controllers\LateAuthorizationController::class, 'request']);

        // Botón de Pánico (R80): el empleado declara una emergencia (categoría + geo) → se persiste y
        // alerta a los mandos del tenant.
        Route::post('/clock/panic', [\App\Http\Controllers\PanicController::class, 'report']);
        Route::get('/clock/panic/mine', [\App\Http\Controllers\PanicController::class, 'mine']);
        Route::post('/clock/panic/resolve-mine', [\App\Http\Controllers\PanicController::class, 'resolveMine']);
        Route::post('/clock/panic/{id}/resolve', [\App\Http\Controllers\PanicController::class, 'resolve']);

        // Laborar Horas Extras / Feriado (R81): autorización del supervisor server-side.
        Route::post('/clock/authorize-overtime', [\App\Http\Controllers\OvertimeAuthorizationController::class, 'grant']);
        Route::get('/clock/overtime/today', [\App\Http\Controllers\OvertimeAuthorizationController::class, 'today']);
        // R92: salida anticipada — espejo del patrón R81.
        Route::post('/clock/authorize-early-departure', [\App\Http\Controllers\EarlyDepartureAuthorizationController::class, 'grant']);

        // Justificante de retardo (R82): el empleado justifica el retardo de HOY.
        Route::post('/clock/request-late-justification', [\App\Http\Controllers\LateJustificationController::class, 'request']);

        // Apertura de tienda (Operativa del Reloj Checador)
        Route::get('/features/company', [StoreOpeningController::class, 'getCompanyFeatures']);
        Route::get('/store-opening/today', [StoreOpeningController::class, 'getTodayStatus']);
        Route::post('/store-opening/open-and-clock-in', [StoreOpeningController::class, 'openStoreAndClockIn']);
        Route::post('/store-opening/report-absence', [StoreOpeningController::class, 'reportAbsence']);
        Route::post('/store-opening/report-late', [StoreOpeningController::class, 'reportLate']);
        Route::post('/store-opening/report-store-still-closed', [StoreOpeningController::class, 'reportStoreStillClosed']);
        // Boton "Cerrar sucursal" (P1-P3): registra quien/cuando y reparte trigger='cierre'.
        // NO bloquea nada; la autorizacion (responsable o mando) vive en el service.
        Route::post('/store-opening/close', [StoreOpeningController::class, 'closeStore']);
        Route::post('/store-opening/closing-checklist', [StoreOpeningController::class, 'closingChecklist']);
        Route::post('/clock/pase-lista/ratings', [StoreOpeningController::class, 'submitPaseListaRatings']);
        Route::post('/clock/door-notice', [StoreOpeningController::class, 'doorNotice']);

        // §25: Ley Silla — solicitud/aprobación/aforo
        Route::post('/clock/silla/request', [SillaController::class, 'request']);
        Route::post('/clock/silla/{id}/approve', [SillaController::class, 'approve']);
        Route::post('/clock/silla/{id}/reject', [SillaController::class, 'reject']);
        Route::get('/clock/silla/status', [SillaController::class, 'status']);
        Route::get('/clock/silla/requests', [SillaController::class, 'listRequests']);

        // Sincronización de tareas y checklists
        Route::post('/sync/tasks', [TaskSyncController::class, 'sync']);
        Route::get('/task-assignments', [TaskAssignmentController::class, 'index']);
        // §31 Tarea al vuelo (P5-P7): solo mandos, nunca a si mismo, firma forzada. El 403 de
        // rol vive en el controller (esta en el grupo comun para que el FE del Monitor la use).
        Route::post('/task-assignments/al-vuelo', [TaskAssignmentController::class, 'alVuelo']);
        Route::put('/task-assignments/{id}', [TaskAssignmentController::class, 'update']);
        Route::post('/task-assignments/{id}/omit', [TaskAssignmentController::class, 'omit']);
        Route::post('/task-assignments/{id}/ai-validate', [TaskAssignmentController::class, 'aiValidate']);
        // A2 (auditoría 2026-07-27): el PIN es de 4-6 dígitos — sin throttle un empleado podía
        // iterar supervisor_user_id+PIN hasta acertar y auto-validarse tareas con pago. 10/min
        // deja validar una cola de tareas en revisión, pero vuelve impráctica la fuerza bruta
        // (~17 h continuas para barrer 10^4 combinaciones, con lockout por minuto).
        Route::middleware('throttle:10,1')->post('/task-assignments/{id}/validate-with-pin', [TaskAssignmentController::class, 'validateWithPin']);
        // Sección 2 #2: los 3 botones del gerente para tareas inconclusas por apagón.
        Route::post('/task-assignments/{id}/resolve-incomplete', [TaskAssignmentController::class, 'resolveIncomplete']);

        // §39: cadena de pedidos (compras→producción→ventas) — operativa abierta a
        // cualquier rol autenticado, ya que los puestos operativos son quienes avanzan
        // las etapas; el scoping por tenant protege el aislamiento entre empresas.
        Route::get('/supply-orders', [\App\Http\Controllers\SupplyOrderController::class, 'index']);
        Route::post('/supply-orders', [\App\Http\Controllers\SupplyOrderController::class, 'store']);
        Route::patch('/supply-orders/{id}/advance-stage', [\App\Http\Controllers\SupplyOrderController::class, 'advanceStage']);

        // =====================================================================
        // PILAR III: Reservas de Comedor y Control de Aforo
        // =====================================================================
        Route::prefix('meal-reservations')->group(function () {
            Route::get('/slots', [MealReservationController::class, 'getSlots']);    // Bloques disponibles del día
            Route::post('/', [MealReservationController::class, 'store']);           // Crear reserva
            Route::delete('/{id}', [MealReservationController::class, 'cancel']);   // Cancelar reserva
            Route::post('/{id}/swap', [MealReservationController::class, 'swap']); // Intercambio con compañero
            // §24: modo 'queue', convive con la selección libre de arriba
            Route::get('/queue', [MealReservationController::class, 'getQueue']);
            Route::post('/queue/pick', [MealReservationController::class, 'pickFromQueue']);
        });

        // Sincronización del cliente local y registros offline (Kiosko)
        if (app()->isLocal() || app()->runningUnitTests() || env('ALLOW_QA_RESET', true)) {
            Route::post('/sync/reset_day', [ClockController::class, 'resetDay']);
        }
        Route::get('/sync/state', [ClockController::class, 'getState']);
        Route::post('/sync/clock', [ClockController::class, 'sync']);
        Route::post('/sync/store', [StoreController::class, 'sync']);
        Route::post('/sync/store_log', [ClockController::class, 'syncStoreLog']);
        Route::post('/sync/contingency', [ClockController::class, 'syncContingency']);
        Route::post('/sync/message', [ClockController::class, 'syncMessage']);
        Route::post('/sync/audit_log', [ClockController::class, 'syncAuditLog']);
        Route::post('/sync/supervisor/generate-qr', [ClockController::class, 'generateSupervisorQR']);
        Route::post('/sync/supervisor/validate-qr', [ClockController::class, 'validateSupervisorQR']);

        // Academia (Visualización de Cursos LMS)
        Route::middleware('tenant.module:academia')->group(function () {
            Route::get('/academy/courses', [AcademyController::class, 'getCourses']);
            Route::get('/academy/courses/{id}', [AcademyController::class, 'getCourse']);
            Route::post('/academy/courses/{id}/progress', [AcademyController::class, 'updateProgress']);
            Route::post('/academy/progress', [AcademyController::class, 'saveProgress']);
            // AC3: el examen se califica en el servidor. Es la única vía para completar un
            // curso que tenga evaluación.
            Route::post('/academy/courses/{id}/quiz-attempt', [AcademyController::class, 'submitQuizAttempt']);
            // Certificados del colaborador, con su folio real (familia H23).
            Route::get('/academy/certificates', [AcademyController::class, 'myCertificates']);
            // Banner de inducción pendiente en la app del colaborador: cuántos días le quedan.
            Route::get('/academy/mi-induccion', [AcademyController::class, 'miInduccionPendiente']);
        });

        // Evaluación 360°
        Route::get('/clock/peers', [Evaluation360Controller::class, 'getPeers']);
        Route::post('/clock/evaluations', [Evaluation360Controller::class, 'store']);

        // Chat Interno de Equipo (Mensajes temporales de 7 días)
        Route::get('/chat/messages', [TeamChatController::class, 'index']);
        Route::post('/chat/messages', [TeamChatController::class, 'store']);

        // El Soplón (Denuncias de compañeros)
        Route::post('/reports/employee', [IncidentReportController::class, 'storeIncident']);
        Route::get('/reports/employee', [IncidentReportController::class, 'indexIncidents']);

        // Buzón Anónimo de RRHH (Feedback)
        Route::post('/anonymous-feedback', [IncidentReportController::class, 'storeFeedback']);
        Route::get('/anonymous-feedback', [IncidentReportController::class, 'indexFeedback']);

        // Alerta de Abandono (Simular Desconexión)
        Route::post('/security/abandonment', [IncidentReportController::class, 'reportAbandonment']);

        // Transferencia de Cierre / Custodia de Llaves
        Route::post('/key-transfers', [KeyTransferController::class, 'store']);
        Route::get('/key-transfers/pending', [KeyTransferController::class, 'pending']);
        Route::post('/key-transfers/{id}/respond', [KeyTransferController::class, 'respond']);

        // Módulo Organizacional Obsidian (Lectura y Sugerencias de Empleados)
        Route::prefix('org-vault')->group(function () {
            Route::get('/index', [ObsidianController::class, 'getDocuments']);
            Route::get('/doc/{slug}', [ObsidianController::class, 'getDocument']);
            Route::post('/suggest', [ObsidianController::class, 'suggestChange']);
        });
    });
});

// Pública (Sin v1 prefix para monitoreo y health checks)
Route::get('/health', function () {
    try {
        \DB::connection()->getPdo();
        $dbStatus = 'ok';
    } catch (\Exception $e) {
        $dbStatus = 'fail: ' . $e->getMessage();
    }

    return response()->json([
        'status' => 'ok',
        'db' => $dbStatus,
        'version' => '1.0.0',
        'timestamp' => now()->toIso8601String()
    ]);
});
