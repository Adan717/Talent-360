<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SupportTicket;
use App\Models\SupportTicketNote;
use App\Models\PlatformUser;
use App\Enums\UserRole;

class SupportTicketController extends Controller
{
    /**
     * Verify if the authenticated user has support access (platform_admin or support_agent).
     */
    private function checkAccess()
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        // The user could be in platform_users table or a general User model with these roles
        return in_array($user->role, [UserRole::PLATFORM_ADMIN->value, UserRole::SUPPORT_AGENT->value]);
    }

    /**
     * Sugerencia de función desde el Monitor del cliente (admin/supervisor del tenant).
     *
     * El botón "Sugerir una función a nuestro equipo" abría un prompt() y respondía "tu
     * sugerencia ha sido enviada al equipo de desarrollo" SIN mandar nada a ningún lado:
     * el texto se descartaba en el acto. Ahora aterriza en la misma bandeja de tickets
     * que ya usa Plataforma.
     *
     * `created_by` va NULL a propósito: esa columna apunta a `platform_users` y aquí el
     * autor es un `users` del tenant (confundir los id-spaces es la familia §29/§30). El
     * autor viaja en contact_name/contact_email, que es para lo que existen esas columnas.
     */
    public function storeFeatureSuggestion(Request $request)
    {
        $validated = $request->validate([
            'suggestion' => 'required|string|max:2000',
        ]);

        $user = auth()->user();

        $ticket = SupportTicket::create([
            'title' => 'Sugerencia de función — ' . ($user->tenant->name ?? 'Cliente'),
            'description' => $validated['suggestion'],
            'tenant_id' => $user->tenant_id,
            'priority' => 'low',
            'status' => 'open',
            'created_by' => null,
            'contact_name' => $user->name,
            'contact_email' => $user->email,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Sugerencia recibida. Queda registrada con el equipo de Talent360.',
            'ticket_id' => $ticket->id,
        ], 201);
    }

    /**
     * Get list of tickets.
     */
    public function index(Request $request)
    {
        if (!$this->checkAccess()) {
            return response()->json(['error' => 'Acceso denegado'], 403);
        }

        $query = SupportTicket::with(['tenant', 'assignedTo']);

        // Search in title, description, contact details
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $likeOp = \DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where(function($q) use ($search, $likeOp) {
                $q->where('title', $likeOp, "%{$search}%")
                  ->orWhere('description', $likeOp, "%{$search}%")
                  ->orWhere('contact_name', $likeOp, "%{$search}%")
                  ->orWhere('contact_email', $likeOp, "%{$search}%");
            });
        }

        if ($request->has('tenant_id') && !empty($request->tenant_id) && $request->tenant_id !== 'all') {
            $query->where('tenant_id', $request->tenant_id);
        }

        if ($request->has('status') && !empty($request->status) && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->has('priority') && !empty($request->priority) && $request->priority !== 'all') {
            $query->where('priority', $request->priority);
        }

        $tickets = $query->orderBy('created_at', 'desc')->get();

        return response()->json($tickets);
    }

    /**
     * Create a ticket.
     */
    public function store(Request $request)
    {
        if (!$this->checkAccess()) {
            return response()->json(['error' => 'Acceso denegado'], 403);
        }

        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'tenant_id' => 'nullable|integer|exists:tenants,id',
            'priority' => 'required|string|in:low,medium,high',
            'status' => 'required|string|in:open,in_progress,resolved,closed',
            'assigned_to' => 'nullable|integer|exists:platform_users,id',
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
        ]);

        $ticket = SupportTicket::create([
            'title' => $request->title,
            'description' => $request->description,
            'tenant_id' => $request->tenant_id,
            'priority' => $request->priority,
            'status' => $request->status,
            'assigned_to' => $request->assigned_to,
            'created_by' => auth()->id(),
            'contact_name' => $request->contact_name,
            'contact_email' => $request->contact_email,
        ]);

        return response()->json([
            'message' => 'Ticket creado con éxito',
            'ticket' => $ticket->load(['tenant', 'assignedTo'])
        ], 201);
    }

    /**
     * Show a ticket with its notes.
     */
    public function show($id)
    {
        if (!$this->checkAccess()) {
            return response()->json(['error' => 'Acceso denegado'], 403);
        }

        $ticket = SupportTicket::with(['tenant', 'assignedTo', 'notes' => function($q) {
            $q->orderBy('created_at', 'asc');
        }])->findOrFail($id);

        return response()->json($ticket);
    }

    /**
     * Update a ticket.
     */
    public function update(Request $request, $id)
    {
        if (!$this->checkAccess()) {
            return response()->json(['error' => 'Acceso denegado'], 403);
        }

        $ticket = SupportTicket::findOrFail($id);

        $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string',
            'status' => 'sometimes|required|string|in:open,in_progress,resolved,closed',
            'priority' => 'sometimes|required|string|in:low,medium,high',
            'assigned_to' => 'nullable|integer|exists:platform_users,id',
            'tenant_id' => 'nullable|integer|exists:tenants,id',
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
        ]);

        $ticket->update($request->only([
            'title', 'description', 'status', 'priority', 'assigned_to', 'tenant_id', 'contact_name', 'contact_email'
        ]));

        return response()->json([
            'message' => 'Ticket actualizado con éxito',
            'ticket' => $ticket->load(['tenant', 'assignedTo'])
        ]);
    }

    /**
     * Delete a ticket (only platform_admin can delete).
     */
    public function destroy($id)
    {
        if (auth()->user()->role !== UserRole::PLATFORM_ADMIN->value) {
            return response()->json(['error' => 'Solo administradores pueden eliminar tickets'], 403);
        }

        $ticket = SupportTicket::findOrFail($id);
        $ticket->delete();

        return response()->json(['message' => 'Ticket eliminado con éxito']);
    }

    /**
     * Add an internal note.
     */
    public function addNote(Request $request, $id)
    {
        if (!$this->checkAccess()) {
            return response()->json(['error' => 'Acceso denegado'], 403);
        }

        $ticket = SupportTicket::findOrFail($id);

        $request->validate([
            'note' => 'required|string'
        ]);

        $note = SupportTicketNote::create([
            'ticket_id' => $ticket->id,
            'user_id' => auth()->id(),
            'user_name' => auth()->user()->name ?? 'Agente',
            'note' => $request->note
        ]);

        return response()->json([
            'message' => 'Nota interna agregada',
            'note' => $note
        ], 201);
    }

    /**
     * List all support agents.
     */
    public function agents()
    {
        if (!$this->checkAccess()) {
            return response()->json(['error' => 'Acceso denegado'], 403);
        }

        $agents = PlatformUser::select('id', 'name', 'role', 'email')
            ->whereIn('role', [UserRole::PLATFORM_ADMIN->value, UserRole::SUPPORT_AGENT->value])
            ->where('is_active', true)
            ->get();

        return response()->json($agents);
    }

    /**
     * Gemini Support Copilot for auto-response and agent assistance.
     */
    public function copilot(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'No autorizado'], 401);
        }

        $request->validate([
            'question' => 'required|string',
            'context' => 'nullable|string'
        ]);

        $question = $request->question;
        $context = $request->context ?? '';

        $systemInstruction = "Eres el Copiloto de Soporte Inteligente de Talent 360, un software SaaS avanzado de recursos humanos y asistencia.
