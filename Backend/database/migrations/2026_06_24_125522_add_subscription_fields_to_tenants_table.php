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
            $table->string('mp_customer_id')->nullable();
            $table->string('mp_subscription_id')->nullable();
            $table->string('subscription_status')->default('trial'); // trial, active, past_due, cancelled
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_end')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'mp_customer_id',
                'mp_subscription_id',
                'subscription_status',
                'trial_ends_at',
                'current_period_end'
            ]);
        });
    }
};
