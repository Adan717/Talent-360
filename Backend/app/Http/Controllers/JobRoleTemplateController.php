<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\JobRoleTemplate;
use App\Models\JobRole;
use App\Models\RoleClockPolicy;
use Illuminate\Support\Facades\DB;

class JobRoleTemplateController extends Controller
{
    public function index(Request $request)
    {
        $query = JobRoleTemplate::query();

        if ($request->has('industry')) {
            $query->where('industry', $request->query('industry'));
        }

        return response()->json($query->get());
    }

    public function import(Request $request, $id)
    {
        $template = JobRoleTemplate::findOrFail($id);
        $user = auth()->user();

        if (!$user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $jobRole = DB::transaction(function () use ($template, $user) {
            // Crear el puesto organizativo (JobRole)
            $jobRole = JobRole::create([
                'name' => $template->name,
                'area' => $template->area,
                'esAperturador' => $template->is_opener,
                'portadorLlaves' => $template->is_opener ? 'apertura' : 'ninguno',
                'jerarquiaLlaves' => $template->is_opener ? 2 : 0,
                'tiempoTolerancia' => $template->default_tolerance_mins,
                'requiereJustificante' => true,
                'puedeEmitirAvisos' => false,
                'aplicaLeySilla' => false,
                'evaluacion360Activa' => false,
                'is_active' => true,
                'tenant_id' => $user->tenant_id,
            ]);

            // Crear la política de reloj asociada
            RoleClockPolicy::create([
                'job_role_id' => $jobRole->id,
                'policy_name' => 'Perfil ' . $template->name,
                'config' => [
                    'tolerancia_retardo_mins' => $template->default_tolerance_mins,
                    'requiere_evaluacion_salida' => !$template->is_opener,
                    'puede_abrir_sucursal' => $template->is_opener,
                    'tiene_boton_panico' => $template->is_opener,
                    'puede_usar_kiosko' => true,
                    'minutos_comida' => $template->default_meal_mins,
                    'paseDeLista' => true,
                    'horario_entrada' => $template->default_schedule_start,
                    'horario_salida' => $template->default_schedule_end,
                ]
            ]);

            return $jobRole;
        });

        return response()->json([
            'message' => 'Plantilla importada con éxito',
            'job_role' => $jobRole
        ], 201);
    }
}
