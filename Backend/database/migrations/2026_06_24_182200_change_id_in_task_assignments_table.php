<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE task_assignments ALTER COLUMN id TYPE VARCHAR(255);');
        } else {
            Schema::table('task_assignments', function (Blueprint $table) {
                $table->string('id', 255)->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE task_assignments ALTER COLUMN id TYPE BIGINT USING id::BIGINT;');
        } else {
            Schema::table('task_assignments', function (Blueprint $table) {
                $table->bigIncrements('id')->change();
            });
        }
    }
};
