<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\JobRole;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\Employee;

class JobRoleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // Eloquent filters automatically by tenant_id thanks to BelongsToTenant trait
        $jobRoles = JobRole::with('reportsTo:id,name')->get();
        return response()->json($jobRoles);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // Sanitize booleans and numbers
        $booleans = ['esAperturador', 'requiereJustificante', 'puedeEmitirAvisos', 'aplicaLeySilla', 'evaluacion360Activa', 'is_active'];
        foreach ($booleans as $field) {
            if ($request->has($field)) {
                $val = $request->input($field);
                if (is_null($val) || $val === '' || $val === 'null' || $val === 'undefined') {
                    $request->merge([$field => ($field === 'is_active' ? true : false)]);
                } else {
                    $request->merge([$field => filter_var($val, FILTER_VALIDATE_BOOLEAN)]);
                }
            }
        }

        if ($request->has('reports_to_role_id')) {
            $val = $request->input('reports_to_role_id');
            if (is_null($val) || $val === '' || $val === 'null' || $val === 'undefined' || $val === 0 || $val === '0') {
                $request->merge(['reports_to_role_id' => null]);
            } else {
                $request->merge(['reports_to_role_id' => (int)$val]);
            }
        }

        if ($request->has('org_parent_role_id')) {
            $val = $request->input('org_parent_role_id');
            if (is_null($val) || $val === '' || $val === 'null' || $val === 'undefined' || $val === 0 || $val === '0') {
                $request->merge(['org_parent_role_id' => null]);
            } else {
                $request->merge(['org_parent_role_id' => (int)$val]);
            }
        }

        if ($request->has('late_penalty_multiplier')) {
            $val = $request->input('late_penalty_multiplier');
            if (is_null($val) || $val === '' || $val === 'null' || $val === 'undefined') {
                $request->merge(['late_penalty_multiplier' => 1.0]);
            } else {
                $request->merge(['late_penalty_multiplier' => (float)$val]);
            }
        }

        if ($request->has('tiempoTolerancia')) {
            $val = $request->input('tiempoTolerancia');
            if (is_null($val) || $val === '' || $val === 'null' || $val === 'undefined') {
                $request->merge(['tiempoTolerancia' => 10]);
            } else {
                $request->merge(['tiempoTolerancia' => (int)$val]);
            }
        }

        if ($request->has('nivel_mando')) {
            $val = $request->input('nivel_mando');
            if (is_null($val) || $val === '' || $val === 'null' || $val === 'undefined') {
                $request->merge(['nivel_mando' => 4]);
            } else {
                $request->merge(['nivel_mando' => (int)$val]);
            }
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'area' => 'required|string|max:255',
            'description' => 'nullable|string',
            'responsibilities' => 'nullable|string',
            'manual_name' => 'nullable|string|max:255',
            'manual_url' => 'nullable|string|max:255',
            'reports_to_role_id' => 'nullable|integer|exists:job_roles,id',
            'org_parent_role_id' => 'nullable|integer|exists:job_roles,id',
            'nivel_mando' => 'nullable|integer|between:1,6',
            'reports_to_role_ids' => 'nullable|array',
            'reports_to_role_ids.*' => 'integer|exists:job_roles,id',
            'esAperturador' => 'boolean',
            'portadorLlaves' => 'string|in:ninguno,apertura,cierre,ambos',
            'jerarquiaLlaves' => 'integer',
            'tiempoTolerancia' => 'integer|min:0',
            'requiereJustificante' => 'boolean',
            'puedeEmitirAvisos' => 'boolean',
            'aplicaLeySilla' => 'boolean',
            'evaluacion360Activa' => 'boolean',
            'late_penalty_multiplier' => 'numeric|min:0.1',
            'required_equipment' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        // Sync reports_to_role_ids and reports_to_role_id
        if ($request->has('reports_to_role_ids')) {
            $ids = $request->input('reports_to_role_ids');
            if (is_array($ids) && count($ids) > 0) {
                $ids = array_map('intval', $ids);
                $data['reports_to_role_ids'] = $ids;
                $data['reports_to_role_id'] = $ids[0];
            } else {
                $data['reports_to_role_ids'] = null;
                $data['reports_to_role_id'] = null;
            }
        } elseif (isset($data['reports_to_role_id'])) {
            $data['reports_to_role_ids'] = $data['reports_to_role_id'] ? [(int)$data['reports_to_role_id']] : null;
        }

        // Validate that parent job roles belong to the current tenant
        if (isset($data['reports_to_role_id']) && $data['reports_to_role_id']) {
            $parentExists = JobRole::where('id', $data['reports_to_role_id'])->exists();
            if (!$parentExists) {
                return response()->json(['message' => 'El puesto superior seleccionado no pertenece a su organización.'], 403);
            }
        }
        if (isset($data['org_parent_role_id']) && $data['org_parent_role_id']) {
            $parentExists = JobRole::where('id', $data['org_parent_role_id'])->exists();
            if (!$parentExists) {
                return response()->json(['message' => 'El puesto superior en organigrama seleccionado no pertenece a su organización.'], 403);
            }
        }
        if (isset($data['reports_to_role_ids']) && is_array($data['reports_to_role_ids']) && count($data['reports_to_role_ids']) > 0) {
            $validCount = JobRole::whereIn('id', $data['reports_to_role_ids'])->count();
            if ($validCount !== count($data['reports_to_role_ids'])) {
                return response()->json(['message' => 'Uno o más puestos superiores seleccionados no pertenecen a su organización.'], 403);
            }
        }

        // Default values for boolean/nullable fields if not provided
        $data['esAperturador'] = $request->input('esAperturador', false);
        $data['portadorLlaves'] = $request->input('portadorLlaves', 'ninguno');
        $data['jerarquiaLlaves'] = $request->input('jerarquiaLlaves', 0);
        $data['tiempoTolerancia'] = $request->input('tiempoTolerancia', 10);
        $data['requiereJustificante'] = $request->input('requiereJustificante', true);
        $data['puedeEmitirAvisos'] = $request->input('puedeEmitirAvisos', false);
        $data['aplicaLeySilla'] = $request->input('aplicaLeySilla', false);
        $data['evaluacion360Activa'] = $request->input('evaluacion360Activa', false);
        $data['late_penalty_multiplier'] = $request->input('late_penalty_multiplier', 1);
        $data['is_active'] = $request->input('is_active', true);

        // Idempotency check: Find if a job role with the same name already exists in this tenant
        $existingJobRole = JobRole::whereRaw('LOWER(name) = ?', [strtolower(trim($data['name']))])->first();
        if ($existingJobRole) {
            $existingJobRole->update($data);
            return response()->json($existingJobRole, 200);
        }

        $jobRole = JobRole::create($data);

        return response()->json($jobRole, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $jobRole = JobRole::with('reportsTo:id,name')->findOrFail($id);
        return response()->json($jobRole);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        // Sanitize booleans and numbers
        $booleans = ['esAperturador', 'requiereJustificante', 'puedeEmitirAvisos', 'aplicaLeySilla', 'evaluacion360Activa', 'is_active'];
        foreach ($booleans as $field) {
            if ($request->has($field)) {
                $val = $request->input($field);
                if (is_null($val) || $val === '' || $val === 'null' || $val === 'undefined') {
                    $request->merge([$field => ($field === 'is_active' ? true : false)]);
                } else {
                    $request->merge([$field => filter_var($val, FILTER_VALIDATE_BOOLEAN)]);
                }
            }
        }

        if ($request->has('reports_to_role_id')) {
            $val = $request->input('reports_to_role_id');
            if (is_null($val) || $val === '' || $val === 'null' || $val === 'undefined' || $val === 0 || $val === '0') {
                $request->merge(['reports_to_role_id' => null]);
            } else {
                $request->merge(['reports_to_role_id' => (int)$val]);
            }
        }

        if ($request->has('org_parent_role_id')) {
            $val = $request->input('org_parent_role_id');
            if (is_null($val) || $val === '' || $val === 'null' || $val === 'undefined' || $val === 0 || $val === '0') {
                $request->merge(['org_parent_role_id' => null]);
            } else {
                $request->merge(['org_parent_role_id' => (int)$val]);
            }
        }

        if ($request->has('late_penalty_multiplier')) {
            $val = $request->input('late_penalty_multiplier');
            if (is_null($val) || $val === '' || $val === 'null' || $val === 'undefined') {
                $request->merge(['late_penalty_multiplier' => 1.0]);
            } else {
                $request->merge(['late_penalty_multiplier' => (float)$val]);
            }
        }

        if ($request->has('tiempoTolerancia')) {
            $val = $request->input('tiempoTolerancia');
            if (is_null($val) || $val === '' || $val === 'null' || $val === 'undefined') {
                $request->merge(['tiempoTolerancia' => 10]);
            } else {
                $request->merge(['tiempoTolerancia' => (int)$val]);
            }
        }

        if ($request->has('nivel_mando')) {
            $val = $request->input('nivel_mando');
            if (is_null($val) || $val === '' || $val === 'null' || $val === 'undefined') {
                $request->merge(['nivel_mando' => 4]);
            } else {
                $request->merge(['nivel_mando' => (int)$val]);
            }
        }

        $jobRole = JobRole::findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'area' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'responsibilities' => 'nullable|string',
            'manual_name' => 'nullable|string|max:255',
            'manual_url' => 'nullable|string|max:255',
            'reports_to_role_id' => 'nullable|integer|exists:job_roles,id',
            'org_parent_role_id' => 'nullable|integer|exists:job_roles,id',
            'nivel_mando' => 'nullable|integer|between:1,6',
            'reports_to_role_ids' => 'nullable|array',
            'reports_to_role_ids.*' => 'integer|exists:job_roles,id',
            'esAperturador' => 'boolean',
            'portadorLlaves' => 'string|in:ninguno,apertura,cierre,ambos',
            'jerarquiaLlaves' => 'integer',
            'tiempoTolerancia' => 'integer|min:0',
            'requiereJustificante' => 'boolean',
            'puedeEmitirAvisos' => 'boolean',
            'aplicaLeySilla' => 'boolean',
            'evaluacion360Activa' => 'boolean',
            'late_penalty_multiplier' => 'numeric|min:0.1',
            'required_equipment' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        // Sync reports_to_role_ids and reports_to_role_id
        if ($request->has('reports_to_role_ids')) {
            $ids = $request->input('reports_to_role_ids');
            if (is_array($ids) && count($ids) > 0) {
                $ids = array_map('intval', $ids);
                $data['reports_to_role_ids'] = $ids;
                $data['reports_to_role_id'] = $ids[0];
            } else {
                $data['reports_to_role_ids'] = null;
                $data['reports_to_role_id'] = null;
            }
        } elseif (isset($data['reports_to_role_id'])) {
            $data['reports_to_role_ids'] = $data['reports_to_role_id'] ? [(int)$data['reports_to_role_id']] : null;
        }

        // Validate that parent job roles belong to the current tenant
        if (isset($data['reports_to_role_id']) && $data['reports_to_role_id']) {
            $parentExists = JobRole::where('id', $data['reports_to_role_id'])->exists();
            if (!$parentExists) {
                return response()->json(['message' => 'El puesto superior seleccionado no pertenece a su organización.'], 403);
            }
        }
        if (isset($data['org_parent_role_id']) && $data['org_parent_role_id']) {
            $parentExists = JobRole::where('id', $data['org_parent_role_id'])->exists();
            if (!$parentExists) {
                return response()->json(['message' => 'El puesto superior en organigrama seleccionado no pertenece a su organización.'], 403);
            }
        }
        if (isset($data['reports_to_role_ids']) && is_array($data['reports_to_role_ids']) && count($data['reports_to_role_ids']) > 0) {
            $validCount = JobRole::whereIn('id', $data['reports_to_role_ids'])->count();
            if ($validCount !== count($data['reports_to_role_ids'])) {
                return response()->json(['message' => 'Uno o más puestos superiores seleccionados no pertenecen a su organización.'], 403);
            }
        }

        // Prevent self-referencing hierarchy
        if (isset($data['reports_to_role_ids']) && in_array((int)$id, $data['reports_to_role_ids'])) {
            return response()->json([
                'message' => 'The job role cannot report to itself.'
            ], 422);
        }

        // Prevent self-referencing hierarchy
        if (isset($data['reports_to_role_id']) && (int)$data['reports_to_role_id'] === (int)$id) {
            return response()->json([
                'message' => 'The job role cannot report to itself.'
            ], 422);
        }

        // Prevent circular reference in organigrama tree
        if (isset($data['org_parent_role_id']) && $this->wouldCreateOrgCycle($id, $data['org_parent_role_id'])) {
            return response()->json([
                'message' => 'No se puede establecer este puesto superior en el organigrama ya que crearía un ciclo de referencias.'
            ], 422);
        }

        // §21: reports_to_role_ids es un grafo (puede tener varios "padres"), a diferencia
        // de org_parent_role_id que es una sola cadena — cada id nuevo se valida por
        // separado con BFS sobre el grafo de "reporta a".
        if (isset($data['reports_to_role_ids']) && is_array($data['reports_to_role_ids'])) {
            $tenantId = auth()->user()->tenant_id ?? $jobRole->tenant_id;
            foreach ($data['reports_to_role_ids'] as $targetId) {
                if ($this->wouldCreateReportsToCycle($tenantId, (int) $id, (int) $targetId)) {
                    return response()->json([
                        'message' => 'No se puede agregar esta jerarquía de "Reporta A" ya que crearía un ciclo de referencias.'
                    ], 422);
                }
            }
        }

        $jobRole->update($data);

        return response()->json($jobRole);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $jobRole = JobRole::findOrFail($id);

        // Security check: Check if there are active users assigned to this job role
        $usersCount = Employee::where('job_role_id', $id)->count();
        if ($usersCount > 0) {
            return response()->json([
                'message' => "No se puede eliminar el puesto porque tiene {$usersCount} colaborador(es) asignado(s)."
            ], 400);
        }

        // Security check: Check if there are vacancies assigned to this job role
        $vacanciesCount = Vacancy::where('job_role_id', $id)->count();
        if ($vacanciesCount > 0) {
            return response()->json([
                'message' => "No se puede eliminar el puesto porque tiene {$vacanciesCount} vacante(s) asociada(s)."
            ], 400);
        }

        $jobRole->delete();

        return response()->json([
            'message' => 'Puesto de trabajo eliminado correctamente.'
        ]);
    }

    private function wouldCreateOrgCycle($roleId, $proposedParentId)
    {
        if (!$proposedParentId) {
            return false;
        }
        if ((int)$roleId === (int)$proposedParentId) {
            return true;
        }
        
        $currentId = $proposedParentId;
        $visited = [];
        
        while ($currentId) {
            if ((int)$currentId === (int)$roleId) {
                return true;
            }
            if (in_array($currentId, $visited)) {
                break;
            }
            $visited[] = $currentId;
            
            $parent = JobRole::find($currentId);
            $currentId = $parent ? $parent->org_parent_role_id : null;
        }

        return false;
    }

    /**
     * A diferencia de org_parent_role_id (cadena simple), reports_to_role_ids es un
     * array — un puesto puede "reportar a" varios otros — así que hace falta recorrer
     * el grafo dirigido completo (BFS) en vez de seguir un solo padre.
     */
    private function wouldCreateReportsToCycle(int $tenantId, int $sourceId, int $targetId): bool
    {
        if ($sourceId === $targetId) {
            return true;
        }

        $queue = [$targetId];
        $visited = [];

        while (!empty($queue)) {
            $currentId = array_shift($queue);
            if (in_array($currentId, $visited, true)) {
                continue;
            }
            $visited[] = $currentId;

            if ($currentId === $sourceId) {
                return true;
            }

            $current = JobRole::where('tenant_id', $tenantId)->find($currentId);
            foreach (($current->reports_to_role_ids ?? []) as $nextId) {
                $queue[] = (int) $nextId;
            }
        }

        return false;
    }
}
