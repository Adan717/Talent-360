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
        Schema::table('pending_registrations', function (Blueprint $table) {
            $table->string('admin_email')->nullable()->index()->after('id');
            $table->string('subdomain')->nullable()->index()->after('admin_email');
            $table->string('status')->default('pending')->index()->after('subdomain');
            $table->text('checkout_url')->nullable()->after('status');
            $table->timestamp('reminded_at')->nullable()->after('checkout_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pending_registrations', function (Blueprint $table) {
            $table->dropColumn(['admin_email', 'subdomain', 'status', 'checkout_url', 'reminded_at']);
        });
    }
};
