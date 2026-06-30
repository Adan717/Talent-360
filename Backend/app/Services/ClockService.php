<?php

namespace App\Services;

use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;

class ClockService
{
    public function processPunch(User $user, $type, $simTime = null, $details = [])
    {
        $settings = \DB::table('system_settings')->pluck('value', 'key')->toArray();
        $isSimulated = isset($settings['time_mode']) ? json_decode($settings['time_mode'], true) === 'simulated' : false;

        $date = Carbon::now()->format('Y-m-d');
        if ($isSimulated && $simTime) {
            // El simulador envía algo como "09:30:00", usamos eso
            $time = $simTime;
            $now = Carbon::createFromFormat('Y-m-d H:i:s', "$date $time");
        } else {
            $now = Carbon::now();
            $time = $now->format('H:i:s');
        }

        // Obtener la política de horario del empleado
        $expectedTimeStr = $user->shiftStart ?? '09:00:00';
        $expectedTime = Carbon::createFromFormat('H:i:s', $expectedTimeStr);
        $toleranceMinutes = 10;

        $isLate = false;
        $lateMinutes = 0;

        if ($type === 'check_in') {
            if ($now->greaterThan($expectedTime->copy()->addMinutes($toleranceMinutes))) {
                $isLate = true;
                $lateMinutes = $now->diffInMinutes($expectedTime);
            }
        }

        // Crear registro en la BD
        $entry = TimeEntry::create([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id ?? 1,
            'date' => $date,
            'type' => $type,
            'time' => $time,
            'is_late' => $isLate,
            'late_minutes' => $lateMinutes,
            'details' => json_encode($details)
        ]);
        
        // Registrar en audit log si es check_in o check_out u otros eventos importantes
        \DB::table('audit_logs')->insert([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id ?? 1,
            'date' => $date,
            'type' => $type,
            'timestamp_str' => "$date $time",
            'reason' => "Fichaje de tipo: $type",
            'punishment_amount' => $isLate ? ($lateMinutes * 2) : 0,
            'details' => json_encode($details),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return [
            'success' => true,
            'message' => $isLate 
                ? "Registro guardado con retardo de {$lateMinutes} minutos." 
                : "Registro exitoso.",
            'entry' => $entry
        ];
    }

    /**
     * Calcular deducciones monetarias para la pre-nómina.
     */
    public function calculateLatePenalty(User $user, $startDate, $endDate)
    {
        $entries = TimeEntry::where('user_id', $user->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->where('is_late', true)
            ->get();

        $totalLateMinutes = $entries->sum('late_minutes');
        
        // Simulación: $2 MXN de descuento por cada minuto de retardo
        $penaltyAmount = $totalLateMinutes * 2;

        return [
            'total_late_minutes' => $totalLateMinutes,
            'penalty_amount' => $penaltyAmount,
            'lates_count' => $entries->count()
        ];
    }
}
