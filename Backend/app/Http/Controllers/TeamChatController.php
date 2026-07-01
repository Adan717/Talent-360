<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\TeamChatMessage;
use Illuminate\Support\Facades\Auth;

class TeamChatController extends Controller
{
    public function index()
    {
        // El trait Tenantable ya filtra automáticamente por tenant_id
        $messages = TeamChatMessage::with('user:id,name,role,avatar')
            ->where('created_at', '>=', now()->subDays(7))
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json($messages);
    }

    public function store(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:1000',
        ]);

        $user = Auth::user();

        $message = TeamChatMessage::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'message' => $request->input('message'),
        ]);

        $message->load('user:id,name,role,avatar');

        return response()->json($message, 201);
    }
}
