<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\SupplyOrder;
use App\Models\SupplyOrderStageRole;
use App\Models\SupplyChainStageRole;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Services\NotificationService;
use App\Events\MonitorUpdated;

class SupplyOrderController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * §39: leer la configuración de la cadena del tenant — qué puesto es responsable
     * de cada etapa. Devuelve todas las etapas, con job_role_id null si no configurada.
     */
    public function getConfig(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;

        $configured = SupplyChainStageRole::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->pluck('job_role_id', 'stage')
            ->all();

        $stages = [];
        foreach (SupplyOrder::STAGES as $stage) {
            $stages[] = [
                'stage' => $stage,
                'job_role_id' => $configured[$stage] ?? null,
            ];
        }

        return response()->json(['success' => true, 'config' => $stages]);
    }

    /**
     * §39: definir/actualizar la cadena del tenant. Recibe un mapa stage → job_role_id.
     * Idempotente (updateOrCreate por tenant+stage); job_role_id null limpia esa etapa.
     */
    public function updateConfig(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;

        $validated = $request->validate([
            'config' => 'required|array',
            'config.*.stage' => ['required', 'string', 'in:' . implode(',', SupplyOrder::STAGES)],
            'config.*.job_role_id' => 'nullable|integer|exists:job_roles,id',
        ]);

        foreach ($validated['config'] as $row) {
            if (empty($row['job_role_id'])) {
                SupplyChainStageRole::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('stage', $row['stage'])
                    ->delete();
                continue;
            }

            SupplyChainStageRole::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenantId, 'stage' => $row['stage']],
                ['job_role_id' => $row['job_role_id']]
            );
        }

        return response()->json(['success' => true, 'message' => 'Cadena de pedidos actualizada.']);
    }

    /**
     * §39: listar pedidos del tenant con sus responsables por etapa.
     */
    public function index(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;

        $orders = SupplyOrder::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with('stageRoles')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json(['success' => true, 'orders' => $orders]);
    }

    /**
     * §39: crear un pedido. Al crearse, hace snapshot de la config del tenant hacia
     * supply_order_stage_roles, para que este pedido conserve sus responsables aunque
     * la config del tenant cambie después.
     */
    public function store(Request $request)
    {
        $user = auth()->user();
        $tenantId = $user->tenant_id ?? 1;

        $validated = $request->validate([
            'supplier_name' => 'required|string|max:255',
            'expected_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($validated, $tenantId, $user) {
            $order = SupplyOrder::create([
                'tenant_id' => $tenantId,
                'supplier_name' => $validated['supplier_name'],
                'created_by_user_id' => $user->id,
                'status' => 'generado',
                'expected_date' => $validated['expected_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Snapshot de la config del tenant hacia el pedido.
            $config = SupplyChainStageRole::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->pluck('job_role_id', 'stage')
                ->all();

            foreach (SupplyOrder::STAGES as $stage) {
                SupplyOrderStageRole::create([
                    'supply_order_id' => $order->id,
                    'stage' => $stage,
                    'job_role_id' => $config[$stage] ?? null,
                ]);
            }

            // Notificar al responsable de la etapa inicial de que hay un pedido nuevo.
            $this->notifyStageRole($order, 'generado', "📦 Nuevo pedido a {$order->supplier_name}", "Se generó un nuevo pedido a {$order->supplier_name}.");

            event(new MonitorUpdated($tenantId));

            return response()->json([
                'success' => true,
                'message' => 'Pedido generado.',
                'order' => $order->load('stageRoles'),
            ], 201);
        });
    }

    /**
     * §39: avanzar el pedido a la siguiente etapa. Notifica al puesto responsable de
     * la nueva etapa y, al llegar a listo_exhibir, genera automáticamente una tarea
     * de exhibición para el puesto de ventas (el responsable de esa etapa).
     */
    public function advanceStage(Request $request, $id)
    {
        $user = auth()->user();
        $tenantId = $user->tenant_id ?? 1;

        return DB::transaction(function () use ($id, $tenantId) {
            $order = SupplyOrder::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($id);

            $currentIndex = array_search($order->status, SupplyOrder::STAGES, true);
            if ($currentIndex === false) {
                return response()->json(['success' => false, 'message' => 'El pedido tiene un estado desconocido.'], 422);
            }
            if ($currentIndex >= count(SupplyOrder::STAGES) - 1) {
                return response()->json(['success' => false, 'message' => 'El pedido ya está en la última etapa de la cadena.'], 422);
            }

            $newStage = SupplyOrder::STAGES[$currentIndex + 1];
            $order->status = $newStage;
            $order->save();

            $this->notifyStageRole(
                $order,
                $newStage,
                "🔄 Pedido a {$order->supplier_name}: {$this->stageLabel($newStage)}",
                "El pedido a {$order->supplier_name} avanzó a la etapa \"{$this->stageLabel($newStage)}\" y ahora te corresponde."
            );

            // Al llegar a la última etapa, generar la tarea de exhibición para ventas.
            if ($newStage === 'listo_exhibir') {
                $this->generateExhibitTask($order, $tenantId);
            }

            event(new MonitorUpdated($tenantId));

            return response()->json([
                'success' => true,
                'message' => "Pedido avanzado a: {$this->stageLabel($newStage)}.",
                'order' => $order->load('stageRoles'),
            ]);
        });
    }

    /**
     * Notifica al puesto (job_role) responsable de una etapa del pedido, según el
     * snapshot del propio pedido (no la config global, que pudo cambiar).
     */
    private function notifyStageRole(SupplyOrder $order, string $stage, string $title, string $body): void
    {
        $stageRole = $order->stageRoles->firstWhere('stage', $stage)
            ?? SupplyOrderStageRole::where('supply_order_id', $order->id)->where('stage', $stage)->first();

        if ($stageRole && $stageRole->job_role_id) {
            $this->notificationService->sendToJobRole($stageRole->job_role_id, $order->tenant_id, $title, $body);
        }
    }

    /**
     * Genera una tarea normal de exhibición dirigida al puesto responsable de la
     * etapa listo_exhibir — reutiliza el módulo de Tareas, no un sistema paralelo.
     */
    private function generateExhibitTask(SupplyOrder $order, int $tenantId): void
    {
        $stageRole = $order->stageRoles->firstWhere('stage', 'listo_exhibir')
            ?? SupplyOrderStageRole::where('supply_order_id', $order->id)->where('stage', 'listo_exhibir')->first();

        $ventasRoleId = $stageRole?->job_role_id;

        $task = Task::create([
            'title' => "Exhibir producto: {$order->supplier_name}",
            'estimated_mins' => 30,
            'priority' => 'normal',
            'category' => 'operativo',
            'target_type' => 'role',
            'target_id' => $ventasRoleId,
            'tenant_id' => $tenantId,
        ]);

        TaskAssignment::create([
            'id' => (string) Str::uuid(),
            'task_id' => $task->id,
            'user_id' => null, // tarea de puesto: queda disponible para quien ocupe ese puesto
            'status' => 'pending',
            'tenant_id' => $tenantId,
            'date' => Carbon::today()->toDateString(),
            'origin' => 'extra',
        ]);
    }

    private function stageLabel(string $stage): string
    {
        return [
            'generado' => 'Generado',
            'por_llegar' => 'Por llegar',
            'recibido' => 'Recibido',
            'almacenado' => 'Almacenado',
            'listo_exhibir' => 'Listo para exhibir',
        ][$stage] ?? $stage;
    }
}
