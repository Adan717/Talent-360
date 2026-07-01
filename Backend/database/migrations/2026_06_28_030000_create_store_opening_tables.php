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
        // 1. platform_features
        Schema::create('platform_features', function (Blueprint $table) {
            $table->id();
            $table->string('feature_key')->unique();
            $table->string('feature_name');
            $table->string('module_key');
            $table->text('description')->nullable();
            $table->boolean('is_premium')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 2. company_features
        Schema::create('company_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->integer('company_id')->nullable();
            $table->string('feature_key');
            $table->foreign('feature_key')->references('feature_key')->on('platform_features')->onDelete('cascade');
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('enabled_from')->nullable();
            $table->timestamp('enabled_until')->nullable();
            $table->timestamps();
        });

        // 3. store_opening_settings
        Schema::create('store_opening_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->integer('company_id')->nullable();
            $table->integer('store_id')->default(1);
            $table->boolean('is_enabled')->default(true);
            $table->integer('pre_opening_window_minutes')->default(15);
            $table->integer('absence_late_report_window_minutes')->default(5);
            $table->integer('early_clock_in_allowed_minutes')->default(10);
            $table->boolean('allow_automatic_handoff')->default(true);
            $table->boolean('allow_late_if_before_opening')->default(true);
            $table->boolean('allow_store_closed_report')->default(true);
            $table->boolean('enable_amnesty_if_store_closed')->default(true);
            $table->boolean('require_opening_checklist')->default(true);
            $table->boolean('require_opening_roll_call')->default(true);
            $table->boolean('notify_admin_on_handoff')->default(true);
            $table->boolean('notify_supervisor_on_handoff')->default(true);
            $table->timestamps();
        });

        // 4. store_opening_assignments
        Schema::create('store_opening_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->integer('company_id')->nullable();
            $table->integer('store_id')->default(1);
            $table->foreignId('employee_id')->constrained('users')->onDelete('cascade');
            $table->integer('priority_order')->default(1);
            $table->boolean('can_open_store')->default(true);
            $table->boolean('can_close_store')->default(true);
            $table->boolean('has_keys')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 5. store_daily_opening_statuses
        Schema::create('store_daily_opening_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->integer('company_id')->nullable();
            $table->integer('store_id')->default(1);
            $table->date('date');
            $table->time('scheduled_opening_time');
            $table->time('pre_opening_window_start');
            $table->time('report_deadline');
            $table->foreignId('current_responsible_employee_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('status')->default('pending'); // pending, waiting_window, active_window, opened, transferred, failed, closed_reported_by_employees
            $table->foreignId('opened_by_employee_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
        });

        // 6. store_opening_events
        Schema::create('store_opening_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->integer('company_id')->nullable();
            $table->integer('store_id')->default(1);
            $table->foreignId('employee_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('event_type');
            $table->string('event_status')->default('success');
            $table->time('scheduled_opening_time')->nullable();
            $table->timestamp('event_time');
            $table->time('estimated_arrival_time')->nullable();
            $table->foreignId('previous_employee_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('next_employee_id')->nullable()->constrained('users')->onDelete('set null');
            $table->text('notes')->nullable();
            $table->jsonb('metadata_json')->nullable();
            $table->timestamps();
        });

        // SEED INITIAL PREMIUM FEATURE AND TENANT 1 CONFIG
        try {
            // Asegurar que exista el Tenant 1 y Company 1 para no violar restricciones de llave foránea en DBs limpias
            DB::table('tenants')->insertOrIgnore([
                'id' => 1,
                'name' => 'DecorArte 360',
                'subdomain' => 'default',
                'public_slug' => 'default',
                'plan' => 'pro',
                'max_users' => 100,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            DB::table('companies')->insertOrIgnore([
                'id' => 1,
                'name' => 'DecorArte 360',
                'is_active' => true,
                'tenant_id' => 1,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            DB::table('platform_features')->insert([
                'feature_key' => 'store_opening',
                'feature_name' => 'Apertura de tienda',
                'module_key' => 'reloj',
                'description' => 'Permite configurar jerarquías, tolerancias, checklists y pase de lista automatizados durante la apertura de sucursales.',
                'is_premium' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Enable for Tenant 1 (DecorArte 360)
            DB::table('company_features')->insert([
                'tenant_id' => 1,
                'company_id' => 1,
                'feature_key' => 'store_opening',
                'is_enabled' => true,
                'enabled_from' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Default settings for Tenant 1
            DB::table('store_opening_settings')->insert([
                'tenant_id' => 1,
                'company_id' => 1,
                'store_id' => 1,
                'is_enabled' => true,
                'pre_opening_window_minutes' => 15,
                'absence_late_report_window_minutes' => 5,
                'early_clock_in_allowed_minutes' => 10,
                'allow_automatic_handoff' => true,
                'allow_late_if_before_opening' => true,
                'allow_store_closed_report' => true,
                'enable_amnesty_if_store_closed' => true,
                'require_opening_checklist' => true,
                'require_opening_roll_call' => true,
                'notify_admin_on_handoff' => true,
                'notify_supervisor_on_handoff' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Seed default assignments for DecorArte (Tenant 1): Priority order
            // 1. Super Admin (ID 20)
            // 2. Francisco (ID 11)
            // 3. Liz (ID 12)
            $users = [
                ['id' => 20, 'priority' => 1],
                ['id' => 11, 'priority' => 2],
                ['id' => 12, 'priority' => 3],
            ];
            foreach ($users as $user) {
                if (DB::table('users')->where('id', $user['id'])->exists()) {
                    DB::table('store_opening_assignments')->insert([
                        'tenant_id' => 1,
                        'company_id' => 1,
                        'store_id' => 1,
                        'employee_id' => $user['id'],
                        'priority_order' => $user['priority'],
                        'can_open_store' => true,
                        'can_close_store' => true,
                        'has_keys' => true,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            // Silently skip if database state is altered during test setups
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_opening_events');
        Schema::dropIfExists('store_daily_opening_statuses');
        Schema::dropIfExists('store_opening_assignments');
        Schema::dropIfExists('store_opening_settings');
        Schema::dropIfExists('company_features');
        Schema::dropIfExists('platform_features');
    }
};
