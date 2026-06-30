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
        Schema::create('billing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique(); // freemium, pro, enterprise
            $table->decimal('price', 10, 2)->default(0.00);
            $table->string('currency')->default('USD');
            $table->string('billing_interval')->default('month'); // month, year
            $table->string('stripe_price_id')->nullable();
            $table->json('features_json')->nullable(); // Módulos habilitados y límites
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }
 
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_plans');
    }
};
