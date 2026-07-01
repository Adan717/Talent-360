<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\EmployeeReport;
use App\Models\AnonymousFeedback;
use Illuminate\Support\Facades\Auth;

class IncidentReportController extends Controller
{
    // El Soplón - Crear Reporte de Compañero
    public function storeIncident(Request $request)
    {
        $request->validate([
            'accused_id' => 'required|exists:users,id',
            'type' => 'required|string|max:100',
            'details' => 'required|string|max:2000',
        ]);

        $user = Auth::user();

        // Evitar autoreporte
        if ($user->id == $request->input('accused_id')) {
            return response()->json(['error' => 'No puedes reportarte a ti mismo.'], 422);
        }

        $report = EmployeeReport::create([
            'tenant_id' => $user->tenant_id,
            'reporter_id' => $user->id,
            'accused_id' => $request->input('accused_id'),
            'type' => $request->input('type'),
            'details' => $request->input('details'),
        ]);

        return response()->json([
            'message' => 'Reporte crítico registrado correctamente.',
            'report' => $report
        ], 201);
    }

    // El Soplón - Listar Reportes (Administradores/Supervisores)
    public function indexIncidents()
    {
        $user = Auth::user();
        if (!in_array($user->role, ['admin', 'supervisor', 'platform_admin'])) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }

        $reports = EmployeeReport::with(['reporter:id,name,role', 'accused:id,name,role'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($reports);
    }

    // Buzón Anónimo - Crear Feedback
    public function storeFeedback(Request $request)
    {
        $request->validate([
            'type' => 'required|string|max:100',
            'content' => 'required|string|max:2000',
        ]);

        $user = Auth::user();

        $feedback = AnonymousFeedback::create([
            'tenant_id' => $user->tenant_id,
            'type' => $request->input('type'),
            'content' => $request->input('content'),
        ]);

        return response()->json([
            'message' => 'Tu reporte anónimo ha sido enviado de forma segura.',
            'feedback' => $feedback
        ], 201);
    }

    // Buzón Anónimo - Listar Quejas/Feedback (Administradores)
    public function indexFeedback()
    {
        $user = Auth::user();
        if (!in_array($user->role, ['admin', 'supervisor', 'platform_admin'])) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }

        $feedbacks = AnonymousFeedback::orderBy('created_at', 'desc')->get();

        return response()->json($feedbacks);
    }
    
    // Alerta de Abandono (Simular Desconexión / Huida)
    public function reportAbandonment(Request $request)
    {
        $user = Auth::user();
        
        // Creamos una alerta en los audit_logs del sistema
        \DB::table('audit_logs')->insert([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'date' => now()->format('Y-m-d'),
            'type' => 'abandonment',
            'timestamp_str' => now()->format('H:i:s'),
            'reason' => 'Abandono crítico de sucursal detectado (pérdida de Wi-Fi/GPS sin transferir llaves).',
            'punishment_amount' => 100, // Penalización de ejemplo
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        return response()->json(['message' => 'Alerta de abandono registrada en los logs de auditoría.'], 201);
    }
}
