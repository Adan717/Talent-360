<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Scopes\TenantScope;
use App\Support\RetencionChat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Chat de equipo del RELOJ (la herramienta "Chat Grupal de Sucursal" del dial).
 *
 * HALLAZGO (fase 12 del guion, 2026-08-22): había DOS chats con el mismo nombre y ninguno de los
 * dos lo decía. El Monitor 360 escribía y leía `internal_messages`; el dial escribía y leía
 * `team_chat_messages`, otra tabla. Comprobado en vivo con el tenant 4: el jefe mandó un mensaje
 * de equipo desde el Monitor y el colaborador no lo vio nunca; el colaborador escribió desde su
 * reloj y el Monitor no lo recibió jamás. Las dos pantallas confirmaban el envío. Lo único que
 * cruzaba era el mensaje PRIVADO, porque el dial lo pinta aparte desde `/sync/state`.
 *
 * Ahora este controlador consume `internal_messages`, que es el chat real: el que tiene
 * destinatario privado, retención configurable (7–30 días, `RetencionChat`), mensajes preservados
 * y aislamiento por empresa. `team_chat_messages` queda muerta a propósito; NO se borra (contiene
 * lo que la gente escribió creyendo que se enviaba) y la purga nocturna la sigue barriendo.
 */
class TeamChatController extends Controller
{
    /** Lo que le toca ver a cada quien: los de equipo, los que mandó y los que le mandaron. */
    private function visiblesPara($query, int $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->whereNull('internal_messages.receiver_id')
                ->orWhere('internal_messages.receiver_id', $userId)
                ->orWhere('internal_messages.sender_id', $userId);
        });
    }

    private function pinta($msg, int $userId): array
    {
        return [
            'id' => $msg->id,
            'user_id' => $msg->sender_id,
            'message' => $msg->content,
            'created_at' => $msg->created_at,
            // Un privado se marca como tal: en un hilo compartido, no saber quién más lo lee es
            // justo lo que hace que la gente escriba de más.
            'es_privado' => $msg->receiver_id !== null,
            'receiver_id' => $msg->receiver_id,
            'receiver_name' => $msg->receiver_name,
            'para_mi' => (int) $msg->receiver_id === $userId,
            'user' => [
                'id' => $msg->sender_id,
                'name' => $msg->sender_name,
                'role' => $msg->sender_role,
                'avatar' => $msg->sender_avatar,
            ],
        ];
    }

    private function seleccion($query)
    {
        return $query
            ->leftJoin('users', 'users.id', '=', 'internal_messages.sender_id')
            ->leftJoin('users as destinatarios', 'destinatarios.id', '=', 'internal_messages.receiver_id')
            ->select(
                'internal_messages.id',
                'internal_messages.sender_id',
                'internal_messages.receiver_id',
                'internal_messages.content',
                'internal_messages.type',
                'internal_messages.created_at',
                'internal_messages.preserved_at',
                'users.name as sender_name',
                'users.role as sender_role',
                'users.avatar as sender_avatar',
                'destinatarios.name as receiver_name'
            );
    }

    public function index()
    {
        $user = Auth::user();
        $dias = RetencionChat::dias((int) $user->tenant_id);

        $query = DB::table('internal_messages')
            ->where('internal_messages.tenant_id', $user->tenant_id)
            // Los mensajes del Simulador Matrix no son de nadie: no se mezclan con los reales.
            ->whereNull('internal_messages.simulation_session_id')
            ->where(function ($q) use ($dias) {
                $q->where('internal_messages.created_at', '>=', now()->subDays($dias))
                    ->orWhereNotNull('internal_messages.preserved_at');
            });

        $mensajes = $this->seleccion($this->visiblesPara($query, (int) $user->id))
            // desc + limit y se invierte en memoria: con asc + limit SQL devuelve los 50 más
            // VIEJOS y el hilo se queda pegado en la conversación de la semana pasada (el mismo
            // defecto que ya se corrigió en el Monitor).
            ->orderBy('internal_messages.created_at', 'desc')
            ->limit(50)
            ->get()
            ->reverse()
            ->values()
            ->map(fn ($m) => $this->pinta($m, (int) $user->id))
            ->all();

        return response()->json([
            'messages' => $mensajes,
            // La pantalla anunciaba "expiran en 7 días" fijo, aunque la empresa tuviera 30.
            'retention_days' => $dias,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:1000',
            'receiver_id' => 'nullable|integer',
        ]);

        $user = Auth::user();
        $receiverId = $request->input('receiver_id') ?: null;

        if ($receiverId) {
            $existe = User::withoutGlobalScope(TenantScope::class)
                ->where('id', $receiverId)
                ->where('tenant_id', $user->tenant_id)
                ->exists();

            if (!$existe) {
                return response()->json(['error' => 'El destinatario no pertenece a su organización.'], 403);
            }
        }

        // El remitente es la SESIÓN, nunca un dato del cliente (misma regla que `/sync/message`:
        // con el id del jefe en el cuerpo, cualquiera firmaba mensajes con su nombre).
        $id = DB::table('internal_messages')->insertGetId([
            'tenant_id' => $user->tenant_id,
            'sender_id' => $user->id,
            'receiver_id' => $receiverId,
            'type' => $receiverId ? 'private' : 'general',
            'content' => $request->input('message'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $msg = $this->seleccion(DB::table('internal_messages')->where('internal_messages.id', $id))->first();

        return response()->json($this->pinta($msg, (int) $user->id), 201);
    }
}
