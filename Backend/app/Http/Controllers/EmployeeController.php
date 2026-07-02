<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Employee;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class EmployeeController extends Controller
{
    public function index()
    {
        // Eloquent automáticamente filtra por tenant_id gracias a BelongsToTenant / Tenantable
        $employees = Employee::with('jobRole:id,name', 'user')->get();
        return response()->json($employees);
    }

    public function store(Request $request)
    {
        $currentUser = auth()->user() ?? auth('sanctum')->user();
        $tenantId = $currentUser ? $currentUser->tenant_id : null;
        $tenant = $currentUser ? $currentUser->tenant : null;

        // 1. Validar límite de empleados activos para plan Freemium (Límite: 10 colaboradores)
        if ($tenant) {
            $trialActive = false;
            if ($tenant->subscription_status === 'trial' || empty($tenant->subscription_status)) {
                if ($tenant->trial_ends_at) {
                    $endsAt = \Carbon\Carbon::parse($tenant->trial_ends_at);
                    if (now()->lt($endsAt)) {
                        $trialActive = true;
                    }
                }
            }

            if (!$trialActive && strtolower($tenant->plan ?? 'freemium') === 'freemium') {
                $activeCount = Employee::where('tenant_id', $tenant->id)
                    ->where('is_active_employee', true)
                    ->count();

                if ($activeCount >= 10) {
                    return response()->json([
                        'error' => 'Plan Limit Exceeded',
                        'message' => 'Has alcanzado el límite máximo de 10 colaboradores activos en el plan gratuito. Actualiza al plan Profesional para contratar más empleados.'
                    ], 403);
                }
            }
        }

        // 2. Validar límite de cuentas administrativas (Seats) por plan
        $role = strtolower($request->input('role', 'empleado'));
        $request->merge(['role' => $role]);
        if ($tenant && in_array($role, ['admin', 'supervisor'])) {
            $adminCount = User::where('tenant_id', $tenant->id)
                ->whereIn('role', ['admin', 'supervisor'])
                ->count();

            $adminLimit = 9999;
            if (strtolower($tenant->plan ?? 'freemium') === 'freemium') {
                $adminLimit = 1;
            } elseif (strtolower($tenant->plan ?? 'pro') === 'pro') {
                $adminLimit = 3;
            }

            if ($adminCount >= $adminLimit) {
                return response()->json([
                    'error' => 'Admin Limit Exceeded',
                    'message' => 'Has alcanzado el límite máximo de ' . $adminLimit . ' cuentas administrativas en tu plan ' . ucfirst($tenant->plan) . '. Por favor actualiza tu suscripción para añadir más supervisores.'
                ], 403);
            }
        }

        $email = $request->input('email');
        $password = $request->input('password', 'password123');

        // 3. Verificar si ya existe un colaborador con este email en el tenant
        $existingEmployee = Employee::withoutGlobalScopes()->where('email', $email)->where('tenant_id', $tenantId)->first();
        if ($existingEmployee) {
            $data = $request->validate([
                'name' => 'required|string',
                'email' => 'required|email',
                'role' => 'required|string',
                'job_role_id' => 'nullable|integer|exists:job_roles,id',
                'contract_type' => 'nullable|string',
                'salary' => 'nullable|numeric',
                'is_active' => 'nullable|boolean',
                'is_active_employee' => 'nullable|boolean',
                'shiftStart' => 'nullable|string',
                'shiftEnd' => 'nullable|string',
                'phone' => 'nullable|string',
                'portadorLlaves' => 'nullable|string',
                'employee_id' => 'nullable|string',
                'curp' => 'nullable|string',
                'rfc' => 'nullable|string',
                'nss' => 'nullable|string',
                'address' => 'nullable|string',
                'emergency_contact_name' => 'nullable|string',
                'emergency_contact_phone' => 'nullable|string',
                'hire_date' => 'nullable|date',
                'mealMinutes' => 'nullable|integer',
                'restDay' => 'nullable|string',
                'base_salary' => 'nullable|numeric',
                'avatar' => 'sometimes|nullable|string',
            ]);

            // Actualizar cuenta de usuario vinculada
            if ($existingEmployee->user_id) {
                $user = User::withoutGlobalScopes()->find($existingEmployee->user_id);
                if ($user) {
                    $user->update([
                        'name' => $data['name'],
                        'email' => $data['email'],
                        'role' => $data['role'],
                        'avatar' => $data['avatar'] ?? $user->avatar,
                    ]);
                    if ($request->filled('password')) {
                        $user->update(['password' => Hash::make($request->password)]);
                    }
                }
            }

            $existingEmployee->update($data);
            return response()->json($existingEmployee->load('user'), 200);
        }

        // 4. Validar email único global en users si se va a crear el usuario de login
        $existingUserGlobal = User::withoutGlobalScopes()->where('email', $email)->first();
        if ($existingUserGlobal) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'email' => ['The email has already been taken.']
            ]);
        }

        $data = $request->validate([
            'name' => 'required|string',
            'email' => 'required|email',
            'role' => 'required|string',
            'job_role_id' => 'nullable|integer|exists:job_roles,id',
            'contract_type' => 'nullable|string',
            'salary' => 'nullable|numeric',
            'is_active' => 'nullable|boolean',
            'is_active_employee' => 'nullable|boolean',
            'shiftStart' => 'nullable|string',
            'shiftEnd' => 'nullable|string',
            'phone' => 'nullable|string',
            'portadorLlaves' => 'nullable|string',
            'employee_id' => 'nullable|string',
            'curp' => 'nullable|string',
            'rfc' => 'nullable|string',
            'nss' => 'nullable|string',
            'address' => 'nullable|string',
            'emergency_contact_name' => 'nullable|string',
            'emergency_contact_phone' => 'nullable|string',
            'hire_date' => 'nullable|date',
            'mealMinutes' => 'nullable|integer',
            'restDay' => 'nullable|string',
            'base_salary' => 'nullable|numeric',
            'avatar' => 'sometimes|nullable|string',
        ]);

        try {
            DB::beginTransaction();

                        // Crear el registro de acceso en la tabla users
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($password),
                'role' => $data['role'],
                'job_role_id' => $data['job_role_id'] ?? null,
                'tenant_id' => $tenantId,
                'is_active' => $request->input('is_active', true),
                'avatar' => $data['avatar'] ?? null,
            ]);

            // Crear el registro del colaborador en la tabla employees
            $data['tenant_id'] = $tenantId;
            $data['user_id'] = $user->id;
            $employee = Employee::create($data);

            DB::commit();

            return response()->json($employee->load('user'), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error al registrar colaborador: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $employee = Employee::findOrFail($id);
        $currentUser = auth()->user() ?? auth('sanctum')->user();
        $tenant = $currentUser ? $currentUser->tenant : null;

        // Validar límite de cuentas administrativas (Seats) por plan en actualización
        $role = $request->input('role');
        if ($role) {
            $role = strtolower($role);
            $request->merge(['role' => $role]);
        }
        if ($tenant && $role && in_array($role, ['admin', 'supervisor']) && $employee->role !== $role) {
            $adminCount = User::where('tenant_id', $tenant->id)
                ->whereIn('role', ['admin', 'supervisor'])
                ->count();

            $adminLimit = 9999;
            if (strtolower($tenant->plan ?? 'freemium') === 'freemium') {
                $adminLimit = 1;
            } elseif (strtolower($tenant->plan ?? 'pro') === 'pro') {
                $adminLimit = 3;
            }

            if ($adminCount >= $adminLimit) {
                return response()->json([
                    'error' => 'Admin Limit Exceeded',
                    'message' => 'Has alcanzado el límite máximo de ' . $adminLimit . ' cuentas administrativas en tu plan ' . ucfirst($tenant->plan) . '. Por favor actualiza tu suscripción para añadir más supervisores.'
                ], 403);
            }
        }

        $data = $request->validate([
            'name' => 'sometimes|string',
            'email' => 'sometimes|email',
            'role' => 'sometimes|string',
            'job_role_id' => 'sometimes|nullable|integer|exists:job_roles,id',
            'contract_type' => 'sometimes|nullable|string',
            'is_active' => 'sometimes|boolean',
            'is_active_employee' => 'sometimes|boolean',
            'salary' => 'sometimes|nullable|numeric',
            'google_id' => 'nullable|string',
            'apple_id' => 'nullable|string',
            'samsung_id' => 'nullable|string',
            'shiftStart' => 'sometimes|nullable|string',
            'shiftEnd' => 'sometimes|nullable|string',
            'phone' => 'sometimes|nullable|string',
            'portadorLlaves' => 'sometimes|nullable|string',
            'employee_id' => 'sometimes|nullable|string',
            'curp' => 'sometimes|nullable|string',
            'rfc' => 'sometimes|nullable|string',
            'nss' => 'sometimes|nullable|string',
            'address' => 'sometimes|nullable|string',
            'emergency_contact_name' => 'sometimes|nullable|string',
            'emergency_contact_phone' => 'sometimes|nullable|string',
            'hire_date' => 'sometimes|nullable|date',
            'mealMinutes' => 'sometimes|nullable|integer',
            'restDay' => 'sometimes|nullable|string',
            'base_salary' => 'sometimes|nullable|numeric',
            'avatar' => 'sometimes|nullable|string',
        ]);

        try {
            DB::beginTransaction();

            $employee->update($data);

            // Si tiene usuario enlazado, actualizar. Si no tiene pero se requiere acceso web, crear usuario.
            if ($employee->user_id) {
                $user = User::withoutGlobalScopes()->find($employee->user_id);
                if ($user) {
                    $userUpdates = [];
                    if ($request->has('name')) $userUpdates['name'] = $request->name;
                    if ($request->has('email')) $userUpdates['email'] = $request->email;
                    if ($request->has('role')) $userUpdates['role'] = $request->role;
                    if ($request->has('job_role_id')) $userUpdates['job_role_id'] = $request->job_role_id;
                    if ($request->has('is_active')) $userUpdates['is_active'] = $request->is_active;
                    if ($request->has('google_id')) $userUpdates['google_id'] = $request->google_id;
                    if ($request->has('apple_id')) $userUpdates['apple_id'] = $request->apple_id;
                    if ($request->has('samsung_id')) $userUpdates['samsung_id'] = $request->samsung_id;
                    if ($request->has('avatar')) $userUpdates['avatar'] = $request->avatar;

                    if ($request->filled('password')) {
                        $userUpdates['password'] = Hash::make($request->password);
                    }

                    if (!empty($userUpdates)) {
                        $user->update($userUpdates);
                    }
                }
            } else {
                if ($request->has('role') && in_array($request->role, ['admin', 'supervisor'])) {
                    $user = User::create([
                        'name' => $request->input('name', $employee->name),
                        'email' => $request->input('email', $employee->email),
                        'password' => Hash::make($request->input('password', 'password123')),
                        'role' => $request->role,
                        'job_role_id' => $request->input('job_role_id', $employee->job_role_id),
                        'tenant_id' => $employee->tenant_id ?? $tenantId,
                        'is_active' => $request->input('is_active', true),
                        'avatar' => $request->input('avatar', $employee->avatar)
                    ]);
                    $employee->user_id = $user->id;
                    $employee->save();
                }
            }

            DB::commit();
            return response()->json($employee->load('user'));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error al actualizar colaborador: ' . $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        $employee = Employee::findOrFail($id);

        try {
            DB::beginTransaction();

            // Desactivar el estado operativo del colaborador
            $employee->update(['is_active_employee' => false]);

            // Si tiene usuario enlazado, desactivar su acceso web (pero no eliminarlo por completo para conservar integridad, y nunca tocar cuentas admin)
            if ($employee->user_id) {
                $user = User::withoutGlobalScopes()->find($employee->user_id);
                if ($user && $user->role !== 'admin') {
                    $user->update(['is_active' => false]);
                }
            }

            DB::commit();
            return response()->json(['message' => 'Employee deactivated']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error al desactivar colaborador: ' . $e->getMessage()], 500);
        }
    }

    public function forceDestroy($id)
    {
        $employee = Employee::findOrFail($id);
        $currentUser = auth()->user() ?? auth('sanctum')->user();

        // Evitar que el administrador principal se borre a sí mismo
        if ($employee->user_id && $employee->user_id === $currentUser->id) {
            return response()->json(['error' => 'No puedes eliminar tu propia cuenta de administrador.'], 403);
        }

        try {
            DB::beginTransaction();

            $userId = $employee->user_id;

            // Eliminar físicamente al empleado
            $employee->delete();

            // Si tiene usuario enlazado, eliminarlo físicamente también (excepto si es el tenant owner / admin principal)
            if ($userId) {
                $user = User::withoutGlobalScopes()->find($userId);
                if ($user) {
                    // Si el usuario es el creador original o tiene rol admin, comprobar que no estemos eliminando al único admin
                    if ($user->role === 'admin') {
                        $adminCount = User::where('tenant_id', $user->tenant_id)
                            ->where('role', 'admin')
                            ->count();
                        if ($adminCount <= 1) {
                            DB::rollBack();
                            return response()->json(['error' => 'No se puede eliminar al último administrador del sistema.'], 403);
                        }
                    }
                    $user->delete();
                }
            }

            DB::commit();
            return response()->json(['message' => 'Employee and user permanently deleted']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error al eliminar definitivamente al colaborador: ' . $e->getMessage()], 500);
        }
    }
}
