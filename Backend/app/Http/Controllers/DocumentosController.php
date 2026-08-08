<?php

namespace App\Http\Controllers;

use App\Models\AcademyCourse;
use App\Models\CompanyDocument;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Scopes\TenantScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Archivo Digital — expedientes de colaboradores y manuales corporativos.
 *
 * Construido en la ronda 2026-08: la pantalla existía pero era un mockup sin una sola
 * línea de backend (upload falso, documentos fabricados, visor "SAT" inventado).
 *
 * Reglas de la ronda (plan 2026-08-08):
 *  - Storage PRIVADO, nombre en disco = uuid, descarga solo por endpoint autenticado.
 *  - Acceso v1 SOLO admin/supervisor (el grupo de rutas lo garantiza).
 *  - Checklist FIJA de 6 documentos requeridos; lo que falta se muestra como FALTANTE.
 *  - Los ids que llegan del cliente son globales: SIEMPRE filtrar por tenant_id explícito.
 */
class DocumentosController extends Controller
{
    // D4: checklist fija de 6 — sin configuración por puesto ni por tenant (v1).
    public const CHECKLIST = [
        'solicitud'             => 'Solicitud de empleo',
        'acta_nacimiento'       => 'Acta de nacimiento',
        'ine'                   => 'INE / Identificación oficial',
        'curp'                  => 'CURP',
        'rfc'                   => 'RFC / Constancia SAT',
        'comprobante_domicilio' => 'Comprobante de domicilio',
    ];

    /** Resumen por empleado activo para el grid de carpetas. */
    public function expedientes(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $employees = Employee::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('is_active_employee', '!=', false)
            ->with('jobRole:id,name')
            ->orderBy('name')
            ->get();

        $docs = EmployeeDocument::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->get()
            ->groupBy('employee_id');

        $resumen = $employees->map(function ($emp) use ($docs) {
            $suyos = $docs->get($emp->id, collect());
            $tiposSubidos = $suyos->pluck('doc_type')->unique();

            return [
                'employee_id' => $emp->id,
                'name'        => $emp->name,
                'role'        => $emp->jobRole->name ?? 'Sin puesto',
                'subidos'     => $suyos->count(),
                'validados'   => $suyos->where('status', 'validado')->count(),
                'faltantes'   => collect(array_keys(self::CHECKLIST))->diff($tiposSubidos)->count(),
            ];
        });

        return response()->json(['employees' => $resumen->values()]);
    }

    /** Expediente de un colaborador: checklist de 6 (con hueco honesto) + extras. */
    public function expediente(Request $request, $employeeId)
    {
        $tenantId = $request->user()->tenant_id;
        $employee = $this->empleadoDelTenant($tenantId, $employeeId);

        $docs = EmployeeDocument::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employee->id)
            ->orderByDesc('created_at')
            ->get();

        $checklist = collect(self::CHECKLIST)->map(function ($label, $tipo) use ($docs) {
            return [
                'doc_type' => $tipo,
                'label'    => $label,
                'doc'      => $docs->firstWhere('doc_type', $tipo),
            ];
        })->values();

