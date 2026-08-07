<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\LftSetting;
use App\Models\LftHoliday;
use Carbon\Carbon;

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
                // N5/opción A: $0 de fábrica — el art. 107 LFT prohíbe multar el salario.
                'late_penalty_per_minute' => 0.00,
                'max_late_block_minutes' => 0,
                'require_checkout_approval' => false,
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
            // sometimes: el frontend actual no lo envía; al omitirlo, updateOrCreate
            // conserva el valor existente y no rompe el guardado.
            'late_penalty_per_minute' => 'sometimes|numeric|min:0',
            // Retardo Extremo (spec:46): 0 = deshabilitado. sometimes = el frontend actual
            // no lo envía; al omitirlo, updateOrCreate conserva el valor existente.
            'max_late_block_minutes' => 'sometimes|integer|min:0',
            // Salida Doble Llave (spec:53-55): 0/false = deshabilitado.
            'require_checkout_approval' => 'sometimes|boolean',
            // R96 (Fase 6, config sin UI): knobs opt-in de Fases 3-5 que hasta ahora sólo se podían
            // fijar por SQL crudo. `sometimes` = si el FE no los envía, updateOrCreate conserva el valor.
            'require_closing_checklist' => 'sometimes|boolean',      // Checklist de Cierre (R88)
            'punctuality_bonus_amount' => 'sometimes|numeric|min:0', // Bono de puntualidad (R94)
            'punctuality_bonus_max_lates' => 'sometimes|integer|min:0|max:65535', // columna unsignedSmallInteger
            'opening_bonus_per_open' => 'sometimes|numeric|min:0',   // Bono de apertura (R94)
        ]);

        // N5/opción A: el descuento por minuto arranca en $0 (art. 107 LFT: las multas al
        // salario están prohibidas). Si una empresa lo ACTIVA, es su decisión y queda
        // documentada: quién la tomó y cuándo. Al volverlo a $0 se limpia la constancia.
        $penaltyAnterior = (float) (LftSetting::where('tenant_id', $tenantId)
            ->value('late_penalty_per_minute') ?? 0);

        $settings = LftSetting::updateOrCreate(
            ['tenant_id' => $tenantId],
            $validated
        );

        $warning = null;
        if (array_key_exists('late_penalty_per_minute', $validated)) {
            $penaltyNuevo = (float) $validated['late_penalty_per_minute'];
            if ($penaltyNuevo > 0) {
                $warning = 'Aviso legal: el descuento por minuto de retardo puede no cumplir con el '
                    . 'art. 107 de la LFT (prohíbe imponer multas al salario). Lo activas bajo tu '
                    . 'responsabilidad; queda registrado quién lo activó y cuándo.';
                if ($penaltyAnterior <= 0) {
                    $settings->forceFill([
                        'late_penalty_set_by' => auth()->id(),
                        'late_penalty_set_at' => now(),
                    ])->save();
                }
            } elseif ($penaltyAnterior > 0) {
                $settings->forceFill([
                    'late_penalty_set_by' => null,
                    'late_penalty_set_at' => null,
                ])->save();
            }
        }

        return response()->json(array_filter([
            'success' => true,
            'message' => 'Configuraciones de la Ley Federal del Trabajo actualizadas con éxito.',
            'warning' => $warning,
            'data' => $settings->fresh(),
        ], fn ($v) => $v !== null));
    }

    /**
     * Obtiene los días festivos de la LFT.
     * Si no hay ninguno, precarga los festivos oficiales de México para el año actual.
     */
    public function getHolidays(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;

        $holidays = LftHoliday::where('tenant_id', $tenantId)
            ->orderBy('date', 'asc')
            ->get();

        if ($holidays->isEmpty()) {
            // Precargar días festivos oficiales de México para el AÑO ACTUAL (no un año
            // hardcodeado): los "recorrido" (a lunes) cambian de fecha cada año. El año se
            // toma en hora de México (no UTC) para no sembrar el año siguiente por unas
            // horas la noche del 31-dic; son festivos mexicanos, así que la tz aplica.
            $defaultHolidays = self::defaultHolidaysForYear(Carbon::now('America/Mexico_City')->year);

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
     * Días festivos oficiales de México para un año dado. Las fechas fijas usan el año
     * directo; las "recorrido" (LFT art. 74: se recorren al lunes) se computan por año:
     * Constitución = 1er lunes de febrero, Benito Juárez = 3er lunes de marzo,
     * Revolución = 3er lunes de noviembre. (El día se toma vía format('Y-m-d'); la hora
     * del Carbon es irrelevante.)
     */
    public static function defaultHolidaysForYear(int $year): array
    {
        return [
            ['date' => "{$year}-01-01", 'name' => 'Año Nuevo', 'block_app' => false],
            ['date' => Carbon::create($year, 2, 1)->firstOfMonth(Carbon::MONDAY)->format('Y-m-d'), 'name' => 'Aniversario de la Constitución (recorrido)', 'block_app' => false],
            ['date' => Carbon::create($year, 3, 1)->nthOfMonth(3, Carbon::MONDAY)->format('Y-m-d'), 'name' => 'Natalicio de Benito Juárez (recorrido)', 'block_app' => false],
            ['date' => "{$year}-05-01", 'name' => 'Día del Trabajo', 'block_app' => false],
            ['date' => "{$year}-09-16", 'name' => 'Día de la Independencia', 'block_app' => false],
            ['date' => Carbon::create($year, 11, 1)->nthOfMonth(3, Carbon::MONDAY)->format('Y-m-d'), 'name' => 'Día de la Revolución (recorrido)', 'block_app' => false],
            ['date' => "{$year}-12-25", 'name' => 'Navidad', 'block_app' => false],
        ];
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
