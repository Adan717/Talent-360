<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\KeyTransfer;
use App\Models\User;
use App\Models\Employee;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class KeyTransferController extends Controller
{
    // Crear transferencia
    public function store(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            // El receptor debe existir Y pertenecer al mismo tenant del emisor.
            // (La regla 'exists' plana usa SQL crudo e ignora el TenantScope, por eso
            // se acota explícitamente por tenant_id para evitar fuga cross-tenant.)
            'receiver_id' => [
                'required',
                Rule::exists('users', 'id')->where('tenant_id', $user->tenant_id),
            ],
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($user->id == $request->input('receiver_id')) {
            return response()->json(['error' => 'No puedes transferirte las llaves a ti mismo.'], 422);
        }

        // La custodia de llaves vive en employees.portadorLlaves (users.portadorLlaves
        // fue eliminada). Se lee vía el employee vinculado del emisor.
        $holder = $user->employee?->portadorLlaves;
        if (!$holder || strtolower($holder) === 'ninguno') {
            return response()->json(['error' => 'No posees permisos de portador de llaves en este momento.'], 403);
        }

        // El receptor debe tener expediente de empleado para poder recibir la custodia;
        // si no, la transferencia quedaría atascada en 'pending' (nadie puede aceptarla).
        $receiverHasEmployee = Employee::where('user_id', $request->input('receiver_id'))
            ->where('tenant_id', $user->tenant_id)
            ->exists();
        if (!$receiverHasEmployee) {
            return response()->json(['error' => 'El receptor no puede recibir llaves (sin expediente de empleado).'], 422);
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

        // sender:id,name,role — NO se selecciona portadorLlaves (columna eliminada de users).
        $transfers = KeyTransfer::with('sender:id,name,role')
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
            $sender = User::findOrFail($transfer->sender_id);

            // La custodia se guarda en employees.portadorLlaves; el receptor necesita un
            // expediente de empleado para poder recibirla. Si no lo tiene, se rechaza la
            // transferencia (para no dejarla atascada en 'pending').
            if (!$user->employee()->exists()) {
                $transfer->status = 'rejected';
                $transfer->save();
                return response()->json(['error' => 'No tienes expediente de empleado para recibir las llaves.'], 422);
            }

            DB::transaction(function () use (&$transfer, $user, $sender) {
                // Lock + re-check para evitar doble-accept concurrente.
                $locked = KeyTransfer::where('id', $transfer->id)
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->first();
                if (!$locked) {
                    return; // otra petición ya la procesó
                }
                $transfer = $locked;

                // Se re-leen frescos y con lock dentro de la transacción para evitar
                // lost-updates (guardar snapshots viejos) y races.
                $senderEmployee = $sender->employee()->lockForUpdate()->first();
                $receiverEmployee = $user->employee()->lockForUpdate()->first();

                // Fail-safe: si no se puede verificar la custodia del emisor (p.ej. su
                // expediente fue eliminado tras crear la solicitud), NO otorgar el valor
                // máximo — dejar 'ninguno', consistente con el guard de store().
                $llavesType = $senderEmployee?->portadorLlaves ?? 'ninguno';

                if ($receiverEmployee) {
                    $receiverEmployee->portadorLlaves = $llavesType;
                    $receiverEmployee->save();
                }
                if ($senderEmployee) {
                    $senderEmployee->portadorLlaves = 'ninguno';
                    $senderEmployee->save();
                }

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
