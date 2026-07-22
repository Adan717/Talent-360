<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('user_wallets')) {
            Schema::create('user_wallets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->default(1);
                $table->unsignedBigInteger('user_id');
                $table->decimal('balance_coins', 12, 2)->default(0.00);
                $table->decimal('total_earned_coins', 12, 2)->default(0.00);
                $table->integer('xp_points')->default(0);
                $table->integer('level')->default(1);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'user_id']);
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('wallet_transactions')) {
            Schema::create('wallet_transactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->default(1);
                $table->unsignedBigInteger('user_id');
                $table->enum('type', ['earned_task', 'redeemed', 'bonus', 'penalty'])->default('earned_task');
                $table->decimal('amount', 12, 2)->default(0.00);
                $table->integer('xp_amount')->default(0);
                $table->string('reference_type')->nullable(); // e.g. TaskAssignment
                $table->string('reference_id')->nullable();
                $table->text('description')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('user_wallets');
    }
};
