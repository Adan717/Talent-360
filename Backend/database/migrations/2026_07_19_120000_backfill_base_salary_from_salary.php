<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * R78 (Fase 0, bug de dinero): la nómina lee `employees.base_salary ?? salary` (ClockService:666),
 * pero la UI de RRHH escribía `salary`. En tenants con `base_salary` poblada (migrados desde el
 * esquema viejo), editar el sueldo no cambiaba el pago.
 *
 * A partir de R78 la UI escribe `base_salary` (la columna canónica). Este backfill rellena
 * `base_salary` SÓLO donde está vacía, copiándola de `salary`. Es DELIBERADAMENTE conservador: NO
 * toca ningún `base_salary` ya existente, así que **no cambia el pago actual de ningún empleado** —
 * sólo evita que quien tenía el sueldo únicamente en `salary` lo pierda al pasar a leer `base_salary`.
 *
 * Nota: para empleados donde `salary` y `base_salary` YA difieren, el pago se sigue basando en
 * `base_salary` (lo que se pagaba). Si RRHH quiere que gane el valor de `salary`, basta re-guardar el
 * sueldo en la ficha (ahora sí escribe `base_salary`). Una migración masiva salary→base_salary sería
 * un cambio deliberado de nómina y va aparte, con respaldo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('employees')
            ->whereNull('base_salary')
            ->whereNotNull('salary')
            ->update(['base_salary' => DB::raw('salary')]);
    }

    public function down(): void
    {
        // No se revierte: el backfill sólo rellenó nulos y no destruyó datos (`salary` sigue intacta).
    }
};
