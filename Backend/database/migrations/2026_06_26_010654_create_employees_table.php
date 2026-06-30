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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('employee_id')->nullable();
            $table->foreignId('job_role_id')->nullable()->constrained('job_roles')->onDelete('set null');
            $table->decimal('salary', 10, 2)->nullable();
            $table->decimal('base_salary', 10, 2)->nullable();
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
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
