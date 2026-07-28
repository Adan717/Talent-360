<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_addons', function (Blueprint $table) {
            $table->id();
            $table->string('module_key')->unique(); // gps_clock, task_runner, live_monitor, payroll, ats, academia, lft, documentos, facturacion
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price_per_employee', 8, 2)->default(15.00); // 10-25 MXN/colab
            $table->decimal('min_monthly_price', 8, 2)->default(100.00);
            $table->string('icon_name')->default('Zap');
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_addons');
    }
};
