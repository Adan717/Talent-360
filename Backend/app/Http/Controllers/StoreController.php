<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\StoreLog;
use App\Models\User;
use App\Events\MonitorUpdated;

class StoreController extends Controller
{
    public function sync(Request $request)
    {
        $userId = $request->input('user_id');
        
        // El trait Tenantable en User verifica automáticamente que pertenezca al tenant actual
        $user = User::findOrFail($userId);

        $date = $request->input('date');
        $type = $request->input('type');
        $time = $request->input('time');
        $notes = $request->input('notes');

        StoreLog::create([
            'user_id' => $userId,
            'date' => $date,
            'type' => $type,
            'time' => $time,
            'notes' => $notes,
            'tenant_id' => $user->tenant_id,
        ]);

        event(new MonitorUpdated($user->tenant_id));

        return response()->json(['message' => 'Store log synced.']);
    }
}
