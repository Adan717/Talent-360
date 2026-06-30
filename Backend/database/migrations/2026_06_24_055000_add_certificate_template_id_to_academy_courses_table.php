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
        Schema::table('academy_courses', function (Blueprint $table) {
            $table->unsignedBigInteger('certificate_template_id')->nullable()->after('quiz_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('academy_courses', function (Blueprint $table) {
            $table->dropColumn('certificate_template_id');
        });
    }
};
