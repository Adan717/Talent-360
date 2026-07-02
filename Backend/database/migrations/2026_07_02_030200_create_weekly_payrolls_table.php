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
        Schema::create('weekly_payrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('base_salary_paid', 10, 2);
            $table->integer('lates_count')->default(0);
            $table->integer('absences_count')->default(0);
            $table->decimal('rest_day_proportion', 5, 2)->default(1.00);
            $table->decimal('deductions', 10, 2)->default(0.00);
            $table->decimal('net_pay', 10, 2);
            $table->timestamp('employee_approved_at')->nullable();
            $table->string('status')->default('pending_employee'); // pending_employee, approved_by_employee, finalized, paid
            $table->timestamps();

            $table->unique(['employee_id', 'start_date', 'end_date'], 'emp_payroll_period_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('weekly_payrolls');
    }
};
