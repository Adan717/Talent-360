<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Identity
            $table->string('employee_id')->nullable();
            $table->string('curp')->nullable();
            $table->string('rfc')->nullable();
            $table->string('nss')->nullable();
            // Contact
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone')->nullable();
            // Employment
            $table->date('hire_date')->nullable();
            $table->string('contract_type')->default('Fijo');
            $table->decimal('base_salary', 10, 2)->nullable();
            // Security / Access
            $table->string('google_id')->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::table('job_roles', function (Blueprint $table) {
            $table->text('description')->nullable();
            $table->text('responsibilities')->nullable();
            $table->foreignId('reports_to_role_id')->nullable()->constrained('job_roles')->onDelete('set null');
            $table->integer('late_penalty_multiplier')->default(1);
            $table->text('required_equipment')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'employee_id', 'curp', 'rfc', 'nss', 'phone', 'address', 
                'emergency_contact_name', 'emergency_contact_phone', 
                'hire_date', 'contract_type', 'base_salary', 'google_id', 'is_active'
            ]);
        });

        Schema::table('job_roles', function (Blueprint $table) {
            $table->dropForeign(['reports_to_role_id']);
            $table->dropColumn([
                'description', 'responsibilities', 'reports_to_role_id', 
                'late_penalty_multiplier', 'required_equipment'
            ]);
        });
    }
};
