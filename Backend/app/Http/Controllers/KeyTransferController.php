<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\KeyTransfer;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class KeyTransferController extends Controller
{
    // Crear transferencia
    public function store(Request $request)
    {
        $request->validate([
            'receiver_id' => 'required|exists:users,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        $user = Auth::user();

        if ($user->id == $request->input('receiver_id')) {
            return response()->json(['error' => 'No puedes transferirte las llaves a ti mismo.'], 422);
        }

        if (strtolower($user->portadorLlaves) === 'ninguno' || !$user->portadorLlaves) {
            return response()->json(['error' => 'No posees permisos de portador de llaves en este momento.'], 403);
        }

        // Cancelamos transferencias previas pendientes de este emisor
        KeyTransfer::where('sender_id', $user->id)
            ->where('status', 'pending')
            ->update(['status' => 'rejected']);

        $transfer = KeyTransfer::create([
            'tenant_id' => $user->tenant_id,
            'sender_id' => $user->id,
            'receiver_id' => $request->input('receiver_id'),
            'notes' => $request->input('notes'),
            'status' => 'pending'
        ]);

        return response()->json([
            'message' => 'Solicitud de transferencia enviada.',
            'transfer' => $transfer
        ], 201);
    }

    // Listar transferencias pendientes dirigidas al usuario actual
    public function pending()
    {
        $user = Auth::user();

        $transfers = KeyTransfer::with('sender:id,name,role,portadorLlaves')
            ->where('receiver_id', $user->id)
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($transfers);
    }

    // Responder a transferencia
    public function respond(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:accepted,rejected',
        ]);

        $user = Auth::user();
        $transfer = KeyTransfer::where('receiver_id', $user->id)
            ->where('status', 'pending')
            ->findOrFail($id);

        $status = $request->input('status');

        if ($status === 'accepted') {
            DB::transaction(function () use ($transfer, $user) {
                // Obtener el emisor
                $sender = User::findOrFail($transfer->sender_id);
                
                // Traspaso de roles de llaves
                $llavesType = $sender->portadorLlaves ?? 'Principal';
                
                $user->portadorLlaves = $llavesType;
                $user->save();

                $sender->portadorLlaves = 'Ninguno';
                $sender->save();

                $transfer->status = 'accepted';
                $transfer->save();

                // Rechazamos otras posibles solicitudes pendientes dirigidas al mismo emisor o receptor
                KeyTransfer::where('id', '!=', $transfer->id)
                    ->where(function($query) use ($sender, $user) {
                        $query->where('sender_id', $sender->id)
                              ->orWhere('sender_id', $user->id)
                              ->orWhere('receiver_id', $sender->id)
                              ->orWhere('receiver_id', $user->id);
                    })
                    ->where('status', 'pending')
                    ->update(['status' => 'rejected']);
            });

            return response()->json([
                'message' => 'Transferencia aceptada. Ahora eres portador de llaves.',
                'transfer' => $transfer
            ]);
        } else {
            $transfer->status = 'rejected';
            $transfer->save();

            return response()->json([
                'message' => 'Transferencia rechazada.',
                'transfer' => $transfer
            ]);
        }
    }
}
