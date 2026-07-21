<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simulator_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('simulated_date'); // la fecha "de mentiras" que usa esta sesión, no la fecha real
            $table->enum('status', ['active', 'closed'])->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simulator_sessions');
    }
};
