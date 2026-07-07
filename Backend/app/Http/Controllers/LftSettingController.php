<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\LftSetting;
use App\Models\LftHoliday;

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

    /**
     * Obtiene los días festivos de la LFT.
     * Si no hay ninguno, precarga los festivos oficiales de México para 2026.
     */
    public function getHolidays(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;

        $holidays = LftHoliday::where('tenant_id', $tenantId)
            ->orderBy('date', 'asc')
            ->get();

        if ($holidays->isEmpty()) {
            // Precargar días festivos oficiales de México (Año 2026)
            $defaultHolidays = [
                ['date' => '2026-01-01', 'name' => 'Año Nuevo', 'block_app' => false],
                ['date' => '2026-02-02', 'name' => 'Aniversario de la Constitución (recorrido)', 'block_app' => false],
                ['date' => '2026-03-16', 'name' => 'Natalicio de Benito Juárez (recorrido)', 'block_app' => false],
                ['date' => '2026-05-01', 'name' => 'Día del Trabajo', 'block_app' => false],
                ['date' => '2026-09-16', 'name' => 'Día de la Independencia', 'block_app' => false],
                ['date' => '2026-11-16', 'name' => 'Día de la Revolución (recorrido)', 'block_app' => false],
                ['date' => '2026-12-25', 'name' => 'Navidad', 'block_app' => false],
            ];

            foreach ($defaultHolidays as $dh) {
                LftHoliday::create([
                    'tenant_id' => $tenantId,
                    'date' => $dh['date'],
                    'name' => $dh['name'],
                    'block_app' => $dh['block_app'],
                ]);
            }

            $holidays = LftHoliday::where('tenant_id', $tenantId)
                ->orderBy('date', 'asc')
                ->get();
        }

        return response()->json([
            'success' => true,
            'data' => $holidays
        ]);
    }

    /**
     * Crea o actualiza un día festivo oficial.
     */
    public function saveHoliday(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;

        $validated = $request->validate([
            'id' => 'nullable|integer|exists:lft_holidays,id',
            'date' => 'required|date',
            'name' => 'required|string|max:255',
            'block_app' => 'required|boolean',
        ]);

        // Evitar duplicados de fecha en el mismo tenant si es una creación nueva
        if (!isset($validated['id'])) {
            $exists = LftHoliday::where('tenant_id', $tenantId)
                ->where('date', $validated['date'])
                ->exists();
            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ya existe un día festivo registrado para esa fecha.'
                ], 422);
            }
        }

        $holiday = LftHoliday::updateOrCreate(
            [
                'id' => $validated['id'] ?? null,
                'tenant_id' => $tenantId
            ],
            [
                'date' => $validated['date'],
                'name' => $validated['name'],
                'block_app' => $validated['block_app'],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Día festivo guardado con éxito.',
            'data' => $holiday
        ]);
    }

    /**
     * Elimina un día festivo oficial.
     */
    public function deleteHoliday($id)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;

        $holiday = LftHoliday::where('tenant_id', $tenantId)->find($id);

        if (!$holiday) {
            return response()->json([
                'success' => false,
                'message' => 'Día festivo no encontrado.'
            ], 404);
        }

        $holiday->delete();

        return response()->json([
            'success' => true,
            'message' => 'Día festivo eliminado correctamente.'
        ]);
    }
}
