<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §39: configuración por tenant de la cadena de pedidos — qué puesto
     * (job_role) es responsable de cada etapa. Se configura una vez por empresa;
     * cada pedido nuevo hace un "snapshot" de esta config a supply_order_stage_roles
     * al crearse, para que cambiar la config después no altere pedidos en curso.
     * Etapas: generado, por_llegar, recibido, almacenado, listo_exhibir.
     */
    public function up(): void
    {
        Schema::create('supply_chain_stage_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->string('stage');
            $table->foreignId('job_role_id')->constrained('job_roles')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['tenant_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_chain_stage_roles');
    }
};
