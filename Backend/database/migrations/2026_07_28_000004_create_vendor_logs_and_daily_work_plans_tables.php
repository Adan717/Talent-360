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
        // 1. Tabla de Registro de Proveedores y Visitas (Inbound Tracker)
        if (!Schema::hasTable('vendor_logs')) {
            Schema::create('vendor_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->default(1)->index();
                $table->unsignedBigInteger('company_id')->nullable()->index();
                $table->string('vendor_name');
                $table->string('driver_name')->default('Repartidor');
                $table->string('order_ref')->nullable();
                $table->timestamp('arrival_at')->useCurrent();
                $table->timestamp('departure_at')->nullable();
                $table->enum('status', ['in_premises', 'completed', 'rejected'])->default('in_premises');
                $table->string('photo_evidence_url')->nullable();
                $table->unsignedBigInteger('received_by_user_id')->nullable();
                $table->string('received_by_name_snapshot')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'status', 'created_at']);
            });
        }

        // 2. Tabla de Planes de Trabajo Diarios Generados por IA
        if (!Schema::hasTable('daily_work_plans')) {
            Schema::create('daily_work_plans', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->default(1)->index();
                $table->unsignedBigInteger('company_id')->nullable()->index();
                $table->date('plan_date')->index();
                $table->text('ai_summary')->nullable();
                $table->json('missing_roles_impact')->nullable();
                $table->json('assignments_json')->nullable();
                $table->enum('status', ['draft', 'approved', 'active', 'archived'])->default('draft');
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->string('created_by_name_snapshot')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_work_plans');
        Schema::dropIfExists('vendor_logs');
    }
};
