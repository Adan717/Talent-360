<?php

namespace App\Http\Controllers;

use App\Http\Resources\VacancyPublicResource;
use Illuminate\Http\Request;
use App\Models\Vacancy;
use App\Models\JobRole;
use App\Models\Tenant;

class RecruitmentController extends Controller
{
    /** Valores por defecto de la personalización del portal público. */
    private const AJUSTES_PORTAL = [
        'header_title' => '',
        'header_subtitle' => '',
        'header_bg_url' => '',
        'footer_text' => '',
        'contact_email' => '',
        'contact_phone' => '',
        'social_facebook' => '',
        'social_instagram' => '',
        'social_linkedin' => '',
        // Vídeo de bienvenida del portal. Vacío = no se muestra ningún modal (antes salía
        // SIEMPRE, y con un enlace de YouTube en duro sobre la marca del cliente).
        'welcome_video_url' => '',
    ];

    private static function ajustesDelPortal($json): array
    {
        $guardados = $json ? json_decode($json, true) : [];

        return array_merge(static::AJUSTES_PORTAL, is_array($guardados) ? $guardados : []);
    }

    /**
     * Empresa dueña de una dirección pública, de forma DETERMINISTA.
     *
     * Se resolvía con `where('public_slug',$slug)->orWhere('subdomain',$slug)` sin desempate:
     * como la validación de `public_slug` sólo mira su propia columna, una empresa podía fijarse
     * como dirección pública el SUBDOMINIO de otra y quedarse con su bolsa de trabajo (y con las
     * hojas de vida que llegaran). Ahora manda `public_slug`, que es lo que el dueño configura.
     */
    private static function empresaDelSlug(string $slug): ?Tenant
    {
        return Tenant::withoutGlobalScopes()->where('public_slug', $slug)->first()
            ?? Tenant::withoutGlobalScopes()->where('subdomain', $slug)->first();
    }

    // ==========================================
    // ENDPOINTS PÚBLICOS (Web de Empleos)
    // ==========================================
    public function getPublicVacancies(Request $request, $slug)
    {
        $request->merge(['slug' => $slug]);
        $request->validate([
            'slug' => 'required|string',
        ]);

        $tenant = static::empresaDelSlug($slug);
        abort_if(!$tenant, 404);
        $tenantId = $tenant->id;

        // El interruptor "Portal público" no apagaba nada: la API seguía entregando las vacantes
        // (con sueldos) y los datos de contacto, y el único que lo miraba era el navegador.
        if (!($tenant->public_portal_enabled ?? true)) {
            return response()->json([
                'tenant' => [
                    'name' => $tenant->name,
                    'logo_url' => $tenant->logo_url ?: '',
                    'brand_color' => $tenant->brand_color ?: '#3b82f6',
                    'public_portal_enabled' => false,
                    'custom_settings' => static::ajustesDelPortal(null),
                ],
                'vacancies' => [],
            ]);
        }

        $vacancies = Vacancy::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where(function ($q) {
                $q->where('is_hidden', false)->orWhereNull('is_hidden');
            })
            // El interruptor del gestor de vacantes escribe `is_active`, y aquí NO se miraba:
            // apagar una vacante la dejaba publicada y recibiendo postulaciones para siempre.
            // (`withoutGlobalScopes()` a secas tampoco respetaba el borrado lógico: las vacantes
            // borradas seguían en la bolsa.)
            ->where(function ($q) {
                $q->where('is_active', true)->orWhereNull('is_active');
            })
            ->get();

        // (Fase 3, 2026-08-24) Antes se devolvía `$vacancies` a secas, y eso serializa la FILA
        // ENTERA: `tenant_id`, `deleted_at`, `is_hidden`, `job_role_id` y las marcas de auditoría
        // viajaban al navegador de cualquier candidato. Nada de eso es un secreto grave, pero un
        // payload público no carga contabilidad interna — y el día que alguien agregue una columna
        // de notas de reclutamiento se publicaría sola. `VacancyPublicResource` es una lista
        // BLANCA: lo que no está enumerado ahí, no sale. Una columna nueva nace privada.
        return response()->json([
            'tenant' => [
                'name' => $tenant->name,
                'logo_url' => $tenant->logo_url ?: '',
                'brand_color' => $tenant->brand_color ?: '#3b82f6',
                'public_portal_enabled' => (bool)($tenant->public_portal_enabled ?? true),
                'custom_settings' => static::ajustesDelPortal($tenant->portal_custom_settings_json)
            ],
            'vacancies' => VacancyPublicResource::collection($vacancies)->resolve(),
        ]);
    }

