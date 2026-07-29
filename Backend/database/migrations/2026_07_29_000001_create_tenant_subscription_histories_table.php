<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_subscription_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('billing_plan_id')->nullable()->constrained('billing_plans')->onDelete('set null');
            $table->string('plan_code')->default('freemium'); // freemium, pro, enterprise, custom
            $table->string('plan_name_at_time')->default('Freemium');
            $table->decimal('monthly_price_at_time', 10, 2)->default(0.00);
            $table->string('currency')->default('USD');
            $table->string('billing_cycle')->default('monthly'); // monthly, annual
            $table->integer('modules_count_at_time')->default(0);
            $table->json('modules_snapshot_json')->nullable();
            $table->json('features_snapshot_json')->nullable();
            $table->integer('max_users_at_time')->default(5);
            $table->integer('active_users_at_time')->default(0);
            $table->string('change_reason')->default('initial_registration'); // initial_registration, plan_upgrade, plan_downgrade, custom_module_added, price_adjustment, trial_started, manual_admin_update
            $table->timestamp('effective_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->string('status')->default('active'); // active, superseded, cancelled
            $table->softDeletes();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['effective_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_subscription_histories');
    }
};
