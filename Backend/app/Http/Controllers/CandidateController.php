<?php
 
namespace App\Http\Controllers;
 
use Illuminate\Http\Request;
use App\Models\Candidate;
use App\Models\Vacancy;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class CandidateController extends Controller
{
    public function index()
    {
        // El trait BelongsToTenant filtra automáticamente por tenant_id
        $candidates = Candidate::all();
        return response()->json($candidates);
    }
 
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'email' => 'required|email',
            // `exists:vacancies,id` a secas aceptaba vacantes BORRADAS: se podía postular a una
            // posición que la empresa ya había quitado. El resto de filtros (empresa, activa,
            // oculta) se comprueba abajo, porque la respuesta depende de si hay sesión.
            'applied_vacancy_id' => ['required', 'integer', 'exists:vacancies,id,deleted_at,NULL'],
            'phone' => 'nullable|string',
            'rfc' => 'nullable|string',
            'nss' => 'nullable|string',
            'status' => 'nullable|string',
            'is_ex_employee_fast_track' => 'nullable|boolean',
            'tenant_id' => 'nullable|integer'
        ]);

        // Se resuelve sin el scope de TENANT (en la ruta pública no hay sesión que lo resuelva),
        // pero SÍ respetando el borrado lógico.
        $vacancy = Vacancy::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->findOrFail($data['applied_vacancy_id']);

        if (!auth()->check()) {
            // Postulación pública: forzar tenant_id de la vacante, ignorar inputs administrativos
            $data['tenant_id'] = $vacancy->tenant_id;
            $data['status'] = 'prospect';

            // Desde fuera sólo se puede postular a lo que de verdad está publicado. La bolsa
            // pública ya filtra igual; esto cierra la puerta de atrás (mandar un id a mano).
            $publicada = ($vacancy->is_active === null || $vacancy->is_active)
                && ($vacancy->is_hidden === null || !$vacancy->is_hidden);

            if (!$publicada) {
                return response()->json([
                    'message' => 'Esta vacante ya no está recibiendo postulaciones.',
                ], 422);
            }

            // FAST-TRACK: la etiqueta existía en la pantalla pero nadie la encendía jamás. Se
            // calcula en el SERVIDOR (nunca se acepta del cliente): es ex-colaborador quien ya
            // tiene expediente en ESA empresa, incluido el archivado.
            $data['is_ex_employee_fast_track'] = Employee::withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->withTrashed()
                ->where('tenant_id', $vacancy->tenant_id)
                ->where('email', $data['email'])
                ->exists();
        } else {
            // Postulación desde panel de administración: forzar el tenant_id del usuario autenticado
            $tenantId = auth()->user()->tenant_id;
            if ($vacancy->tenant_id !== $tenantId) {
                return response()->json(['error' => 'La vacante seleccionada no pertenece a su empresa.'], 403);
            }
            $data['tenant_id'] = $tenantId;
        }
 
        $candidate = Candidate::create($data);
        return response()->json($candidate, 201);
    }
 
    public function update(Request $request, $id)
    {
        $candidate = Candidate::findOrFail($id);
 
        $data = $request->validate([
            'name' => 'sometimes|string',
            'email' => 'sometimes|email',
            // La vacante se valida DENTRO de la empresa: con `exists:vacancies,id` a secas se
            // aceptaba el id de una vacante de OTRO tenant, y su `job_role_id` acababa metido en
            // el expediente al contratar (puesto ajeno = política de tolerancia ajena).
            'applied_vacancy_id' => [
                'sometimes',
                'integer',
                \Illuminate\Validation\Rule::exists('vacancies', 'id')
                    ->where('tenant_id', $candidate->tenant_id)
                    ->whereNull('deleted_at'),
            ],
            'phone' => 'nullable|string',
            'rfc' => 'nullable|string',
            'nss' => 'nullable|string',
            'status' => 'sometimes|string',
            'induction_score' => 'nullable|integer',
            'is_ex_employee_fast_track' => 'sometimes|boolean',
            'hr_notes' => 'nullable|string'
        ]);

        // El PIN de invitación se lleva en una variable, NO como atributo del candidato: la tabla
        // `candidates` no tiene columna `pin_code`, así que asignárselo al modelo hacía que el
        // `update()` de más abajo intentara escribir una columna inexistente → 500 en TODA
        // contratación (con el usuario y el expediente ya creados por la transacción previa, y el
        // candidato quedándose para siempre sin pasar a "contratado").
        $pinDeInvitacion = null;
        $faltaSueldo = false;
        $faltaPuesto = false;
        $cursosInduccion = 0;

        if (isset($data['status']) && $data['status'] === 'hired' && $candidate->status !== 'hired') {
            // El correo es ÚNICO GLOBAL en `users`. Si ya pertenece a una cuenta de OTRA empresa,
            // seguir adelante significaba apropiarse de ella: el `update` de abajo la degradaba a
            // 'empleado' (sacando de su panel al admin del vecino) y el expediente nuevo quedaba
            // colgado de un usuario de otro tenant. Se comprueba ANTES de tocar nada, con el mismo
            // criterio que ya aplica el alta de RRHH (EmployeeController::store).
            $cuentaAjena = User::withoutGlobalScopes()->withTrashed()
                ->where('email', $candidate->email)
                ->where('tenant_id', '!=', $candidate->tenant_id)
                ->exists();

            if ($cuentaAjena) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'email' => ['Ese correo ya tiene una cuenta en la plataforma, en otra empresa. Pídele al candidato un correo distinto y corrígelo antes de contratarlo.'],
                ]);
            }

            DB::transaction(function () use ($candidate, &$data, &$pinDeInvitacion, &$faltaSueldo, &$faltaPuesto, &$cursosInduccion) {
                // La vacante se lee DENTRO de la empresa del candidato y viva: de una ajena o
                // borrada no se hereda el puesto (se queda sin puesto, que sí avisa en RRHH).
                $vacancy = Vacancy::withoutGlobalScope(\App\Scopes\TenantScope::class)
                    ->where('tenant_id', $candidate->tenant_id)
                    ->find($candidate->applied_vacancy_id);
                $jobRoleId = $vacancy ? $vacancy->job_role_id : null;

                // Cuenta y expediente se buscan en SU empresa y CON los archivados: el reingreso
                // de un ex-colaborador es justo el caso que este módulo dice atender. Antes se
                // usaba `withoutGlobalScopes()` a secas —que también apaga el filtro de borrado
                // lógico— y se les ponía is_active=true SIN restore(): el `deleted_at` seguía
                // puesto y la persona quedaba contratada pero invisible: no podía entrar (el login
                // sí respeta el borrado), su PIN no servía y no salía ni en RRHH ni en nómina.
                $user = User::withoutGlobalScope(\App\Scopes\TenantScope::class)->withTrashed()
                    ->where('email', $candidate->email)
                    ->where('tenant_id', $candidate->tenant_id)
                    ->first();

                if (!$user) {
                    // Misma regla que el alta de RRHH: contraseña que nadie conoce; la persona
                    // fija la suya al activar su cuenta con el PIN de la invitación. Contratar a
                    // alguien no debe crear un acceso con contraseña pública.
                    $user = User::create([
                        'name' => $candidate->name,
                        'email' => $candidate->email,
                        'password' => Hash::make(\Illuminate\Support\Str::random(32)),
                        'role' => 'empleado',
                        'tenant_id' => $candidate->tenant_id,
                        'is_active' => true
                    ]);
                } else {
                    if ($user->trashed()) {
                        $user->restore();
                    }
                    // Sólo se reactiva el acceso. El ROL no se toca: contratar a alguien que ya
                    // tiene cuenta de admin o supervisor en la empresa no debe degradarlo.
                    $user->update(['is_active' => true]);
                }

                $employee = Employee::withoutGlobalScope(\App\Scopes\TenantScope::class)->withTrashed()
                    ->where('email', $candidate->email)
                    ->where('tenant_id', $candidate->tenant_id)
                    ->first();

                $pin = null;
                do {
                    $pin = sprintf("%06d", mt_rand(1, 999999));
                    $pinExists = Employee::withoutGlobalScopes()->where('pin_code', $pin)->exists();
                } while ($pinExists);

                if (!$employee) {
                    // El turno sale del horario de la EMPRESA, no de un 09:00–18:00 en duro: a
                    // quien abre a las 11:00 le contaba dos horas de retardo desde su primer día.
                    $jornada = \App\Support\HorarioDeLaEmpresa::completar([], $candidate->tenant_id);

                    $employee = Employee::create([
                        'tenant_id' => $candidate->tenant_id,
                        'user_id' => $user->id,
                        'name' => $candidate->name,
                        'email' => $candidate->email,
                        'phone' => $candidate->phone,
                        'job_role_id' => $jobRoleId,
                        'is_active_employee' => true,
                        'pin_code' => $pin,
                        'invite_token' => Str::random(32),
                        'shiftStart' => $jornada['shiftStart'],
                        'shiftEnd' => $jornada['shiftEnd'],
                        'mealMinutes' => 60,
                        'restDay' => 'Domingo',
                        'hire_date' => now()->toDateString(),
                    ]);
                } else {
                    if ($employee->trashed()) {
                        $employee->restore();
                    }
                    $employee->update([
                        'user_id' => $user->id,
                        'is_active_employee' => true,
                        'pin_code' => $employee->pin_code ?? $pin,
                        'job_role_id' => $employee->job_role_id ?? $jobRoleId
                    ]);
                }

                $pinDeInvitacion = $employee->pin_code;

                // SUELDO: el ATS no lo pregunta en ningún momento, así que el expediente nace sin
                // él y la nómina rellena el hueco con un default escondido de $2,400 que nadie
                // tecleó (ClockService). No se bloquea la contratación —criterio del dueño— pero
                // se AVISA, para que RRHH sepa que le falta capturarlo.
                $employee->refresh();
                $faltaSueldo = ($employee->base_salary === null || (float) $employee->base_salary <= 0)
                    && ($employee->salary === null || (float) $employee->salary <= 0);
                $faltaPuesto = $employee->job_role_id === null;

                // Bloque 5 / D2 (2026-08-13): la inducción es de Academia y ocurre YA CONTRATADO.
                // La inscripción es implícita (curso del tenant visible por puesto; el avance se
                // crea al tocarlo) y el aviso de pendiente cuenta desde el hire_date que esta
                // misma transacción fija — aquí sólo se MIDE para que la contratación pueda
                // DECIR la verdad: cuántos cursos de inducción le esperan (o que no hay ninguno).
                $cursosInduccion = \App\Models\AcademyCourse::withoutGlobalScope(\App\Scopes\TenantScope::class)
                    ->where('tenant_id', $candidate->tenant_id)
                    ->where('course_type', 'induction')
                    ->where(function ($q) use ($employee) {
                        $q->whereNull('target_job_role_id')
                            ->orWhere('target_job_role_id', $employee->job_role_id);
                    })
                    ->count();
            });
        }

        $candidate->update($data);

        // Incluir pin_code en la respuesta JSON si fue generado durante esta actualización
        $responseArray = $candidate->toArray();
        if ($pinDeInvitacion !== null) {
            $responseArray['pin_code'] = $pinDeInvitacion;
            $responseArray['salary_pending'] = $faltaSueldo;
            $responseArray['job_role_pending'] = $faltaPuesto;
            $responseArray['induction_courses'] = $cursosInduccion;
            $responseArray['avisos'] = array_values(array_filter([
                $faltaSueldo ? 'Falta capturar su sueldo en RRHH: sin él, la nómina lo calcula con un valor por defecto.' : null,
                $faltaPuesto ? 'Quedó sin puesto asignado: no podrá fichar por el kiosco ni recibir capacidades hasta que se lo asignes en RRHH.' : null,
                $cursosInduccion === 0 ? 'La Academia no tiene ningún curso de inducción que le aplique: no le llegará nada que completar.' : null,
            ]));
        }

        return response()->json($responseArray);
    }
 
    public function destroy($id)
    {
        $candidate = Candidate::findOrFail($id);
        $candidate->delete();
        return response()->json(['message' => 'Candidato eliminado exitosamente']);
    }
}
