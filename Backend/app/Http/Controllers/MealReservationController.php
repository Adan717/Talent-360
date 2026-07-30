<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\Employee;
use Carbon\Carbon;

/**
 * MealReservationController
 *
 * Controla la reserva de bloques de comida del comedor, validando:
 * - Aforo máximo configurable por tenant
 * - Que no haya dos empleados del mismo job_role saliendo al mismo tiempo (piso vacío)
 * - Swaps de comida entre empleados del mismo nivel de puesto
 * - Liberación reactiva al reportar inasistencia
 *
 * SPEC Pilar III: "El sistema calcula el aforo disponible y valida que dos empleados
 * con el mismo rol operativo no salgan a comer al mismo tiempo."
 */
class MealReservationController extends Controller
{
    // =========================================================
    // GET /api/v1/meal-reservations/slots
    // Retorna los bloques de comida disponibles para el día con aforo
    // =========================================================
    public function getSlots(Request $request)
    {
        $user     = Auth::user();
        $tenantId = $user->tenant_id ?? 1;
        $date     = $request->input('date', Carbon::today()->toDateString());

        $settings = DB::table('meal_capacity_settings')
            ->where('tenant_id', $tenantId)
            ->first();

        $maxCapacity = $settings?->max_capacity ?? 5;
        $availableSlots = $settings?->available_slots
            ? json_decode($settings->available_slots, true)
            : ['12:00', '13:00', '14:00', '15:00'];

        $slotDuration = $settings?->slot_duration_minutes ?? 60;

        // Contar reservas activas por slot
        $reservationCounts = DB::table('meal_reservations')
            ->where('tenant_id', $tenantId)
            ->where('reservation_date', $date)
            ->whereIn('status', ['reserved', 'confirmed'])
            ->select('slot_start', DB::raw('count(*) as count'))
            ->groupBy('slot_start')
            ->get()
            ->keyBy('slot_start');

        // Reserva actual del usuario
        $employee = Employee::where('user_id', $user->id)->first();
        $myReservation = DB::table('meal_reservations')
            ->where('user_id', $user->id)
            ->where('reservation_date', $date)
            ->whereIn('status', ['reserved', 'confirmed'])
            ->first();

        $slotData = [];
        foreach ($availableSlots as $slotStart) {
            $slotEnd   = Carbon::parse($slotStart)->addMinutes($slotDuration)->format('H:i');
            $booked    = $reservationCounts[$slotStart]->count ?? 0;
            $available = max(0, $maxCapacity - $booked);

            // Verificar restricción de mismo rol (piso vacío)
            $sameRoleCount = 0;
            if ($employee?->job_role_id) {
                $sameRoleCount = DB::table('meal_reservations')
                    ->where('tenant_id', $tenantId)
                    ->where('reservation_date', $date)
                    ->where('job_role_id', $employee->job_role_id)
                    ->where('slot_start', $slotStart)
                    ->whereIn('status', ['reserved', 'confirmed'])
                    ->count();
            }

            $slotData[] = [
                'slot_start'          => $slotStart,
                'slot_end'            => $slotEnd,
                'capacity'            => $maxCapacity,
                'booked'              => $booked,
                'available'           => $available,
                'is_full'             => $available <= 0,
                'same_role_blocked'   => $sameRoleCount > 0, // Restricción piso vacío
                'is_my_reservation'   => $myReservation?->slot_start === $slotStart,
            ];
        }

        return response()->json([
            'success'       => true,
            'date'          => $date,
            'my_reservation'=> $myReservation,
            'slots'         => $slotData,
            'max_capacity'  => $maxCapacity,
        ]);
    }

    // =========================================================
    // POST /api/v1/meal-reservations
    // Crear una reserva de comida
    // =========================================================
    public function store(Request $request)
    {
        $request->validate([
            'date'       => 'required|date',
            'slot_start' => 'required|date_format:H:i',
        ]);

        $user     = Auth::user();
        $tenantId = $user->tenant_id ?? 1;
        $date     = $request->date;
        $slotStart= $request->slot_start;

        $employee = Employee::where('user_id', $user->id)->first();

        return DB::transaction(function () use ($user, $tenantId, $date, $slotStart, $employee, $request) {
            $settings = DB::table('meal_capacity_settings')
                ->where('tenant_id', $tenantId)
                ->first();

            $maxCapacity  = $settings?->max_capacity ?? 5;
            $slotDuration = $settings?->slot_duration_minutes ?? 60;
            $slotEnd      = Carbon::parse($slotStart)->addMinutes($slotDuration)->format('H:i');

            // Verificar que no ya tenga reserva en este día
            $existing = DB::table('meal_reservations')
                ->where('user_id', $user->id)
                ->where('reservation_date', $date)
                ->whereIn('status', ['reserved', 'confirmed'])
                ->first();

            if ($existing) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ya tienes una reserva de comida para este día. Cancela la anterior para cambiarla.',
                ], 422);
            }

