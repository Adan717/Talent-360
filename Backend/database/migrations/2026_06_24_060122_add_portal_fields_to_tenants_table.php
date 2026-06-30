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
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('public_slug')->nullable()->unique();
            $table->string('brand_color')->nullable()->default('#2563EB');
            $table->string('logo_url')->nullable();
            $table->boolean('public_portal_enabled')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['public_slug', 'brand_color', 'logo_url', 'public_portal_enabled']);
        });
    }
};
