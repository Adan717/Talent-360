<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\PayrollWeekService;

/**
 * Sección 2 #1: configuración de nómina y semana laboral por empresa. Conforme a LFT
 * (pago al menos semanal) pero cada tenant define su día de inicio de semana, día de
 * pago y hora del cálculo automático. El Reloj Checador usa week_start_day para
 * ordenar dinámicamente las tarjetas semanales.
 */
class PayrollSettingsController extends Controller
{
    public function get(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;
        $service = app(PayrollWeekService::class);

        return response()->json([
            'success' => true,
            'week_start_day' => $service->weekStartDay($tenantId), // 0=domingo .. 6=sábado
            'pay_day' => $service->payDay($tenantId),
            'calc_time' => $service->calcTime($tenantId),
            // Periodicidad de PAGO de la empresa (2026-08-03). `confirmada=false` significa que
            // nadie la ha declarado: el sistema viene asumiendo semanal de facto y el valor es
            // esa suposición etiquetada, no un dato. La interfaz debe pedir confirmarla.
            'periodicity' => self::periodicidadDe($tenantId),
            'periodicity_confirmed' => (bool) json_decode(
                (string) DB::table('system_settings')->where('tenant_id', $tenantId)
                    ->where('key', 'payroll_periodicity_confirmada')->value('value'), true
            ),
        ]);
    }

    /** La periodicidad de pago del tenant; 'semanal' si nunca se ha configurado (el statu quo). */
    public static function periodicidadDe(int $tenantId): string
    {
        $valor = json_decode((string) DB::table('system_settings')->where('tenant_id', $tenantId)
            ->where('key', 'payroll_periodicity')->value('value'), true);

        return in_array($valor, ['semanal', 'quincenal', 'mensual'], true) ? $valor : 'semanal';
    }

    public function save(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;

        $validated = $request->validate([
            'week_start_day' => 'nullable|integer|between:0,6',
            'pay_day' => 'nullable|integer|between:0,6',
            'calc_time' => 'nullable|string|regex:/^\d{2}:\d{2}$/',
            'periodicity' => 'nullable|string|in:semanal,quincenal,mensual',
        ]);

        // Declarar la periodicidad (aunque sea confirmar la semanal que se venía asumiendo)
        // la marca como CONFIRMADA: a partir de ahí es un dato del cliente, no una suposición.
        if (!empty($validated['periodicity'])) {
            DB::table('system_settings')->updateOrInsert(
                ['tenant_id' => $tenantId, 'key' => 'payroll_periodicity_confirmada'],
                ['value' => json_encode(true), 'updated_at' => now(), 'created_at' => now()]
            );
        }

        $map = [
            'week_start_day' => 'payroll_week_start_day',
            'pay_day' => 'payroll_pay_day',
            'calc_time' => 'payroll_calc_time',
            'periodicity' => 'payroll_periodicity',
        ];

        foreach ($map as $input => $key) {
            if (!array_key_exists($input, $validated) || $validated[$input] === null) {
                continue;
            }
            DB::table('system_settings')->updateOrInsert(
                ['tenant_id' => $tenantId, 'key' => $key],
                ['value' => json_encode($validated[$input]), 'updated_at' => now(), 'created_at' => now()]
            );
        }

        return response()->json(['success' => true, 'message' => 'Configuración de nómina guardada.']);
    }
}
