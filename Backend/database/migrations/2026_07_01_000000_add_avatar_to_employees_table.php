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
        Schema::table('employees', function (Blueprint $table) {
            $table->string('avatar')->nullable()->after('user_id');
        });

        // Copiar los avatares existentes desde la tabla users a la de employees
        $users = DB::table('users')->whereNotNull('avatar')->get();
        foreach ($users as $user) {
            DB::table('employees')
                ->where('user_id', $user->id)
                ->update(['avatar' => $user->avatar]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('avatar');
        });
    }
};
