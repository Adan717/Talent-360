<?php

namespace App\Http\Controllers;

use App\Helpers\SecurityLogger;
use App\Models\Employee;
use Illuminate\Http\Request;

/**
 * RESERVA LEGAL — "a esta persona no la toca la purga" (2026-09-05).
 *
 * El sistema conserva cinco años (art. 804 LFT) y a partir de ahí `datos:purgar-vencidos` borra.
 * La reserva legal es el freno de mano: mientras esté puesta, esa persona queda fuera de la purga
 * pase el tiempo que pase. Existe porque en un juicio laboral la carga de la prueba es del patrón
 * (LFT 784) y la evidencia que la empresa destruyó se presume en su contra: un plazo de retención
 * que corre por encima de un litigio abierto no es cumplimiento, es destrucción de pruebas.
 *
 * INDELEGABLE, `role:admin`. No es una capacidad que se otorgue por puesto: quien pudiera
 * levantarla podría, con un comando después, dejar a la empresa sin la evidencia con la que se
 * defiende. Va en el mismo bloque que la matriz de permisos y por la misma razón.
 *
 * LEVANTARLA EXIGE MOTIVO IGUAL QUE PONERLA. Poner una reserva es conservador —a lo sumo se
 * guardan datos de más—; quitarla es lo que abre la puerta al borrado. Si sólo se pidiera motivo
 * al ponerla, el acto peligroso sería el barato.
 *
 * ALCANZA A LOS EXPEDIENTES ARCHIVADOS (`withTrashed`): quien tiene un juicio abierto casi nunca
 * sigue en la plantilla — está archivado, que es exactamente donde vive la población que esta
 * figura protege. Un endpoint que sólo viera a los activos sería inútil para su único caso de uso.
 */
class ReservaLegalController extends Controller
{
    /**
     * GET /admin/reserva-legal
     * Las reservas puestas hoy en esta empresa, incluidas las de expedientes archivados.
     */
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $reservas = Employee::withTrashed()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('legal_hold_at')
            ->orderBy('legal_hold_at')
            ->get([
                'id', 'name', 'employee_id', 'job_role_id',
                'is_active_employee', 'termination_date', 'termination_reason',
                'legal_hold_at', 'legal_hold_reason', 'legal_hold_by', 'legal_hold_by_name',
                'legal_hold_reference', 'purged_at', 'deleted_at',
            ]);

        return response()->json([
            'success' => true,
            'reservas' => $reservas,
        ]);
    }

    /** POST /admin/employees/{id}/reserva-legal */
    public function marcar(Request $request, int $id)
    {
        $datos = $request->validate([
            'motivo' => 'required|string|min:5|max:255',
            'referencia' => 'nullable|string|max:255',
        ], [
            'motivo.required' => 'La reserva legal necesita un motivo escrito: es lo que la sostiene si alguien la cuestiona.',
            'motivo.min' => 'El motivo tiene que decir algo. Escribe la razón real (juicio, demanda, requerimiento).',
        ]);

        $empleado = $this->expediente($request, $id);
        $actor = $request->user();

        if ($empleado->legal_hold_at) {
            return response()->json([
                'success' => false,
                'message' => 'Esta persona ya tiene reserva legal desde el '
                    . $empleado->legal_hold_at->toDateString() . '. Para cambiar el motivo, levántala y vuelve a ponerla.',
            ], 409);
        }

        // forceFill: estas columnas NO son asignables en masa a propósito (ver la migración). La
        // pantalla de RRHH manda el expediente entero en cada guardado, y si fueran fillable un
        // guardado cualquiera podría levantar una reserva sin que nadie lo decidiera.
        $empleado->forceFill([
            'legal_hold_at' => now(),
            'legal_hold_reason' => $datos['motivo'],
            'legal_hold_by' => $actor->id,
            // Foto del nombre: el id solo no sirve dentro de tres años si esta misma purga ya
            // anonimizó a quien puso la reserva.
            'legal_hold_by_name' => $actor->name,
            'legal_hold_reference' => $datos['referencia'] ?? null,
        ])->save();

        SecurityLogger::log(
            'legal_hold_puesta',
            'Reserva legal PUESTA sobre el expediente ' . $empleado->id . ' (' . $empleado->name . '). '
                . 'Motivo: ' . $datos['motivo']
                . (($datos['referencia'] ?? null) ? ' · Referencia: ' . $datos['referencia'] : '')
                . '. Queda fuera de la purga de retención mientras siga puesta.',
            $empleado->tenant_id,
            $actor->id
        );

        return response()->json([
            'success' => true,
            'message' => $empleado->name . ' queda fuera de la purga de retención mientras la reserva siga puesta.',
            'employee' => $this->resumen($empleado->fresh()),
        ]);
    }

    /** POST /admin/employees/{id}/reserva-legal/levantar */
    public function levantar(Request $request, int $id)
    {
        $datos = $request->validate([
            'motivo' => 'required|string|min:5|max:255',
        ], [
            'motivo.required' => 'Levantar la reserva también necesita motivo: es el acto que vuelve a exponer '
                . 'a esa persona al borrado, así que se justifica igual que ponerla.',
            'motivo.min' => 'El motivo tiene que decir algo (juicio resuelto, convenio firmado, expediente cerrado).',
        ]);

        $empleado = $this->expediente($request, $id);
        $actor = $request->user();

        if (!$empleado->legal_hold_at) {
            return response()->json([
                'success' => false,
                'message' => 'Esta persona no tiene reserva legal puesta.',
            ], 409);
        }

        $motivoAnterior = $empleado->legal_hold_reason;
        $desde = $empleado->legal_hold_at->toDateString();

        $empleado->forceFill([
            'legal_hold_at' => null,
            'legal_hold_reason' => null,
            'legal_hold_by' => null,
            'legal_hold_by_name' => null,
            'legal_hold_reference' => null,
        ])->save();

        // La reserva se borra del expediente, pero NO de la historia: lo que queda de ella es este
        // renglón. Por eso el motivo viejo y la fecha viajan dentro del texto.
        SecurityLogger::log(
            'legal_hold_levantada',
            'Reserva legal LEVANTADA del expediente ' . $empleado->id . ' (' . $empleado->name . '). '
                . 'Estaba puesta desde el ' . $desde . ' por "' . $motivoAnterior . '". '
                . 'Motivo para levantarla: ' . $datos['motivo']
                . '. A partir de ahora la purga de retención sí la alcanza.',
            $empleado->tenant_id,
            $actor->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Reserva levantada. ' . $empleado->name . ' vuelve a estar sujeta a la purga de retención.',
            'employee' => $this->resumen($empleado->fresh()),
        ]);
    }

    /**
     * El expediente de ESTA empresa, archivado o no. El `where` por tenant es explícito además
     * del scope global: un platform_admin lo desactiva, y aquí no se quiere ese bypass.
     */
    private function expediente(Request $request, int $id): Employee
    {
        return Employee::withTrashed()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($id);
    }

    private function resumen(Employee $empleado): array
    {
        return [
            'id' => $empleado->id,
            'name' => $empleado->name,
            'legal_hold_at' => $empleado->legal_hold_at,
            'legal_hold_reason' => $empleado->legal_hold_reason,
            'legal_hold_by' => $empleado->legal_hold_by,
            'legal_hold_by_name' => $empleado->legal_hold_by_name,
            'legal_hold_reference' => $empleado->legal_hold_reference,
        ];
    }
}
