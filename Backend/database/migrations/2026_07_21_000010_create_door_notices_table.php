<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('door_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('from_employee_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('to_employee_id')->constrained('users')->onDelete('cascade');
            $table->date('date');
            $table->string('message');
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'to_employee_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('door_notices');
    }
};