            // Verificar aforo
            $booked = DB::table('meal_reservations')
                ->where('tenant_id', $tenantId)
                ->where('reservation_date', $date)
                ->where('slot_start', $slotStart)
                ->whereIn('status', ['reserved', 'confirmed'])
                ->count();

            if ($booked >= $maxCapacity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Este horario de comedor está lleno. Por favor elige otro bloque.',
                ], 422);
            }

            // Verificar restricción de mismo rol (no dejar el piso vacío)
            if ($employee?->job_role_id) {
                $sameRoleCount = DB::table('meal_reservations')
                    ->where('tenant_id', $tenantId)
                    ->where('reservation_date', $date)
                    ->where('job_role_id', $employee->job_role_id)
                    ->where('slot_start', $slotStart)
                    ->whereIn('status', ['reserved', 'confirmed'])
                    ->count();

                if ($sameRoleCount > 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ya hay un compañero de tu mismo puesto en este horario. Para no dejar el piso sin cobertura, elige otro bloque.',
                    ], 422);
                }
            }

            // Crear reserva con snapshot histórico
            $id = DB::table('meal_reservations')->insertGetId([
                'tenant_id'              => $tenantId,
                'user_id'                => $user->id,
                'job_role_id'            => $employee?->job_role_id,
                'reservation_date'       => $date,
                'slot_start'             => $slotStart,
                'slot_end'               => $slotEnd,
                'status'                 => 'reserved',
                'employee_name_at_time'  => $user->name,
                'job_role_title_at_time' => $employee?->jobRole?->name ?? 'N/A',
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);

            return response()->json([
                'success'     => true,
                'message'     => "Reserva confirmada para el bloque {$slotStart} - {$slotEnd}.",
                'reservation' => DB::table('meal_reservations')->find($id),
            ]);
        });
    }

    // =========================================================
    // DELETE /api/v1/meal-reservations/{id}
    // Cancelar una reserva (liberación reactiva)
    // =========================================================
    public function cancel(Request $request, int $id)
    {
        $user = Auth::user();

        $reservation = DB::table('meal_reservations')
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$reservation) {
            return response()->json(['success' => false, 'message' => 'Reserva no encontrada.'], 404);
        }

        DB::table('meal_reservations')
            ->where('id', $id)
            ->update([
                'status'              => 'cancelled',
                'cancellation_reason' => $request->input('reason', 'Cancelado por el colaborador'),
                'updated_at'          => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Reserva cancelada. El aforo ha sido liberado para tus compañeros.',
        ]);
    }

    // =========================================================
    // POST /api/v1/meal-reservations/{id}/swap
    // Intercambiar reserva con otro empleado del mismo puesto
    // =========================================================
    public function swap(Request $request, int $id)
    {
        $request->validate([
            'target_user_id' => 'required|exists:users,id',
        ]);

        $user     = Auth::user();
        $tenantId = $user->tenant_id ?? 1;

        return DB::transaction(function () use ($user, $tenantId, $id, $request) {
            $myReservation = DB::table('meal_reservations')
                ->where('id', $id)
                ->where('user_id', $user->id)
                ->whereIn('status', ['reserved', 'confirmed'])
                ->first();

            if (!$myReservation) {
                return response()->json(['success' => false, 'message' => 'Reserva no encontrada.'], 404);
            }

            // Verificar que el target tiene el mismo job_role (mismo nivel de puesto)
            $myEmployee     = Employee::where('user_id', $user->id)->first();
            $targetEmployee = Employee::where('user_id', $request->target_user_id)->first();

            if ($myEmployee?->job_role_id !== $targetEmployee?->job_role_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solo puedes intercambiar horarios con compañeros del mismo puesto.',
                ], 422);
            }

            // Obtener la reserva del target (si tiene)
            $targetReservation = DB::table('meal_reservations')
                ->where('user_id', $request->target_user_id)
                ->where('reservation_date', $myReservation->reservation_date)
                ->whereIn('status', ['reserved', 'confirmed'])
                ->first();

            // Intercambio: marcar mi reserva como 'swapped' y crear nueva para el target
            DB::table('meal_reservations')->where('id', $id)->update([
                'status'            => 'swapped',
                'swapped_to_user_id'=> $request->target_user_id,
                'updated_at'        => now(),
            ]);

            // Si el target tenía reserva, intercambiarla a mí
            if ($targetReservation) {
                DB::table('meal_reservations')->where('id', $targetReservation->id)->update([
                    'user_id'                => $user->id,
                    'employee_name_at_time'  => $user->name,
                    'status'                 => 'reserved',
                    'updated_at'             => now(),
                ]);
            }

            // Crear nueva reserva para el target con mi slot original
            DB::table('meal_reservations')->insert([
                'tenant_id'              => $tenantId,
                'user_id'                => $request->target_user_id,
                'job_role_id'            => $targetEmployee?->job_role_id,
                'reservation_date'       => $myReservation->reservation_date,
                'slot_start'             => $myReservation->slot_start,
                'slot_end'               => $myReservation->slot_end,
                'status'                 => 'reserved',
                'employee_name_at_time'  => $targetEmployee?->user?->name ?? 'N/A',
                'job_role_title_at_time' => $targetEmployee?->jobRole?->name ?? 'N/A',
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Swap de comida realizado exitosamente.',
            ]);
        });
    }

    // =========================================================
    // §24: Cola Secuencial de Reserva de Comida — modo 'queue', convive con la
    // selección libre de arriba (decisión de Francisco: no la reemplaza).
    // =========================================================

    // GET /api/v1/meal-reservations/queue?date=&order_by=&store_id=
    public function getQueue(Request $request)
    {
        $user     = Auth::user();
        $tenantId = $user->tenant_id ?? 1;
        $date     = $request->input('date', Carbon::today()->toDateString());
        // H15: la sucursal se resuelve del tenant, no del cliente (con `stores` los ids son globales).
        $storeId  = \App\Helpers\TenantStore::defaultIdFor($tenantId);

        $round = DB::table('meal_queue_rounds')
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('date', $date)
            ->first();

        if (!$round) {
            $orderBy = in_array($request->input('order_by'), ['arrival', 'random']) ? $request->input('order_by') : 'arrival';

            $roundId = DB::table('meal_queue_rounds')->insertGetId([
                'tenant_id'  => $tenantId,
                'store_id'   => $storeId,
                'date'       => $date,
                'order_by'   => $orderBy,
                'status'     => 'open',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Elegibles: quienes ya ficharon check_in hoy (no se puede apartar turno de
            // comida si no se está en la sucursal). El orden 'arrival' usa esa misma hora.
            $employeeIds = DB::table('time_entries')
                ->where('tenant_id', $tenantId)
                ->where('date', $date)
                ->where('type', 'check_in')
                ->whereNull('simulation_session_id')
                ->orderBy('time')
                ->pluck('user_id')
                ->unique()
                ->values()
                ->all();

            if ($orderBy === 'random') {
                shuffle($employeeIds);
            }

            foreach ($employeeIds as $i => $employeeId) {
                DB::table('meal_queue_entries')->insert([
                    'round_id'   => $roundId,
                    'employee_id'=> $employeeId,
                    'position'   => $i + 1,
                    'status'     => $i === 0 ? 'choosing' : 'waiting',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $round = DB::table('meal_queue_rounds')->find($roundId);
        }

        $entries = DB::table('meal_queue_entries')
            ->where('round_id', $round->id)
            ->orderBy('position')
            ->get();

        $currentTurn = $entries->firstWhere('status', 'choosing');

        return response()->json([
            'mode'                     => 'queue',
            'order_by'                 => $round->order_by,
            'current_turn_employee_id' => $currentTurn->employee_id ?? null,
            'queue'                    => $entries->map(fn($e) => [
                'employee_id' => $e->employee_id,
                'status'      => $e->status,
                'slot_start'  => $e->slot_start,
                'slot_end'    => $e->slot_end,
            ])->values()->all(),
        ]);
    }

    // POST /api/v1/meal-reservations/queue/pick
    public function pickFromQueue(Request $request)
    {
        $request->validate([
            'date'       => 'required|date',
            'slot_start' => 'required|date_format:H:i',
            'store_id'   => 'nullable|integer',
        ]);

        $user     = Auth::user();
        $tenantId = $user->tenant_id ?? 1;
        $date     = $request->date;
        $storeId  = \App\Helpers\TenantStore::defaultIdFor($tenantId); // H15
        $slotStart= $request->slot_start;

        return DB::transaction(function () use ($user, $tenantId, $date, $storeId, $slotStart) {
            $round = DB::table('meal_queue_rounds')
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where('date', $date)
                ->where('status', 'open')
                ->first();

            if (!$round) {
                return response()->json(['success' => false, 'message' => 'No hay una ronda de comida abierta para este día.'], 404);
            }

            $myEntry = DB::table('meal_queue_entries')
                ->where('round_id', $round->id)
                ->where('employee_id', $user->id)
                ->first();

            if (!$myEntry || $myEntry->status !== 'choosing') {
                return response()->json(['success' => false, 'message' => 'No es tu turno de elegir horario todavía.'], 409);
            }

            $employee     = Employee::where('user_id', $user->id)->first();
            $settings     = DB::table('meal_capacity_settings')->where('tenant_id', $tenantId)->first();
            $maxCapacity  = $settings?->max_capacity ?? 5;
            $slotDuration = $settings?->slot_duration_minutes ?? 60;
            $slotEnd      = Carbon::parse($slotStart)->addMinutes($slotDuration)->format('H:i');

            // Mismas reglas que la reserva libre: aforo y "no dejar el piso vacío".
            $booked = DB::table('meal_reservations')
                ->where('tenant_id', $tenantId)
                ->where('reservation_date', $date)
                ->where('slot_start', $slotStart)
                ->whereIn('status', ['reserved', 'confirmed'])
                ->count();

            if ($booked >= $maxCapacity) {
                return response()->json(['success' => false, 'message' => 'Este horario de comedor está lleno. Elige otro bloque.'], 422);
            }

            if ($employee?->job_role_id) {
                $sameRoleCount = DB::table('meal_reservations')
                    ->where('tenant_id', $tenantId)
                    ->where('reservation_date', $date)
                    ->where('job_role_id', $employee->job_role_id)
                    ->where('slot_start', $slotStart)
                    ->whereIn('status', ['reserved', 'confirmed'])
                    ->count();

                if ($sameRoleCount > 0) {
                    return response()->json(['success' => false, 'message' => 'Ya hay un compañero de tu mismo puesto en este horario.'], 422);
                }
            }

            $reservationId = DB::table('meal_reservations')->insertGetId([
                'tenant_id'              => $tenantId,
                'user_id'                => $user->id,
                'job_role_id'            => $employee?->job_role_id,
                'reservation_date'       => $date,
                'slot_start'             => $slotStart,
                'slot_end'               => $slotEnd,
                'status'                 => 'reserved',
                'employee_name_at_time'  => $user->name,
                'job_role_title_at_time' => $employee?->jobRole?->name ?? 'N/A',
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);

            DB::table('meal_queue_entries')->where('id', $myEntry->id)->update([
                'status'     => 'done',
                'slot_start' => $slotStart,
                'slot_end'   => $slotEnd,
                'updated_at' => now(),
            ]);

            $next = DB::table('meal_queue_entries')
                ->where('round_id', $round->id)
                ->where('status', 'waiting')
                ->orderBy('position')
                ->first();

            if ($next) {
                DB::table('meal_queue_entries')->where('id', $next->id)->update(['status' => 'choosing', 'updated_at' => now()]);
            }

            event(new \App\Events\MealQueueTurnChanged($tenantId, $next->employee_id ?? null));

            return response()->json([
                'success'                => true,
                'message'                => "Turno elegido: {$slotStart} - {$slotEnd}.",
                'reservation'            => DB::table('meal_reservations')->find($reservationId),
                'next_turn_employee_id'  => $next->employee_id ?? null,
            ]);
        });
    }

    // =========================================================
    // POST /api/v1/meal-reservations/release-by-absence
    // Liberación reactiva al reportar inasistencia (llamado internamente)
    // =========================================================
    public function releaseByAbsence(int $userId, string $date): void
    {
        DB::table('meal_reservations')
            ->where('user_id', $userId)
            ->where('reservation_date', $date)
            ->whereIn('status', ['reserved', 'confirmed'])
            ->update([
                'status'              => 'cancelled',
                'cancellation_reason' => 'Cancelación automática por inasistencia reportada.',
                'updated_at'          => now(),
            ]);
    }
}
