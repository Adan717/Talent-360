<?php

namespace App\Support;

/**
 * §65: catálogo único de capacidades delegables por puesto.
 *
 * Deliberadamente ~12 capacidades gruesas (no una por endpoint): si son demasiado finas
 * nadie las configura bien. Fuente de verdad compartida por la migración de siembra, la
 * inicialización de tenants nuevos y PermissionMatrixController.
 */
class PermissionCatalog
{
    /**
     * Capacidades delegables (name => descripción). El admin dueño las tiene todas por
     * bypass en PermissionMiddleware; se pueden otorgar a cualquier puesto vía la matriz.
     */
    public const DELEGABLE = [
        // Operación
        'manage_employees'      => 'Alta, baja y edición de colaboradores; generar PIN/invitaciones',
        'manage_schedules'      => 'Horarios, turnos, tolerancias, días de descanso',
        'manage_tasks'          => 'Tareas, rutinas, asignaciones, plan del día',
        'manage_store_opening'  => 'Aperturas, control de llaves, transferencia de cierre',
        'approve_operations'    => 'Aprobar Ley Silla, eventualidades, permisos, horas extra, validar tareas',
        'manage_academy'        => 'Cursos y contenido de Academia',
        'manage_recruitment'    => 'Vacantes, candidatos, ATS',
        'manage_documents'      => 'Archivo Digital / expedientes (documentos, no datos salariales)',
        'manage_org_chart'      => 'Organigrama, SOP y vault',
        'view_reports'          => 'Reportes y analítica operativa',
        // Datos sensibles (separados a propósito de lo operativo)
        'view_salaries'         => 'Ver salarios y contratos del personal',
        'manage_payroll'        => 'Calcular y cerrar nómina (sin timbrar ante el SAT)',
        // Aislada a proposito (decision del dueno, 2026-08-24): corregir un fichaje mueve la
        // evidencia con la que la empresa se defiende en un juicio laboral. NO viene incluida en
        // `admin` por ser admin: se otorga a quien responde por ella, y queda registrada.
        'manage_punch_corrections' => 'Corregir fichajes de asistencia (anular y sustituir, con motivo y aviso al colaborador)',
    ];

    /**
     * Capacidades INDELEGABLES — exclusivas del administrador dueño. Nunca se siembran en
     * `permissions` como otorgables ni se aceptan en la matriz. Se listan solo para que la
     * UI las muestre bloqueadas y para rechazarlas explícitamente si alguien las manda.
     *
     * En rutas se protegen con `role:admin` (no con `permission:`), de modo que ningún
     * permiso pueda otorgarlas aunque el admin quiera:
     *  - fiscal/CSD/timbrado (§64), plan y suscripción, eliminar empresa, y
     *  - manage_permissions (otorgar permisos): si se pudiera delegar, un delegado se
     *    auto-asignaría todo lo demás y el modelo entero dejaría de servir.
     */
    public const INDELEGABLE = [
        'manage_billing'      => 'Identidad fiscal, sello digital del SAT y timbrado de nómina',
        'manage_subscription' => 'Plan, datos de pago y cancelación de la suscripción',
        'delete_company'      => 'Eliminar la empresa',
        'manage_permissions'  => 'Otorgar y revocar permisos a los puestos',
    ];

    /**
     * Set conservador por defecto para puestos de nivel supervisor (users.role = supervisor).
     * "Conserva — hoy tienen de más, no de menos": se les deja lo operativo, NO nómina ni
     * salarios (raíz del §64).
     */
    public const SUPERVISOR_DEFAULTS = [
        'manage_tasks',
        'approve_operations',
        'manage_store_opening',
        'view_reports',
    ];

    /** Solo los nombres de las capacidades delegables. */
    public static function delegableNames(): array
    {
        return array_keys(self::DELEGABLE);
    }
}