    public function storeVacancyAlert(Request $request)
    {
        // La empresa se deduce SIEMPRE del slug del portal que se está visitando. Antes sólo se
        // hacía cuando el cuerpo no traía `tenant_id`, y el portal público mandaba siempre
        // `tenant_id: 1` (no hay sesión de la que sacarlo): el correo de quien pedía aviso en la
        // bolsa de CUALQUIER cliente acababa archivado en la empresa 1. En una ruta pública el
        // `tenant_id` del cuerpo es un dato del visitante: no se le hace caso.
        $request->validate([
            'email' => 'required|email',
            'job_role_name' => 'required|string',
            // El slug es OBLIGATORIO. Mientras se pudiera omitir y mandar `tenant_id` a pelo,
            // cualquiera escribía correos en la lista de la empresa que eligiera.
            'slug' => 'required|string',
        ]);

        $tenant = static::empresaDelSlug((string) $request->slug);
        if (!$tenant) {
            return response()->json(['message' => 'No encontramos esta bolsa de trabajo.'], 404);
        }
        $request->merge(['tenant_id' => $tenant->id]);

        $alert = \App\Models\VacancyAlert::create([
            'tenant_id' => $request->tenant_id,
            'email' => $request->email,
            'job_role_name' => $request->job_role_name,
            'notified_at' => null,
        ]);

        return response()->json($alert, 201);
    }

    // ==========================================
    // ENDPOINTS PRIVADOS (Gestor ATS)
    // ==========================================
    public function getAdminVacancies()
    {
        // Usar Eloquent aplica el global scope automáticamente (solo ve vacantes de su tenant)
        $vacancies = Vacancy::with('jobRole:id,name')->get();

        $vacancies->transform(function ($v) {
            if (is_string($v->requirements)) {
                $decoded = json_decode($v->requirements, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $v->requirements = $decoded;
                } else {
                    $v->requirements = array_values(array_filter(array_map('trim', explode("\n", $v->requirements))));
                }
            } else {
                $v->requirements = is_array($v->requirements) ? $v->requirements : [];
            }
            $v->role_name = $v->jobRole ? $v->jobRole->name : 'N/A';
            return $v;
        });

        return response()->json($vacancies);
    }

    public function createVacancy(Request $request)
    {
        $request->validate([
            'job_role_id' => 'required|integer|exists:job_roles,id',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'requirements' => 'nullable|array',
            'is_active' => 'nullable|boolean',
            'is_hidden' => 'nullable|boolean',
            'image_url' => 'nullable|string',
            'work_type' => 'required|string',
            'schedule' => 'required|string',
            'salary_range' => 'required|string',
        ]);

        // Verify the job role belongs to this tenant
        if (!JobRole::where('id', $request->job_role_id)->exists()) {
            return response()->json(['message' => 'El puesto seleccionado no pertenece a su organización.'], 403);
        }

        $vacancy = Vacancy::create([
            'job_role_id' => $request->job_role_id,
            'title' => $request->title,
            'description' => $request->description,
            'requirements' => is_array($request->requirements) ? json_encode($request->requirements) : json_encode([]),
            'is_active' => $request->is_active ?? true,
            'is_hidden' => $request->is_hidden ?? false,
            'image_url' => $request->image_url,
            'work_type' => $request->work_type,
            'schedule' => $request->schedule,
            'salary_range' => $request->salary_range,
        ]);

        return response()->json(['id' => $vacancy->id, 'message' => 'Vacancy created successfully']);
    }

    public function updateVacancy(Request $request, $id)
    {
        $vacancy = Vacancy::findOrFail($id);

        $request->validate([
            'job_role_id' => 'sometimes|integer|exists:job_roles,id',
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'requirements' => 'nullable|array',
            'is_active' => 'nullable|boolean',
            'is_hidden' => 'nullable|boolean',
            'image_url' => 'nullable|string',
            'work_type' => 'sometimes|string',
            'schedule' => 'sometimes|string',
            'salary_range' => 'sometimes|string',
        ]);

        if ($request->has('job_role_id')) {
            // Verify the job role belongs to this tenant
            if (!JobRole::where('id', $request->job_role_id)->exists()) {
                return response()->json(['message' => 'El puesto seleccionado no pertenece a su organización.'], 403);
            }
        }
        
        $updateData = $request->only([
            'title', 'description', 'is_active', 'is_hidden', 
            'image_url', 'work_type', 'schedule', 'salary_range', 'job_role_id'
        ]);

        if ($request->has('requirements')) {
            $updateData['requirements'] = is_array($request->requirements) ? json_encode($request->requirements) : json_encode([]);
        }

        $vacancy->update($updateData);

        return response()->json(['message' => 'Vacancy updated successfully']);
    }

