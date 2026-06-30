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
            $table->string('rfc')->nullable();
            $table->string('tax_name')->nullable(); // Razón Social
            $table->string('tax_regimen')->nullable(); // Régimen Fiscal (e.g. 601)
            $table->string('postal_code')->nullable();
            $table->text('csd_certificate')->nullable(); // Encrypted base64
            $table->text('csd_private_key')->nullable(); // Encrypted base64
            $table->text('csd_password')->nullable(); // Encrypted password
            $table->string('facturapi_organization_id')->nullable(); // Associated Facturapi org ID
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'rfc',
                'tax_name',
                'tax_regimen',
                'postal_code',
                'csd_certificate',
                'csd_private_key',
                'csd_password',
                'facturapi_organization_id'
            ]);
        });
    }
};