        return response()->json([
            'employee'  => ['id' => $employee->id, 'name' => $employee->name, 'role' => $employee->jobRole->name ?? 'Sin puesto'],
            'checklist' => $checklist,
            'extras'    => $docs->where('doc_type', 'otro')->values(),
        ]);
    }

    /** Subir un documento al expediente. Reemplazar el mismo doc_type soft-borra el anterior. */
    public function subir(Request $request, $employeeId)
    {
        $tenantId = $request->user()->tenant_id;
        $employee = $this->empleadoDelTenant($tenantId, $employeeId);

        // D6: límites del trust boundary — no se negocian.
        $validated = $request->validate([
            'file'     => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'doc_type' => 'required|string|in:' . implode(',', array_merge(array_keys(self::CHECKLIST), ['otro'])),
        ]);

        $file = $validated['file'];
        // El nombre en disco es un uuid con extensión deducida del contenido —
        // jamás el nombre original del cliente (path traversal).
        $filename = Str::uuid() . '.' . $file->extension();
        $path = $file->storeAs("expedientes/{$tenantId}/{$employee->id}", $filename, 'local');

        // El filename del multipart lo controla el cliente: recortado al varchar(255)
        // de la columna — sin esto, un nombre largo revienta el INSERT solo en Postgres
        // (sqlite no aplica límites y la suite no lo vería).
        $originalName = mb_substr($file->getClientOriginalName(), 0, 255);

        // Reemplazo y alta en una transacción: si el INSERT falla, el documento anterior
        // del mismo tipo NO desaparece del expediente. El archivo físico del anterior se
        // conserva siempre (D9: contractual, recuperable).
        $doc = DB::transaction(function () use ($validated, $tenantId, $employee, $originalName, $path, $file, $request) {
            if ($validated['doc_type'] !== 'otro') {
                EmployeeDocument::withoutGlobalScope(TenantScope::class)
                    ->where('tenant_id', $tenantId)
                    ->where('employee_id', $employee->id)
                    ->where('doc_type', $validated['doc_type'])
                    ->get()->each->delete();
            }

            return EmployeeDocument::create([
                'tenant_id'     => $tenantId,
                'employee_id'   => $employee->id,
                'doc_type'      => $validated['doc_type'],
                'original_name' => $originalName,
                'path'          => $path,
                'mime'          => $file->getMimeType(),
                'size_bytes'    => $file->getSize(),
                'status'        => 'pendiente', // D5: nada nace "validado"
                'uploaded_by'   => $request->user()->id,
            ]);
        });

        return response()->json(['success' => true, 'doc' => $doc]);
    }

    /** Stream autenticado del archivo (empleado o corporativo). Única puerta de salida. */
    public function descargar(Request $request, $docId)
    {
        $doc = $this->docDelTenant($request, $docId, $request->query('scope', 'empleado'));

        if (!Storage::disk('local')->exists($doc->path)) {
            abort(404, 'El archivo físico no existe.');
        }

        return Storage::disk('local')->download($doc->path, $doc->original_name);
    }

    /** Validar o rechazar (con motivo obligatorio) un documento del expediente. */
    public function validar(Request $request, $docId)
    {
        $validated = $request->validate([
            'accion' => 'required|in:validar,rechazar',
            'motivo' => 'required_if:accion,rechazar|nullable|string|max:1000',
        ]);

        $doc = $this->docDelTenant($request, $docId, 'empleado');

        $doc->update([
            'status'           => $validated['accion'] === 'validar' ? 'validado' : 'rechazado',
            'rejection_reason' => $validated['accion'] === 'rechazar' ? $validated['motivo'] : null,
            'validated_by'     => $request->user()->id,
            'validated_at'     => now(),
        ]);

        return response()->json(['success' => true, 'doc' => $doc]);
    }

    /** Soft-delete. El archivo físico se conserva (D9). */
    public function eliminar(Request $request, $docId)
    {
        $doc = $this->docDelTenant($request, $docId, $request->query('scope', 'empleado'));
        $doc->delete();

        return response()->json(['success' => true]);
    }

    /** Lista de manuales corporativos con su curso vinculado. */
    public function corporativos(Request $request)
    {
        $docs = CompanyDocument::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $request->user()->tenant_id)
            ->with('course:id,title')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['docs' => $docs]);
    }

    /** Subir un manual corporativo. */
    public function subirCorporativo(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $validated = $request->validate([
            'file'     => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'category' => 'required|string|max:120',
            'name'     => 'nullable|string|max:255',
        ]);

        $file = $validated['file'];
        $filename = Str::uuid() . '.' . $file->extension();
        $path = $file->storeAs("corporativos/{$tenantId}", $filename, 'local');

        // Mismo recorte que en subir(): el filename lo controla el cliente.
        $originalName = mb_substr($file->getClientOriginalName(), 0, 255);

        $doc = CompanyDocument::create([
            'tenant_id'     => $tenantId,
            'name'          => $validated['name'] ?? $originalName,
            'category'      => $validated['category'],
            'original_name' => $originalName,
            'path'          => $path,
            'mime'          => $file->getMimeType(),
            'size_bytes'    => $file->getSize(),
            'uploaded_by'   => $request->user()->id,
        ]);

        return response()->json(['success' => true, 'doc' => $doc]);
    }

    /** Vincular (o desvincular con null) un manual a un curso de Academia — persistido de verdad. */
    public function vincular(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $validated = $request->validate([
            'course_id' => 'nullable|integer',
        ]);

        $doc = $this->docDelTenant($request, $id, 'corporativo');

        $courseId = $validated['course_id'] ?? null;
        if ($courseId !== null) {
            AcademyCourse::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenantId)
                ->findOrFail($courseId);
        }

        $doc->update(['linked_course_id' => $courseId]);

        return response()->json(['success' => true, 'doc' => $doc->load('course:id,title')]);
    }

    // -------------------------------------------------------------------------

    /** Empleado del MI tenant o 404 — nunca confiar en el id que manda el cliente. */
    private function empleadoDelTenant(?int $tenantId, $employeeId): Employee
    {
        return Employee::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->with('jobRole:id,name')
            ->findOrFail($employeeId);
    }

    /** Documento del MI tenant (por scope) o 404. Excluye soft-borrados. */
    private function docDelTenant(Request $request, $docId, string $scope)
    {
        $model = $scope === 'corporativo' ? CompanyDocument::class : EmployeeDocument::class;

        return $model::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($docId);
    }
}
