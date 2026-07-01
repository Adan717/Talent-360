<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TaskAssignment;
use Carbon\Carbon;

class TaskAssignmentController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }
        
        $tenantId = $user->tenant_id ?? 1;
        $date = $request->input('date', Carbon::now()->format('Y-m-d'));
        
        $query = TaskAssignment::where('tenant_id', $tenantId)
            ->where('date', $date);

        if ($request->has('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        } else {
            $query->where('user_id', $user->id);
        }

        $assignments = $query->with('task')->get();

        return response()->json($assignments);
    }

    public function update(Request $request, $id)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }
        
        $tenantId = $user->tenant_id ?? 1;

        $assignment = TaskAssignment::where('tenant_id', $tenantId)
            ->findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|string|in:pending,in_progress,paused,completed,awaiting_validation,omitted,spilled',
            'assistant_data' => 'nullable',
            'accumulated_mins' => 'nullable|integer',
            'started_at_mins' => 'nullable|integer',
            'completed_at_mins' => 'nullable|integer',
            'validation_feedback' => 'nullable|string',
            'validated_by' => 'nullable|integer'
        ]);

        if (isset($validated['assistant_data']) && is_array($validated['assistant_data'])) {
            $validated['assistant_data'] = json_encode($validated['assistant_data']);
        }

        $assignment->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Asignación actualizada con éxito.',
            'assignment' => $assignment->load('task')
        ]);
    }
}
