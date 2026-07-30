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
        // Drop foreign key first if exists
        try {
            Schema::table('store_opening_assignments', function (Blueprint $table) {
                $table->dropForeign(['employee_id']);
            });
        } catch (\Throwable $e) {}

        // 1. Migrar datos de user_id a employee_id de forma quirúrgica
        $assignments = Illuminate\Support\Facades\DB::table('store_opening_assignments')->get();
        foreach ($assignments as $assignment) {
            $employee = Illuminate\Support\Facades\DB::table('employees')->where('user_id', $assignment->employee_id)->first();
            if ($employee) {
                Illuminate\Support\Facades\DB::table('store_opening_assignments')
                    ->where('id', $assignment->id)
                    ->update(['employee_id' => $employee->id]);
            } else {
                Illuminate\Support\Facades\DB::table('store_opening_assignments')->where('id', $assignment->id)->delete();
            }
        }

        // 2. Cambiar la clave foránea
        Schema::table('store_opening_assignments', function (Blueprint $table) {
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('store_opening_assignments', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
        });

        // Revertir datos: de employee_id de vuelta a user_id
        $assignments = Illuminate\Support\Facades\DB::table('store_opening_assignments')->get();
        foreach ($assignments as $assignment) {
            $employee = Illuminate\Support\Facades\DB::table('employees')->where('id', $assignment->employee_id)->first();
            if ($employee && $employee->user_id) {
                Illuminate\Support\Facades\DB::table('store_opening_assignments')
                    ->where('id', $assignment->id)
                    ->update(['employee_id' => $employee->user_id]);
            } else {
                Illuminate\Support\Facades\DB::table('store_opening_assignments')->where('id', $assignment->id)->delete();
            }
        }

        Schema::table('store_opening_assignments', function (Blueprint $table) {
            $table->foreign('employee_id')->references('id')->on('users')->onDelete('cascade');
        });
    }
};
