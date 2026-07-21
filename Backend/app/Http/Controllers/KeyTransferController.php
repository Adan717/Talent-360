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

        // portadorLlaves vive en `employees`, no en `users` (migrate_existing_users_to_employees_table).
        $senderPortadorLlaves = $user->employee?->portadorLlaves;
        if (!$senderPortadorLlaves || strtolower($senderPortadorLlaves) === 'ninguno') {
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

        $transfers = KeyTransfer::with(['sender:id,name,role', 'sender.employee:user_id,portadorLlaves'])
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
            try {
                DB::transaction(function () use ($transfer, $user) {
                // Obtener el emisor
                $sender = User::findOrFail($transfer->sender_id);

                // portadorLlaves vive en `employees`, no en `users`.
                $senderEmployee = $sender->employee;
                $receiverEmployee = $user->employee;

                if (!$senderEmployee || !$receiverEmployee) {
                    throw new \Exception('No se encontró el perfil de empleado del emisor o del receptor.');
                }

                // Traspaso de roles de llaves
                $llavesType = $senderEmployee->portadorLlaves ?? 'Principal';

                $receiverEmployee->portadorLlaves = $llavesType;
                $receiverEmployee->save();

                $senderEmployee->portadorLlaves = 'Ninguno';
                $senderEmployee->save();

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
            } catch (\Exception $e) {
                return response()->json(['error' => $e->getMessage()], 422);
            }

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
