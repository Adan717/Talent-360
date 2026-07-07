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
        
        // Obtener las políticas LFT del Tenant
        $lft = \App\Models\LftSetting::where('tenant_id', $user->tenant_id)->first();
        $toleranceMinutes = $lft ? $lft->late_tolerance_minutes : 10;
        $lateActionMode = $lft ? $lft->late_action_mode : 'deduct';

        $isLate = false;
        $lateMinutes = 0;
        $hasAmnesty = isset($details['has_amnesty']) && $details['has_amnesty'] === true;

        if ($type === 'check_in' && !$hasAmnesty) {
            if ($now->greaterThan($expectedTime->copy()->addMinutes($toleranceMinutes))) {
                $isLate = true;
                $lateMinutes = $now->diffInMinutes($expectedTime);
            }
        }

        // Estructurar detalles del retardo o compensación
        $detailsMerge = $details;
        if ($hasAmnesty) {
            $detailsMerge['amnesty_applied'] = true;
            $detailsMerge['amnesty_note'] = 'Amnistía aplicada por apertura tardía de la sucursal.';
        }
        
        if ($isLate) {
            $detailsMerge['lft_incident'] = [
                'type' => 'late',
                'minutes' => $lateMinutes,
                'action_mode' => $lateActionMode,
                'notes' => $lateActionMode === 'extend_shift' 
                    ? "Retardo de {$lateMinutes} min. Compensación requerida: salir {$lateMinutes} min tarde." 
                    : "Retardo de {$lateMinutes} min. Descuento de salario o penalización acumulada."
            ];
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
            'details' => json_encode($detailsMerge)
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
            'details' => json_encode($detailsMerge),
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

    /**
     * Calcula la nómina detallada y el desglose de LFT de un colaborador.
     */
    public function calculatePayrollForEmployee($employee, $startDate, $endDate)
    {
        $tenantId = $employee->tenant_id;
        
        // 1. Obtener la política LFT
        $lft = \App\Models\LftSetting::where('tenant_id', $tenantId)->first();
        if (!$lft) {
            $lft = \App\Models\LftSetting::create([
                'tenant_id' => $tenantId,
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
            ]);
        }
        
        $baseSalary = $employee->base_salary ?? $employee->salary ?? 2400.00;
        $dailySalary = $baseSalary / 6.0; // 6 días de trabajo devengan 1 de descanso
        
        // 2. Obtener registros de asistencia
        $entries = TimeEntry::where('user_id', $employee->user_id)
            ->whereBetween('date', [$startDate, $endDate])
            ->get();
            
        // 3. Agrupar por día
        $entriesByDate = $entries->groupBy('date');
        
        $latesCount = 0;
        $lateMinutes = 0;
        $mealExcessMinutes = 0;
        $restExcessMinutes = 0;
        
        foreach ($entries as $entry) {
            if ($entry->is_late) {
                $latesCount++;
                $lateMinutes += $entry->late_minutes;
            }
            // Analizar excesos de comida y descanso
            $details = json_decode($entry->details, true);
            if ($entry->type === 'meal_end' && isset($details['duration_minutes'])) {
                $mealMins = (int)$details['duration_minutes'];
                $allowedMeal = $employee->mealMinutes ?? 60;
                if ($mealMins > ($allowedMeal + $lft->meal_tolerance_minutes)) {
                    $mealExcessMinutes += max(0, $mealMins - $allowedMeal);
                }
            }
        }
        
        // 4. Calcular Faltas
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        
        $expectedDaysCount = 0;
        $physicalAbsences = 0;
        
        $dayMap = [
            'domingo' => 0, 'sunday' => 0,
            'lunes' => 1, 'monday' => 1,
            'martes' => 2, 'tuesday' => 2,
            'miercoles' => 3, 'wednesday' => 3,
            'jueves' => 4, 'thursday' => 4,
            'viernes' => 5, 'friday' => 5,
            'sabado' => 6, 'saturday' => 6,
        ];
        $restDayName = strtolower($employee->restDay ?? 'domingo');
        $restDayOfWeek = $dayMap[$restDayName] ?? 0;
        
        $current = $start->copy();
        $analysedDates = [];
        while ($current->lte($end)) {
            $dateStr = $current->toDateString();
            $analysedDates[] = $dateStr;
            
            if ($current->dayOfWeek !== $restDayOfWeek) {
                $expectedDaysCount++;
                if (!isset($entriesByDate[$dateStr])) {
                    $physicalAbsences++;
                }
            }
            $current->addDay();
        }
        
        // Faltas equivalentes por retardos
        $absencesFromLates = $lft->lates_per_absence > 0 
            ? (int)floor($latesCount / $lft->lates_per_absence) 
            : 0;
            
        $totalAbsences = $physicalAbsences + $absencesFromLates;
        
        // 5. Calcular Proporcional del Séptimo Día (día de descanso)
        $restDayProportion = 1.0;
        if ($lft->proportional_rest_day && $totalAbsences > 0) {
            $workedDays = max(0, 6 - $totalAbsences);
            $restDayProportion = $workedDays / 6.0;
        }
        
        // 6. Calcular Deducciones
        $deductionAbsence = 0;
        if ($lft->deduct_absence_day) {
            $deductionAbsence = $totalAbsences * $dailySalary;
        }
        
        $deductionRestDay = 0;
        if ($lft->paid_rest_day) {
            $deductionRestDay = (1.0 - $restDayProportion) * $dailySalary;
        }
        
        // Penalización por minutos tarde si es deduct y no compensó
        $deductionLates = 0;
        if ($lft->late_action_mode === 'deduct') {
            $deductionLates = $lateMinutes * 2; // Simulación: $2 MXN de descuento por minuto
        }
        
        $totalDeductions = $deductionAbsence + $deductionRestDay + $deductionLates;
        
        // Sueldo bruto para los 7 días (6 trabajados + 1 descanso)
        $grossPay = $dailySalary * 7;
        $netPay = max(0, $grossPay - $totalDeductions);
        
        // Obtener estatus de aprobaciones diarias
        $dailyApprovals = \App\Models\DailyApproval::where('employee_id', $employee->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->get()
            ->keyBy('date');
            
        $datesDetails = [];
        foreach ($analysedDates as $dStr) {
            $cDate = Carbon::parse($dStr);
            $isRestDay = ($cDate->dayOfWeek === $restDayOfWeek);
            $hasEntries = isset($entriesByDate[$dStr]);
            
            $datesDetails[] = [
                'date' => $dStr,
                'day_name' => $cDate->translatedFormat('l'),
                'is_rest_day' => $isRestDay,
                'has_entries' => $hasEntries,
                'entries' => $hasEntries ? $entriesByDate[$dStr]->toArray() : [],
                'approval_status' => $dailyApprovals->has($dStr) 
                    ? $dailyApprovals[$dStr]->status 
                    : 'pending',
                'comments' => $dailyApprovals->has($dStr) 
                    ? $dailyApprovals[$dStr]->comments 
                    : null
            ];
        }

        // 6. Calcular Rendimiento de Tareas
        $assignments = \DB::table('task_assignments')
            ->where('user_id', $employee->user_id)
            ->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->get();

        $totalTasks = $assignments->count();
        $completedTasksOnTime = 0;
        foreach ($assignments as $asn) {
            if ($asn->status === 'completed') {
                $completedAt = $asn->completed_at_mins ?? 0;
                $expectedEnd = $asn->expected_end_time_mins ?? 0;
                if ($expectedEnd === 0 || $completedAt <= $expectedEnd) {
                    $completedTasksOnTime++;
                }
            }
        }
        $taskPerformancePct = $totalTasks > 0 ? (int)round(($completedTasksOnTime / $totalTasks) * 100) : 100;

        // 7. Calcular Score Global de Rendimiento (Evaluación Ley/Comportamiento)
        // Base 100, restamos 15 por falta, 5 por retardo, 1 por cada 5 min de exceso en comida/descanso
        $attendanceScore = 100 - ($totalAbsences * 15) - ($latesCount * 5) - (int)floor($mealExcessMinutes / 5) - (int)floor($restExcessMinutes / 5);
        $attendanceScore = max(0, $attendanceScore);
        
        // El score global es 60% asistencia/puntualidad y 40% desempeño de tareas
        $performanceScore = (int)round(($attendanceScore * 0.6) + ($taskPerformancePct * 0.4));
        $performanceScore = max(0, min(100, $performanceScore));
        
        // Buscar si ya se guardó y aprobó la nómina de esta semana
        $weeklyPayrollRecord = \App\Models\WeeklyPayroll::where('employee_id', $employee->id)
            ->where('start_date', $startDate)
            ->where('end_date', $endDate)
            ->first();
            
        return [
            'employee_id' => $employee->id,
            'name' => $employee->name,
            'period' => [
                'start' => $startDate,
                'end' => $endDate
            ],
            'salary' => [
                'base' => (float)$baseSalary,
                'daily' => (float)$dailySalary,
                'gross' => (float)$grossPay,
                'net' => (float)$netPay
            ],
            'incidents' => [
                'lates' => $latesCount,
                'late_minutes' => $lateMinutes,
                'physical_absences' => $physicalAbsences,
                'absences_from_lates' => $absencesFromLates,
                'total_absences' => $totalAbsences,
                'rest_day_proportion' => (float)$restDayProportion,
                'meal_excess_minutes' => $mealExcessMinutes,
                'rest_excess_minutes' => $restExcessMinutes,
            ],
            'performance' => [
                'meal_overtime_mins' => $mealExcessMinutes,
                'break_overtime_mins' => $restExcessMinutes,
                'task_performance_pct' => $taskPerformancePct,
                'performance_score' => $performanceScore
            ],
            'deductions_breakdown' => [
                'absences' => (float)$deductionAbsence,
                'rest_day' => (float)$deductionRestDay,
                'lates' => (float)$deductionLates,
                'total' => (float)$totalDeductions
            ],
            'days_details' => $datesDetails,
            'approval' => [
                'is_approved' => $weeklyPayrollRecord ? ($weeklyPayrollRecord->status === 'approved_by_employee' || $weeklyPayrollRecord->status === 'finalized') : false,
                'status' => $weeklyPayrollRecord ? $weeklyPayrollRecord->status : 'pending_employee',
                'approved_at' => $weeklyPayrollRecord ? $weeklyPayrollRecord->employee_approved_at : null
            ]
        ];
    }
}
