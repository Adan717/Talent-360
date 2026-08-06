<?php

namespace App\Http\Controllers;

use App\Models\AcademyCourse;
use App\Models\JobRole;
use App\Models\User;
use App\Models\UserCourseProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tablero de pendientes del encargado (decisión de producto 2026-08-05).
 *
 * El criterio del jefe fue **nada bloquea, todo avisa**: ni reprobar un examen ni traer la
 * inducción pendiente le impiden nada al colaborador, pero el encargado tiene que enterarse
 * para acercarse a la persona. "El que está en piso es quien puede acercarse, no el de arriba."
 *
 * El aviso es una CONSULTA, no mensajería: el tablero pregunta y pinta. No se usa
 * `internal_messages` porque ésa es el Chat Operativo del Monitor 360, un canal entre personas,
 * y meterle avisos automáticos del sistema sería ensuciar una conversación.
 *
 * A QUIÉN LE TOCA CADA CASO: el sistema no tiene la figura de "encargado de área" —no existe
 * `store_id` en `employees` ni un rol por sucursal—. Lo que hay es el ORGANIGRAMA: cada puesto
 * declara a quién reporta (`job_roles.reports_to_role_id`). Así que el caso de un colaborador le
 * toca a quien ocupe el puesto al que su puesto reporta; si ese puesto está vacante o no existe,
 * lo ve el admin. **Ojo**: el organigrama que arma el asistente de alta usa una convención (cada
 * quien reporta al primer puesto del nivel superior), así que conviene revisarlo una vez.
 */
class SupervisorPendientesController extends Controller
{
    /**
     * El plazo que se le da al colaborador para completar su inducción. Es el mismo número que
     * ve él en su app ("tienes N días") y el que decide cuándo el caso se pinta ROJO en el
     * tablero del encargado: **al cumplirse el plazo, no antes**. Decisión del jefe: "a los 3
     * días sin completar, el caso se pone rojo en mi tablero... no quiero que el sistema
     * castigue al nuevo; quiero que me presione a mí para acercarme a él".
     */
    private const DIAS_DE_PLAZO = 3;

    /**
     * A los cuántos días se pinta en rojo. El endpoint devuelve TODOS los pendientes con su
     * cuenta de días; el umbral sólo marca cuáles urgen, para que el tablero no tenga que
     * conocer la regla ni repetirla.
     */
    private const DIAS_PARA_ALERTA = self::DIAS_DE_PLAZO;

    public function index(Request $request)
    {
        $request->validate([
            'tipo' => 'nullable|string|in:induccion,cursos_reprobados,todos',
        ]);

        $tipo = $request->query('tipo', 'todos');
        $user = $request->user();
        $tenantId = $user->tenant_id ?? 1;

        $equipo = $this->equipoDe($user, $tenantId);

        return response()->json([
            'success' => true,
            'dias_para_alerta' => self::DIAS_PARA_ALERTA,
            'dias_de_plazo' => self::DIAS_DE_PLAZO,
            'induccion_pendiente' => in_array($tipo, ['todos', 'induccion'], true)
                ? $this->induccionPendiente($equipo, $tenantId)
                : [],
            'cursos_reprobados' => in_array($tipo, ['todos', 'cursos_reprobados'], true)
                ? $this->cursosReprobados($equipo, $tenantId)
                : [],
        ]);
    }

    /**
     * El encargado marca el caso como atendido: ya habló con la persona. La fila sale del
     * tablero. Si el colaborador vuelve a reprobar, la marca se limpia sola y el caso reaparece
     * (ver `AcademyController::submitQuizAttempt`).
     */
    public function marcarAtendido(Request $request, $progressId)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id ?? 1;

        $progreso = UserCourseProgress::where('id', $progressId)->first();

        if (!$progreso) {
            return response()->json(['success' => false, 'message' => 'Ese caso no existe.'], 404);
        }

        // Un encargado sólo atiende casos de SU equipo; el admin, los de toda la empresa.
        $equipo = $this->equipoDe($user, $tenantId);

        if (!$equipo->has($progreso->user_id)) {
            return response()->json([
                'success' => false,
                'message' => 'Ese colaborador no es de tu equipo.',
            ], 403);
        }

        $progreso->supervisor_atendido_at = now();
        $progreso->save();

