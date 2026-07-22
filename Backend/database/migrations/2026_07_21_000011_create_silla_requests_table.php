<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('silla_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->integer('store_id')->default(1);
            $table->foreignId('employee_id')->constrained('users')->onDelete('cascade');
            $table->timestamp('requested_at');
            $table->string('status')->default('pending'); // pending|approved|rejected|active|finished
            $table->foreignId('approved_by_employee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approval_method')->nullable(); // pin|qr|remote
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('silla_requests');
    }
};
