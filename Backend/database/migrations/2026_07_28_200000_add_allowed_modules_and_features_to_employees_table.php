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
        Schema::table('employees', function (Blueprint $table) {
            if (!Schema::hasColumn('employees', 'allowed_modules')) {
                $table->json('allowed_modules')->nullable()->after('clock_preferences');
            }
            if (!Schema::hasColumn('employees', 'allowed_features')) {
                $table->json('allowed_features')->nullable()->after('allowed_modules');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['allowed_modules', 'allowed_features']);
        });
    }
};