        return response()->json([
            'success' => true,
            'message' => 'Caso marcado como atendido.',
        ]);
    }

    /**
     * Los colaboradores cuyos casos le tocan a quien pregunta, indexados por `users.id`.
     *
     * El admin ve toda la empresa. El supervisor ve a quienes ocupan un puesto que reporta al
     * suyo — y también a sí mismo, para que no se le escondan sus propios pendientes.
     *
     * Los administradores no aparecen en ninguna de las dos listas: el tablero es para seguir a
     * la plantilla operativa, no a la dueña de la empresa.
     */
    private function equipoDe(User $user, int $tenantId)
    {
        $colaboradores = User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            // Los administradores quedan fuera del tablero (decisión de producto 2026-08-06:
            // "excluye a los admin del tablero, es ruido"). Al medirlo en vivo, el tablero del
            // primer día listaba a TODA la plantilla incluida la dueña, que no es alguien a
            // quien su encargado tenga que perseguir para que haga la inducción. Los
            // supervisores SÍ siguen apareciendo: son personal como cualquier otro.
            ->whereNotIn('role', ['admin', 'platform_admin'])
            ->get()
            ->keyBy('id');

        if (($user->role ?? null) === 'admin' || ($user->role ?? null) === 'platform_admin') {
            return $colaboradores;
        }

        $puestosACargo = $this->puestosQueReportanA($user->job_role_id, $tenantId);

        return $colaboradores->filter(
            fn ($c) => $c->id === $user->id || in_array($c->job_role_id, $puestosACargo, true)
        );
    }

    /**
     * Qué puestos le reportan a `$puestoId`.
     *
     * El organigrama guarda la relación DOS veces y hay que mirar las dos, porque se llenan por
     * caminos distintos:
     *
     *   - `reports_to_role_ids` (arreglo) es la que el organigrama de Directorio > Puestos llama
     *     "la jerarquía operativa real": la línea PUNTEADA, y un puesto puede tener varios
     *     superiores. Es la que escribe el admin al arrastrar la conexión.
     *   - `reports_to_role_id` (uno solo) es el primero de esa lista... salvo cuando el
     *     organigrama lo armó el asistente de alta, que escribe SÓLO éste y deja el arreglo en
     *     nulo.
     *
     * Mirar únicamente el singular dejaba fuera al segundo jefe de un puesto con dos líneas
     * dibujadas: el caso le llegaba a uno y el otro no se enteraba nunca.
     */
    private function puestosQueReportanA(?int $puestoId, int $tenantId): array
    {
        if (!$puestoId) {
            return [];
        }

        return JobRole::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->get()
            ->filter(function ($puesto) use ($puestoId) {
                $lista = is_array($puesto->reports_to_role_ids) ? $puesto->reports_to_role_ids : [];

                if (!empty($lista)) {
                    return in_array((int) $puestoId, array_map('intval', $lista), true);
                }

                return (int) $puesto->reports_to_role_id === (int) $puestoId;
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Quién no ha terminado su inducción y desde hace cuántos días.
     *
     * Los días se cuentan desde `hire_date` —cuándo empezó a trabajar—, que es obligatoria en el
     * alta desde 2026-08-05 justamente para que esto tenga desde dónde contar.
     */
    private function induccionPendiente($equipo, int $tenantId): array
    {
        $hayInduccion = AcademyCourse::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('course_type', 'induction')
            ->exists();

        if (!$hayInduccion) {
            // Sin cursos de inducción en la empresa no hay nada que reclamarle a nadie.
            return [];
        }

        $puestos = JobRole::withoutGlobalScopes()->where('tenant_id', $tenantId)->pluck('name', 'id');

        $expedientes = DB::table('employees')
            ->where('tenant_id', $tenantId)
            ->pluck('hire_date', 'user_id');

        $hoy = Carbon::today();

        return $equipo
            ->filter(fn ($c) => !$c->has_completed_induction)
            ->map(function ($c) use ($puestos, $expedientes, $hoy) {
                $ingreso = $expedientes[$c->id] ?? null;
                $dias = $ingreso ? Carbon::parse($ingreso)->startOfDay()->diffInDays($hoy, false) : null;

                return [
                    'user_id' => $c->id,
                    'nombre' => $c->name,
                    'puesto' => $puestos[$c->job_role_id] ?? null,
                    'hire_date' => $ingreso ? Carbon::parse($ingreso)->toDateString() : null,
                    // Null si el expediente no tiene fecha de ingreso (expedientes viejos): el
                    // tablero lo muestra igual, pero sin cuenta de días.
                    'dias_sin_induccion' => $dias !== null ? max(0, $dias) : null,
                    'urge' => $dias !== null && $dias >= self::DIAS_PARA_ALERTA,
                ];
            })
            ->sortByDesc(fn ($fila) => $fila['dias_sin_induccion'] ?? -1)
            ->values()
            ->all();
    }

    /**
     * Quién lleva dos o más intentos reprobados en el mismo curso.
     *
     * Se devuelven también los ya atendidos, con su bandera, para que el tablero decida si los
     * esconde o los muestra en gris: el dato de "ya se habló con esta persona" también sirve.
     */
    private function cursosReprobados($equipo, int $tenantId): array
    {
        $progresos = UserCourseProgress::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('failed_attempts', '>=', 2)
            // Si acabó aprobando, ya no es un pendiente de nadie: los intentos fallidos siguen
            // en su historial, pero el caso está cerrado y no tiene por qué seguir en el tablero.
            ->where('status', '!=', 'completed')
            ->whereIn('user_id', $equipo->keys())
            ->get();

        if ($progresos->isEmpty()) {
            return [];
        }

        $cursos = AcademyCourse::withoutGlobalScopes()
            ->whereIn('id', $progresos->pluck('course_id')->unique())
            ->pluck('title', 'id');

        return $progresos
            ->map(fn ($p) => [
                'progress_id' => $p->id,
                'user_id' => $p->user_id,
                'nombre' => $equipo[$p->user_id]->name ?? null,
                'course_id' => $p->course_id,
                'curso' => $cursos[$p->course_id] ?? null,
                'intentos' => (int) $p->failed_attempts,
                'ultimo_score' => (int) $p->score,
                'atendido' => $p->supervisor_atendido_at !== null,
                'atendido_at' => $p->supervisor_atendido_at,
            ])
            ->sortBy('atendido')
            ->values()
            ->all();
    }
}
