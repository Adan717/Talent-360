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
            'applied_vacancy_id' => 'required|integer|exists:vacancies,id',
            'phone' => 'nullable|string',
            'rfc' => 'nullable|string',
            'nss' => 'nullable|string',
            'status' => 'nullable|string',
            'is_ex_employee_fast_track' => 'nullable|boolean',
            'tenant_id' => 'nullable|integer'
        ]);
 
        // Buscar la vacante sin scopes globales para obtener el tenant_id real
        $vacancy = Vacancy::withoutGlobalScopes()->findOrFail($data['applied_vacancy_id']);

        if (!auth()->check()) {
            // Postulación pública: forzar tenant_id de la vacante, ignorar inputs administrativos
            $data['tenant_id'] = $vacancy->tenant_id;
            $data['status'] = 'prospect';
            $data['is_ex_employee_fast_track'] = false;
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
            'applied_vacancy_id' => 'sometimes|integer|exists:vacancies,id',
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

        if (isset($data['status']) && $data['status'] === 'hired' && $candidate->status !== 'hired') {
            DB::transaction(function () use ($candidate, &$data, &$pinDeInvitacion) {
                // Find vacancy
                $vacancy = Vacancy::withoutGlobalScopes()->find($candidate->applied_vacancy_id);
                $jobRoleId = $vacancy ? $vacancy->job_role_id : null;

                // Create user
                $user = User::withoutGlobalScopes()->where('email', $candidate->email)->first();
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
                    $user->update([
                        'is_active' => true,
                        'role' => 'empleado'
                    ]);
                }

                // Create/Update employee
                $employee = Employee::withoutGlobalScopes()
                    ->where('email', $candidate->email)
                    ->where('tenant_id', $candidate->tenant_id)
                    ->first();

                $pin = null;
                do {
                    $pin = sprintf("%06d", mt_rand(1, 999999));
                    $pinExists = Employee::withoutGlobalScopes()->where('pin_code', $pin)->exists();
                } while ($pinExists);

                if (!$employee) {
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
                        'shiftStart' => '09:00:00',
                        'shiftEnd' => '18:00:00',
                        'mealMinutes' => 60,
                        'restDay' => 'Domingo',
                        'hire_date' => now()->toDateString(),
                    ]);
                } else {
                    $employee->update([
                        'user_id' => $user->id,
                        'is_active_employee' => true,
                        'pin_code' => $employee->pin_code ?? $pin,
                        'job_role_id' => $employee->job_role_id ?? $jobRoleId
                    ]);
                }

                $pinDeInvitacion = $employee->pin_code;
            });
        }

        $candidate->update($data);

        // Incluir pin_code en la respuesta JSON si fue generado durante esta actualización
        $responseArray = $candidate->toArray();
        if ($pinDeInvitacion !== null) {
            $responseArray['pin_code'] = $pinDeInvitacion;
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
