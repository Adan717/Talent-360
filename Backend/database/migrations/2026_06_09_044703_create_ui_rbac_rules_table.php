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
        Schema::create('ui_rbac_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('job_role_id');
            $table->string('state'); // 'active', 'rest', 'absent'
            $table->string('module'); // 'checador', 'historial', etc.
            $table->timestamps();

            $table->foreign('job_role_id')->references('id')->on('job_roles')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ui_rbac_rules');
    }
};
