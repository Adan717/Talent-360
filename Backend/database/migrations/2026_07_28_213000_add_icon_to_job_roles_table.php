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
        if (Schema::hasTable('job_roles') && !Schema::hasColumn('job_roles', 'icon')) {
            Schema::table('job_roles', function (Blueprint $table) {
                $table->string('icon', 50)->nullable()->after('area');
            });
        }

        if (Schema::hasTable('job_role_templates') && !Schema::hasColumn('job_role_templates', 'icon')) {
            Schema::table('job_role_templates', function (Blueprint $table) {
                $table->string('icon', 50)->nullable()->after('area');
            });
        }

        // Poblar iconos alusivos ejecutivos en registros existentes de job_roles
        $mappings = [
            'Administrador Gerente' => 'shield-check',
            'Supervisor de Compras' => 'scale',
            'Supervisor de Ventas' => 'bar-chart-3',
            'Supervisor de Producción' => 'workflow',
            'Asesor de Ventas' => 'user-check',
            'Atención al Cliente' => 'user-check',
            'Ayudante Integral' => 'clipboard-check',
            'Apoyo Eventual' => 'clock',
            'Gerente de Sucursal' => 'store',
            'Supervisor de Tienda' => 'store',
            'Cajero' => 'credit-card',
            'Cajero(a)' => 'credit-card',
            'Supervisor de Cajas' => 'credit-card',
            'Ayudante de Ventas' => 'user-check',
            'Almacenista' => 'package',
            'Gerente General' => 'shield-check',
            'Director de Operaciones' => 'building-2',
            'Recepcionista' => 'headset',
            'Contador' => 'calculator',
            'Recursos Humanos' => 'user-plus',
            'Cocinero' => 'utensils',
            'Mesero' => 'utensils',
        ];

        foreach ($mappings as $roleName => $iconKey) {
            DB::table('job_roles')
                ->whereRaw('LOWER(name) = ?', [strtolower($roleName)])
                ->where(function ($q) {
                    $q->whereNull('icon')->orWhere('icon', '');
                })
                ->update(['icon' => $iconKey]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('job_roles') && Schema::hasColumn('job_roles', 'icon')) {
            Schema::table('job_roles', function (Blueprint $table) {
                $table->dropColumn('icon');
            });
        }

        if (Schema::hasTable('job_role_templates') && Schema::hasColumn('job_role_templates', 'icon')) {
            Schema::table('job_role_templates', function (Blueprint $table) {
                $table->dropColumn('icon');
            });
        }
    }
};
