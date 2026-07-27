<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\FreemiumComplianceCheck;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class FreemiumComplianceController extends Controller
{
    /**
     * §53: el admin del tenant sube su comprobante de cumplimiento del mes.
     * Solo aplica a planes freemium.
     */
    public function submit(Request $request)
    {
        $user = auth()->user();
        $tenantId = $user->tenant_id ?? 1;

        $tenant = \App\Models\Tenant::find($tenantId);
        if (!$tenant || strtolower($tenant->plan ?? '') !== 'freemium') {
            return response()->json(['success' => false, 'message' => 'El cumplimiento mensual solo aplica al plan freemium.'], 422);
        }

        $validated = $request->validate([
            'period' => 'nullable|string|regex:/^\d{4}-\d{2}$/',
            'proof_note' => 'nullable|string|max:2000',
            'proof_url' => 'nullable|string',
        ]);

        if (empty($validated['proof_note']) && empty($validated['proof_url'])) {
            return response()->json(['success' => false, 'message' => 'Adjunta una captura o una nota como comprobante.'], 422);
        }

        $period = $validated['period'] ?? Carbon::now()->format('Y-m');

        $check = FreemiumComplianceCheck::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenantId, 'period' => $period],
            [
                'status' => 'submitted',
                'proof_note' => $validated['proof_note'] ?? null,
                'proof_url' => $validated['proof_url'] ?? null,
                // Reset de una revisión previa si vuelve a enviar.
                'reviewed_by' => null,
                'review_note' => null,
                'reviewed_at' => null,
            ]
        );

        return response()->json(['success' => true, 'message' => 'Comprobante enviado. Talent360 lo revisará.', 'check' => $check]);
    }

    /** §53: el tenant ve su propio historial de cumplimiento. */
    public function mine(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;

        $checks = FreemiumComplianceCheck::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('period')
            ->get();

        return response()->json(['success' => true, 'checks' => $checks]);
    }

    /** §53: Plataforma Talent360 lista los comprobantes (por estado). */
    public function platformIndex(Request $request)
    {
        $status = $request->query('status');

        $query = FreemiumComplianceCheck::withoutGlobalScopes()
            ->with('tenant:id,name,plan')
            ->orderByDesc('created_at');

        if ($status) {
            $query->where('status', $status);
        }

        return response()->json(['success' => true, 'checks' => $query->get()]);
    }

    /** §53: Plataforma aprueba o rechaza un comprobante. */
    public function review(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:approved,rejected',
            'review_note' => 'nullable|string|max:2000',
        ]);

        $check = FreemiumComplianceCheck::withoutGlobalScopes()->findOrFail($id);

        $check->update([
            'status' => $validated['status'],
            'review_note' => $validated['review_note'] ?? null,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        // v1: solo se marca el resultado. NO se suspende el tenant automáticamente —
        // por ahora es recordatorio + bandera visible en Plataforma, hasta que Francisco
        // decida la política de suspensión (ver §53 punto 4).

        return response()->json(['success' => true, 'check' => $check]);
    }
}