    public function deleteVacancy($id)
    {
        $vacancy = Vacancy::findOrFail($id);
        $vacancy->delete();
        
        return response()->json(['message' => 'Vacancy deleted successfully']);
    }

    public function getPortalSettings(Request $request)
    {
        $tenant = auth()->user()->tenant;
        if (!$tenant) {
            return response()->json(['message' => 'No se encontró la información de la empresa.'], 404);
        }

        return response()->json([
            'name' => $tenant->name,
            // Sin `public_slug` configurado se ofrecía `Str::slug($tenant->subdomain)`, que NO se
            // guarda y que el resolutor público no reconoce si el subdominio trae mayúsculas o
            // guion bajo: el dueño copiaba y difundía una dirección que daba 404. Se ofrece el
            // subdominio tal cual, que sí resuelve.
            'public_slug' => $tenant->public_slug ?: $tenant->subdomain,
            'brand_color' => $tenant->brand_color ?: '#3b82f6',
            'logo_url' => $tenant->logo_url ?: '',
            'public_portal_enabled' => (bool)($tenant->public_portal_enabled ?? true),
            'custom_settings' => static::ajustesDelPortal($tenant->portal_custom_settings_json)
        ]);
    }

    public function updatePortalSettings(Request $request)
    {
        $tenant = auth()->user()->tenant;
        if (!$tenant) {
            return response()->json(['message' => 'No se encontró la información de la empresa.'], 404);
        }

        $request->validate([
            // La unicidad se comprobaba SÓLO contra `public_slug`, pero el portal resuelve también
            // por `subdomain`: una empresa podía fijar como dirección pública el subdominio de
            // otra. Se rechazan las dos colisiones.
            'public_slug' => [
                'required', 'string', 'alpha_dash', 'max:100',
                \Illuminate\Validation\Rule::unique('tenants', 'public_slug')->ignore($tenant->id),
                function ($attr, $value, $fail) use ($tenant) {
                    $deOtra = Tenant::withoutGlobalScopes()
                        ->where('subdomain', $value)
                        ->where('id', '!=', $tenant->id)
                        ->exists();
                    if ($deOtra) {
                        $fail('Esa dirección ya es la de otra empresa. Elige otra.');
                    }
                },
            ],
            'brand_color' => 'nullable|string|max:50',
            'logo_url' => 'nullable|string|max:2048',
            'public_portal_enabled' => 'required|boolean',
            'custom_settings' => 'nullable|array',
        ]);

        $updateData = [
            'public_slug' => $request->public_slug,
            'brand_color' => $request->brand_color,
            'logo_url' => $request->logo_url,
            'public_portal_enabled' => $request->public_portal_enabled,
        ];

        if ($request->has('custom_settings')) {
            $updateData['portal_custom_settings_json'] = json_encode($request->custom_settings);
        }

        $tenant->update($updateData);

        return response()->json([
            'message' => 'Configuración del portal público actualizada con éxito',
            'settings' => [
                'name' => $tenant->name,
                'public_slug' => $tenant->public_slug,
                'brand_color' => $tenant->brand_color,
                'logo_url' => $tenant->logo_url,
                'public_portal_enabled' => (bool)$tenant->public_portal_enabled,
                'custom_settings' => static::ajustesDelPortal($tenant->portal_custom_settings_json)
            ]
        ]);
    }

    /**
     * Los correos que pidieron aviso de vacante, para que la empresa pueda usarlos.
     *
     * `vacancy_alerts` era una tabla de SOLO ESCRITURA: el portal prometía "te notificaremos de
     * inmediato" y no había ni un lector en todo el sistema (ni endpoint, ni pantalla, ni job, ni
     * correo). Mientras no exista el envío automático —eso es decisión del dueño—, al menos la
     * empresa ve a quién le interesó su vacante y puede llamarlo.
     */
    public function getVacancyAlerts(Request $request)
    {
        $tenantId = auth()->user()->tenant_id;

        $alertas = \App\Models\VacancyAlert::where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->limit(500)
            ->get(['id', 'email', 'job_role_name', 'notified_at', 'created_at']);

        return response()->json($alertas);
    }
}
