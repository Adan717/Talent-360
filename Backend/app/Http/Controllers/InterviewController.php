<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Interview;
use App\Models\Candidate;

class InterviewController extends Controller
{
    /**
     * List all interviews for the tenant
     */
    public function index(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;

        $interviews = Interview::where('tenant_id', $tenantId)
            ->with('candidate')
            ->orderBy('interview_date', 'asc')
            ->orderBy('interview_time', 'asc')
            ->get();

        return response()->json($interviews);
    }

    /**
     * Schedule a new interview
     */
    public function store(Request $request)
    {
        $request->validate([
            'candidate_id' => 'required|integer|exists:candidates,id',
            'interview_date' => 'required|date',
            'interview_time' => 'required|string',
            'interviewer_name' => 'required|string|max:255',
            'notes' => 'nullable|string'
        ]);

        $tenantId = auth()->user()->tenant_id ?? 1;

        // Verify candidate belongs to same tenant
        $candidate = Candidate::where('id', $request->candidate_id)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$candidate) {
            return response()->json(['message' => 'El candidato seleccionado no es válido.'], 403);
        }

        $interview = Interview::create([
            'candidate_id' => $request->candidate_id,
            'interview_date' => $request->interview_date,
            'interview_time' => $request->interview_time,
            'interviewer_name' => $request->interviewer_name,
            'notes' => $request->notes,
            'tenant_id' => $tenantId
        ]);

        // Eager load candidate for the response
        $interview->load('candidate');

        return response()->json([
            'status' => 'success',
            'message' => 'Entrevista agendada correctamente.',
            'interview' => $interview
        ], 201);
    }

    /**
     * Cancel an interview
     */
    public function destroy($id)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;

        $interview = Interview::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$interview) {
            return response()->json(['message' => 'Entrevista no encontrada.'], 404);
        }

        $interview->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Entrevista cancelada con éxito.'
        ]);
    }
}
