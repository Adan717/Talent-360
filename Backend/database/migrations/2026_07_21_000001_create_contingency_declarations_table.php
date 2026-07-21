<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contingency_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->integer('store_id')->default(1);
            $table->foreignId('declared_by_user_id')->constrained('users');
            $table->date('date');
            $table->string('reason'); // no_power | no_internet | no_power_and_internet
            $table->timestamp('declared_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contingency_declarations');
    }
};
