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
            'feedback' => 'nullable|string'
        ]);

        $assignment = TaskAssignment::findOrFail($id);
        $employee = User::findOrFail($assignment->user_id);
        $validator = auth()->user();
        $userId = $validator->id;
        $tenantId = $validator->tenant_id ?? 1;

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
            $assignment->update([
                'status' => 'completed',
                'validation_feedback' => null,
                'validated_by' => $userId,
            ]);

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
                'message' => 'Tarea aprobada con éxito.',
                'status' => 'completed'
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
