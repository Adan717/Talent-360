<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Vacancy;
use App\Models\JobRole;
use App\Models\Tenant;

class RecruitmentController extends Controller
{
    // ==========================================
    // ENDPOINTS PÚBLICOS (Web de Empleos)
    // ==========================================
    public function getPublicVacancies(Request $request, $slug)
    {
        $request->merge(['slug' => $slug]);
        $request->validate([
            'slug' => 'required|string',
        ]);

        $tenant = Tenant::withoutGlobalScopes()->where(function ($q) use ($slug) {
            $q->where('public_slug', $slug)->orWhere('subdomain', $slug);
        })->firstOrFail();
        $tenantId = $tenant->id;

        $vacancies = Vacancy::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_hidden', false)
            ->with('jobRole:id,name')
            ->get();

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

        return response()->json([
            'tenant' => [
                'name' => $tenant->name,
                'logo_url' => $tenant->logo_url ?: '',
                'brand_color' => $tenant->brand_color ?: '#3b82f6',
                'public_portal_enabled' => (bool)($tenant->public_portal_enabled ?? true),
            ],
            'vacancies' => $vacancies
        ]);
    }

    public function storeVacancyAlert(Request $request)
    {
        if ($request->has('slug') && !$request->has('tenant_id')) {
            $tenant = Tenant::withoutGlobalScopes()->where(function ($q) use ($request) {
                $q->where('public_slug', $request->slug)->orWhere('subdomain', $request->slug);
            })->first();
            if ($tenant) {
                $request->merge(['tenant_id' => $tenant->id]);
            }
        }

        $request->validate([
            'email' => 'required|email',
            'job_role_name' => 'required|string',
            'tenant_id' => 'required|integer|exists:tenants,id',
        ]);

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
            'public_slug' => $tenant->public_slug ?: \Illuminate\Support\Str::slug($tenant->subdomain),
            'brand_color' => $tenant->brand_color ?: '#3b82f6',
            'logo_url' => $tenant->logo_url ?: '',
            'public_portal_enabled' => (bool)($tenant->public_portal_enabled ?? true),
        ]);
    }

    public function updatePortalSettings(Request $request)
    {
        $tenant = auth()->user()->tenant;
        if (!$tenant) {
            return response()->json(['message' => 'No se encontró la información de la empresa.'], 404);
        }

        $request->validate([
            'public_slug' => 'required|string|alpha_dash|max:100|unique:tenants,public_slug,' . $tenant->id,
            'brand_color' => 'nullable|string|max:50',
            'logo_url' => 'nullable|string|max:2048',
            'public_portal_enabled' => 'required|boolean',
        ]);

        $tenant->update([
            'public_slug' => $request->public_slug,
            'brand_color' => $request->brand_color,
            'logo_url' => $request->logo_url,
            'public_portal_enabled' => $request->public_portal_enabled,
        ]);

        return response()->json([
            'message' => 'Configuración del portal público actualizada con éxito',
            'settings' => [
                'name' => $tenant->name,
                'public_slug' => $tenant->public_slug,
                'brand_color' => $tenant->brand_color,
                'logo_url' => $tenant->logo_url,
                'public_portal_enabled' => (bool)$tenant->public_portal_enabled,
            ]
        ]);
    }
}
