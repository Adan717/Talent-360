<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_opening_settings', function (Blueprint $table) {
            $table->boolean('require_closing_checklist')->default(true)->after('require_opening_checklist');
        });
    }

    public function down(): void
    {
        Schema::table('store_opening_settings', function (Blueprint $table) {
            $table->dropColumn('require_closing_checklist');
        });
    }
};
