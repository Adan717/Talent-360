<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\LftSetting;

class LftSettingController extends Controller
{
    /**
     * Obtiene la configuración de la LFT para el tenant autenticado.
     * Si no existe, la inicializa con valores por defecto.
     */
    public function getSettings(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;

        $settings = LftSetting::firstOrCreate(
            ['tenant_id' => $tenantId],
            [
                'lates_per_absence' => 3,
                'deduct_absence_day' => true,
                'absences_for_warning' => 3,
                'absences_for_suspension' => 4,
                'proportional_rest_day' => true,
                'late_tolerance_minutes' => 10,
                'meal_tolerance_minutes' => 15,
                'rest_tolerance_minutes' => 10,
                'late_action_mode' => 'deduct',
                'paid_rest_day' => true,
            ]
        );

        return response()->json([
            'success' => true,
            'data' => $settings
        ]);
    }

    /**
     * Guarda o actualiza las configuraciones de la LFT.
     */
    public function saveSettings(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;

        $validated = $request->validate([
            'lates_per_absence' => 'required|integer|min:1',
            'deduct_absence_day' => 'required|boolean',
            'absences_for_warning' => 'required|integer|min:1',
            'absences_for_suspension' => 'required|integer|min:1',
            'proportional_rest_day' => 'required|boolean',
            'late_tolerance_minutes' => 'required|integer|min:0',
            'meal_tolerance_minutes' => 'required|integer|min:0',
            'rest_tolerance_minutes' => 'required|integer|min:0',
            'late_action_mode' => 'required|string|in:deduct,extend_shift',
            'paid_rest_day' => 'required|boolean',
        ]);

        $settings = LftSetting::updateOrCreate(
            ['tenant_id' => $tenantId],
            $validated
        );

        return response()->json([
            'success' => true,
            'message' => 'Configuraciones de la Ley Federal del Trabajo actualizadas con éxito.',
            'data' => $settings
        ]);
    }
}
