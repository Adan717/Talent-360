<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seasonal_promotions', function (Blueprint $table) {
            $table->id();
            $table->string('title'); // ej. "Promo Día del Padre"
            $table->string('subtitle')->nullable();
            $table->string('badge_text')->default('20% OFF');
            $table->decimal('discount_percentage', 5, 2)->default(20.00);
            $table->string('target_plan')->default('all'); // pro, enterprise, all
            $table->string('banner_bg_color')->default('from-blue-600 to-indigo-700');
            $table->string('banner_text_color')->default('text-white');
            $table->string('cta_label')->default('Ver Oferta Especial');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seasonal_promotions');
    }
};
