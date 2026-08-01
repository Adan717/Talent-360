<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H23: la aprobación del ADMINISTRADOR no se guardaba en ninguna parte.
 *
 * `PayrollController::approvePayroll` devolvía `"Nómina aprobada y lista para timbrar."` sin
 * tocar la base de datos: la pantalla confirmaba una autorización de pago que no existía. La
 * tabla sólo tenía `employee_approved_at` (la firma del trabajador), así que del lado de la
 * empresa no quedaba ni estado, ni fecha, ni responsable.
 *
 * Se añaden las dos columnas que faltaban. `admin_approved_by` apunta a `users` con
 * `nullOnDelete`: si el administrador se da de baja, la nómina conserva la fecha de autorización
 * —el hecho de que se autorizó no desaparece— aunque se pierda el nombre.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('weekly_payrolls')) {
            return;
        }

        Schema::table('weekly_payrolls', function (Blueprint $table) {
            if (!Schema::hasColumn('weekly_payrolls', 'admin_approved_at')) {
                $table->timestamp('admin_approved_at')->nullable()->after('employee_approved_at');
            }
            if (!Schema::hasColumn('weekly_payrolls', 'admin_approved_by')) {
                $table->foreignId('admin_approved_by')->nullable()->after('admin_approved_at')
                    ->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('weekly_payrolls')) {
            return;
        }

        Schema::table('weekly_payrolls', function (Blueprint $table) {
            if (Schema::hasColumn('weekly_payrolls', 'admin_approved_by')) {
                $table->dropConstrainedForeignId('admin_approved_by');
            }
            if (Schema::hasColumn('weekly_payrolls', 'admin_approved_at')) {
                $table->dropColumn('admin_approved_at');
            }
        });
    }
};
