<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TaskAssignment;
use App\Models\Task;
use App\Models\User;
use App\Jobs\LogTaskValidationJob;

class TaskValidationController extends Controller
{
    public function validateAssignment(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:completed,in_progress',
            'feedback' => 'nullable|string',
            'score_percentage' => 'nullable|integer|min:0|max:100'
        ]);

        $assignment = TaskAssignment::findOrFail($id);
        $employee = User::findOrFail($assignment->user_id);
        $validator = auth()->user();
        $userId = $validator->id;
        $tenantId = $validator->tenant_id ?? 1;
        $scorePct = $request->input('score_percentage', 100);

        // 1. Prevent self-validation
        if ($userId === $employee->id) {
            return response()->json(['error' => 'No puedes validar tus propias tareas.'], 403);
        }

        // 2. Hierarchical permission check
        $isAuthorized = false;
        if ($validator->role === 'admin' || $validator->role === 'platform_admin') {
            $isAuthorized = true;
        } else {
            $validatorJobRole = $validator->employee ? $validator->employee->jobRole : null;
            $employeeJobRole = $employee->employee ? $employee->employee->jobRole : null;
            
            if ($validatorJobRole && $employeeJobRole) {
                $isAuthorized = $validatorJobRole->isSupervisorOf($employeeJobRole);
            }
        }

        if (!$isAuthorized) {
            return response()->json(['error' => 'No tienes permisos de supervisor para validar esta tarea.'], 403);
        }

        // 3. Update state in database
        if ($request->status === 'completed') {
            $task = Task::find($assignment->task_id);
            $basePoints = $task ? ($task->points ?? 10) : 10;
            $awardedPoints = round(($basePoints * $scorePct) / 100);
            $coinsEarned = round($awardedPoints * 0.10, 2);

            $assignment->update([
                'status' => 'completed',
                'validation_feedback' => $request->feedback,
                'validated_by' => $userId,
                'score_percentage' => $scorePct,
                'points_awarded' => $awardedPoints,
                'coins_awarded' => $coinsEarned,
            ]);

            // Depositar recompensa aprobada por el supervisor en el Monedero Digital
            $wallet = \App\Models\UserWallet::getOrCreateForUser($employee->id, $tenantId);
            $wallet->deposit(
                $coinsEarned,
                $awardedPoints,
                'earned_task',
                "Recompensa validada por supervisor ({$scorePct}%): " . ($task->title ?? 'Tarea Operativa'),
                'TaskAssignment',
                $assignment->id
            );

            // Dispatch background Job for logging and WebSockets
            LogTaskValidationJob::dispatch(
                $assignment->user_id,
                $assignment->task_id,
                $userId,
                $tenantId,
                'completed'
            );

            return response()->json([
                'success' => true,
                'message' => "Tarea aprobada con éxito ({$scorePct}% calificación - +{$coinsEarned} monedas).",
                'status' => 'completed',
                'points_awarded' => $awardedPoints,
                'coins_awarded' => $coinsEarned,
                'score_percentage' => $scorePct
            ]);
        } else {
            // Rejected: return to 'in_progress'
            $assignment->update([
                'status' => 'in_progress',
                'validation_feedback' => $request->feedback,
                'validated_by' => $userId,
                'completed_at_mins' => null,
            ]);

            // Dispatch background Job for logging and WebSockets
            LogTaskValidationJob::dispatch(
                $assignment->user_id,
                $assignment->task_id,
                $userId,
                $tenantId,
                'in_progress',
                $request->feedback
            );

            return response()->json([
                'success' => true,
                'message' => 'Tarea rechazada y devuelta a "En Progreso".',
                'status' => 'in_progress',
                'feedback' => $request->feedback
            ]);
        }
    }
}
