<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `employees.hire_date` era opcional y NADIE la llenaba: ninguno de los dos puntos de alta
 * —el asistente de onboarding y Recursos Humanos— la mandaba, así que estaba vacía en el 100%
 * de los colaboradores vivos (4 de 4 al medir la V2 el 2026-08-05).
 *
 * Es un dato de NEGOCIO, no técnico: es cuándo empezó a trabajar la persona, y de ahí cuelgan
 * la antigüedad, el aguinaldo y el finiquito el día que existan. Hoy sólo la lee
 * `TaskValidationPolicy`, y ahora también el aviso de "lleva N días sin completar su inducción",
 * que sin fecha de alta no tiene desde cuándo contar.
 *
 * A partir de aquí es OBLIGATORIA en el alta. Esta migración rellena lo ya existente con
 * `created_at`, que es lo más cercano que hay. **Es una aproximación, no un dato legal**: la
 * fecha en que se creó el registro no tiene por qué ser el día que la persona entró a trabajar
 * (un alta el viernes para que empiece el lunes son fechas distintas). Los registros rellenados
 * aquí deben revisarse a mano si alguna vez se usan para calcular una liquidación.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('employees')
            ->whereNull('hire_date')
            ->update(['hire_date' => DB::raw('DATE(created_at)')]);
    }

    public function down(): void
    {
        // No se revierte: distinguir un `hire_date` rellenado por esta migración de uno escrito
        // a mano exigiría una columna extra, y volver a poner NULL borraría también los buenos.
    }
};