Tu objetivo es resolver dudas de forma precisa, amable y sumamente concisa.
Información clave de la plataforma:
1. Talent 360 incluye reloj checador premium V2 con geolocalización, geofencing estricto (50 metros a la redonda de la sucursal) y cola de sincronización offline con IndexedDB para fichar sin internet.
2. Cumple con la obligatoriedad fiscal mexicana 2027 del control de accesos digitales con firmas encriptadas y folios.
3. Planes comerciales: Freemium (básico), Pro (GPS, Comedor, Ley Silla), Enterprise (nóminas CFDI 4.0 con PAC, Stripe, auditorías).
4. Si el empleado tiene problemas de GPS: recomiéndale dar permisos de ubicación haciendo clic en el candado 🔒 junto a la barra de direcciones del navegador.

Responde de manera profesional y directa a la consulta del usuario, usando el siguiente contexto si es relevante: '{$context}'.";

        $geminiKey = env('GEMINI_API_KEY');
        if (!$geminiKey) {
            return response()->json([
                'answer' => "Modo offline/Demo: Hola, soy tu Copiloto de Soporte. Para responder consultas reales con Inteligencia Artificial, configura la variable GEMINI_API_KEY en tu archivo .env. Consulta: \"{$question}\""
            ]);
        }

        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $geminiKey, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $systemInstruction . "\n\nConsulta del usuario: " . $question]
                        ]
                    ]
                ]
            ]);

            if ($response->failed()) {
                return response()->json(['error' => 'Error al conectar con la IA de soporte.'], 500);
            }

            $answer = $response->json('candidates.0.content.parts.0.text') ?? 'No se pudo obtener una respuesta en este momento.';
            return response()->json(['answer' => trim($answer)]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
