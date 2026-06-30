<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Migrate existing users data to employees table
        $users = DB::table('users')->get();
        foreach ($users as $user) {
            DB::table('employees')->insert([
                'tenant_id' => $user->tenant_id ?? 1,
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone ?? null,
                'employee_id' => $user->employee_id ?? null,
                'job_role_id' => $user->job_role_id ?? null,
                'salary' => $user->salary ?? null,
                'base_salary' => $user->base_salary ?? null,
                'curp' => $user->curp ?? null,
                'rfc' => $user->rfc ?? null,
                'nss' => $user->nss ?? null,
                'address' => $user->address ?? null,
                'emergency_contact_name' => $user->emergency_contact_name ?? null,
                'emergency_contact_phone' => $user->emergency_contact_phone ?? null,
                'hire_date' => $user->hire_date ?? null,
                'contract_type' => $user->contract_type ?? null,
                'is_active_employee' => $user->is_active_employee ?? true,
                'shiftStart' => $user->shiftStart ?? null,
                'shiftEnd' => $user->shiftEnd ?? null,
                'mealMinutes' => $user->mealMinutes ?? 60,
                'restDay' => $user->restDay ?? null,
                'pin_code' => $user->pin_code ?? null,
                'invite_token' => $user->invite_token ?? null,
                'portadorLlaves' => $user->portadorLlaves ?? 'ninguno',
                'created_at' => $user->created_at ?? now(),
                'updated_at' => $user->updated_at ?? now()
            ]);
        }

        // 2. Restore development admin password for admin@pcmaster67.com to 123456789
        DB::table('users')
            ->where('email', 'admin@pcmaster67.com')
            ->update(['password' => Hash::make('123456789')]);

        // 3. Drop operational columns from users table
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['job_role_id']);
            $table->dropColumn([
                'salary',
                'base_salary',
                'phone',
                'employee_id',
                'curp',
                'rfc',
                'nss',
                'address',
                'emergency_contact_name',
                'emergency_contact_phone',
                'hire_date',
                'contract_type',
                'is_active_employee',
                'shiftStart',
                'shiftEnd',
                'mealMinutes',
                'restDay',
                'pin_code',
                'invite_token',
                'portadorLlaves'
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Re-adding dropped columns to users table
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('job_role_id')->nullable();
            $table->decimal('salary', 10, 2)->nullable();
            $table->decimal('base_salary', 10, 2)->nullable();
            $table->string('phone')->nullable();
            $table->string('employee_id')->nullable();
            $table->string('curp')->nullable();
            $table->string('rfc')->nullable();
            $table->string('nss')->nullable();
            $table->string('address')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone')->nullable();
            $table->date('hire_date')->nullable();
            $table->string('contract_type')->nullable();
            $table->boolean('is_active_employee')->default(true);
            $table->time('shiftStart')->nullable();
            $table->time('shiftEnd')->nullable();
            $table->integer('mealMinutes')->default(60);
            $table->string('restDay')->nullable();
            $table->string('pin_code', 6)->nullable();
            $table->string('invite_token', 32)->nullable();
            $table->string('portadorLlaves')->default('ninguno');
        });

        // Pull back data from employees table if needed (optional best-effort)
        $employees = DB::table('employees')->get();
        foreach ($employees as $emp) {
            if ($emp->user_id) {
                DB::table('users')->where('id', $emp->user_id)->update([
                    'job_role_id' => $emp->job_role_id,
                    'salary' => $emp->salary,
                    'base_salary' => $emp->base_salary,
                    'phone' => $emp->phone,
                    'employee_id' => $emp->employee_id,
                    'curp' => $emp->curp,
                    'rfc' => $emp->rfc,
                    'nss' => $emp->nss,
                    'address' => $emp->address,
                    'emergency_contact_name' => $emp->emergency_contact_name,
                    'emergency_contact_phone' => $emp->emergency_contact_phone,
                    'hire_date' => $emp->hire_date,
                    'contract_type' => $emp->contract_type,
                    'is_active_employee' => $emp->is_active_employee,
                    'shiftStart' => $emp->shiftStart,
                    'shiftEnd' => $emp->shiftEnd,
                    'mealMinutes' => $emp->mealMinutes,
                    'restDay' => $emp->restDay,
                    'pin_code' => $emp->pin_code,
                    'invite_token' => $emp->invite_token,
                    'portadorLlaves' => $emp->portadorLlaves,
                ]);
            }
        }
    }
};
