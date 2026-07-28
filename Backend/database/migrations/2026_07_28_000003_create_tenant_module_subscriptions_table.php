<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_module_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->string('module_key');
            $table->string('access_type')->default('social_grace_period'); // addon_paid, social_grace_period
            $table->integer('grace_days_granted')->default(30);
            $table->text('proof_url')->nullable();
            $table->text('proof_note')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status')->default('pending_approval'); // pending_approval, active, expired, rejected, canceled
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['tenant_id', 'module_key', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_module_subscriptions');
    }
};
