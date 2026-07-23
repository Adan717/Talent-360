<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §39: snapshot por pedido de qué puesto es responsable de cada etapa.
     * Se copia de supply_chain_stage_roles (config del tenant) cuando el pedido se
     * crea, para que cambiar la config del tenant después no reasigne pedidos que ya
     * están en curso. job_role_id es nullable por si el tenant no configuró alguna
     * etapa: en ese caso el avance a esa etapa simplemente no notifica a nadie.
     */
    public function up(): void
    {
        Schema::create('supply_order_stage_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supply_order_id')->constrained('supply_orders')->onDelete('cascade');
            $table->string('stage');
            $table->foreignId('job_role_id')->nullable()->constrained('job_roles')->onDelete('set null');
            $table->timestamps();

            $table->unique(['supply_order_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_order_stage_roles');
    }
};
