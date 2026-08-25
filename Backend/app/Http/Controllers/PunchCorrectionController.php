<?php

namespace App\Http\Controllers;

use App\Models\TimeEntry;
use App\Scopes\ExcludeAnuladasScope;
use App\Scopes\TenantScope;
use App\Services\CorreccionDeAsistencia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Corrección de fichajes — la puerta HTTP (Capa 3, 2026-08-25).
 *
 * Hasta hoy corregir un fichaje requería un ingeniero con acceso a la consola, que era justo la
 * dependencia que había que quitar. Este controlador **sólo valida y delega**: toda la mecánica
 * —anular sin borrar, firmar el rastro, avisar al colaborador— vive en `CorreccionDeAsistencia`,
 * donde ninguna pantalla puede saltársela.
 *
 * El `motivo` exige 10 caracteres mínimo a propósito: "ok", "error" y "ya" no son razones, y una
 * corrección que no se puede explicar no se puede defender en un juicio. El mínimo no garantiza
 * calidad —nadie puede—, pero sí obliga a escribir una frase en vez de teclear cualquier cosa.
 *
 * La ruta va detrás de `permission:manage_punch_corrections`. Eso hoy significa: el admin dueño
 * (que pasa siempre por decisión de producto, no puede quedarse fuera de su empresa) y cualquier
 * puesto al que se le haya concedido la capacidad explícitamente. Ver
 * `docs/RFC_BITACORA_INMUTABLE.md`.
 */
class PunchCorrectionController extends Controller
{
    public function __construct(private CorreccionDeAsistencia $correcciones)
    {
    }

    /** Corrige un fichaje: lo anula y —si vienen valores nuevos— crea el que lo sustituye. */
    public function store(Request $request)
    {
        $datos = $request->validate([
            'time_entry_id' => 'required|integer',
            'motivo' => 'required|string|min:10|max:500',
            'time' => 'nullable|date_format:H:i,H:i:s',
            'type' => 'nullable|string|max:40',
            'date' => 'nullable|date_format:Y-m-d',
            'anular' => 'nullable|boolean',
        ], [
            'motivo.min' => 'Escribe por qué se corrige: "ok" o "error" no explican nada, y esto es la evidencia con la que tu empresa se defiende.',
            'motivo.required' => 'Una corrección de asistencia necesita un motivo escrito.',
        ]);

        $usuario = $request->user();

        // El fichaje tiene que ser de SU empresa. `TenantScope` ya lo haría, pero aquí se dice
        // explícito porque un id de otra empresa debe responder 404, no un error raro.
        $original = TimeEntry::withoutGlobalScope(ExcludeAnuladasScope::class)
            ->withoutGlobalScope(TenantScope::class)
            ->where('id', $datos['time_entry_id'])
            ->where('tenant_id', $usuario->tenant_id)
            ->first();

        if (!$original) {
            return response()->json(['message' => 'Ese fichaje no existe en tu empresa.'], 404);
        }

        // Valores nuevos: sólo lo que venga. Vacío = anulación pura (un duplicado).
        $nuevos = collect($datos)
            ->only(['time', 'type', 'date'])
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->all();

        if (isset($nuevos['time']) && strlen($nuevos['time']) === 5) {
            $nuevos['time'] .= ':00';
        }

        if (!empty($datos['anular'])) {
            $nuevos = []; // se pidió anular sin sustituto: manda la intención explícita
        }

        try {
            $resultado = $this->correcciones->corregir($original, $nuevos, $datos['motivo'], $usuario);
        } catch (\RuntimeException $e) {
            // Reglas de negocio del servicio (ya anulado, motivo vacío): son 422, no un 500.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => empty($nuevos)
                ? 'Fichaje anulado. Se avisó al colaborador.'
                : 'Fichaje corregido. El original queda registrado y se avisó al colaborador.',
            'correccion_id' => $resultado['correccion_id'],
            'anulado_id' => $resultado['anulado_id'],
            'nuevo_id' => $resultado['nuevo_id'],
        ], 201);
    }

    /** Da de alta un fichaje que faltaba (un olvido). No anula nada. */
    public function alta(Request $request)
    {
        $datos = $request->validate([
            'user_id' => 'required|integer',
            'date' => 'required|date_format:Y-m-d',
            'type' => 'required|string|max:40',
            'time' => 'required|date_format:H:i,H:i:s',
            'motivo' => 'required|string|min:10|max:500',
        ], [
            'motivo.min' => 'Escribe por qué se agrega este fichaje a mano.',
        ]);

        $usuario = $request->user();

        $esDeLaEmpresa = DB::table('users')
            ->where('id', $datos['user_id'])
            ->where('tenant_id', $usuario->tenant_id)
            ->exists();

        if (!$esDeLaEmpresa) {
            return response()->json(['message' => 'Ese colaborador no es de tu empresa.'], 404);
        }

        $hora = strlen($datos['time']) === 5 ? $datos['time'] . ':00' : $datos['time'];

        $resultado = $this->correcciones->darDeAlta([
            'tenant_id' => $usuario->tenant_id,
            'user_id' => $datos['user_id'],
            'date' => $datos['date'],
            'type' => $datos['type'],
            'time' => $hora,
            'is_late' => false,
            'late_minutes' => 0,
        ], $datos['motivo'], $usuario);

        return response()->json([
            'success' => true,
            'message' => 'Fichaje agregado. Se avisó al colaborador.',
            'correccion_id' => $resultado['correccion_id'],
            'nuevo_id' => $resultado['nuevo_id'],
        ], 201);
    }

    /**
     * La historia de un fichaje: por qué manos pasó, cuándo y con qué motivo.
     *
     * Es lo que se enseña en una auditoría, así que mira TAMBIÉN los anulados — a propósito.
     */
    public function historia(Request $request, $id)
    {
        $usuario = $request->user();

        $fichaje = TimeEntry::withoutGlobalScope(ExcludeAnuladasScope::class)
            ->withoutGlobalScope(TenantScope::class)
            ->where('id', $id)
            ->where('tenant_id', $usuario->tenant_id)
            ->first();

        if (!$fichaje) {
            return response()->json(['message' => 'Ese fichaje no existe en tu empresa.'], 404);
        }

        $cadena = $this->correcciones
            ->historiaDelDia($usuario->tenant_id, $fichaje->user_id, $fichaje->date)
            ->where('type', $fichaje->type)
            ->values();

        $correcciones = DB::table('asistencia_correcciones as c')
            ->leftJoin('users as autor', 'autor.id', '=', 'c.autorizado_por')
            ->where('c.tenant_id', $usuario->tenant_id)
            ->whereIn('c.id', $cadena->pluck('anulado_por_correccion_id')
                ->merge($cadena->pluck('creado_por_correccion_id'))
                ->filter()->unique()->values())
            ->orderBy('c.id')
            ->select(
                'c.id', 'c.tipo', 'c.motivo', 'c.created_at', 'c.notificado_at',
                'c.time_entry_id', 'c.nueva_time_entry_id',
                'autor.name as autorizado_por_nombre'
            )
            ->get();

        return response()->json([
            'fichajes' => $cadena->map(fn ($e) => [
                'id' => $e->id,
                'date' => $e->date,
                'type' => $e->type,
                'time' => substr((string) $e->time, 0, 8),
                'late_minutes' => (int) $e->late_minutes,
                'vigente' => $e->anulado_at === null,
                'creado_por_correccion_id' => $e->creado_por_correccion_id,
                'anulado_por_correccion_id' => $e->anulado_por_correccion_id,
            ])->all(),
            'correcciones' => $correcciones,
        ]);
    }
}
