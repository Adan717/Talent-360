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
        Schema::table('job_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('org_parent_role_id')->nullable()->after('reports_to_role_id');
            $table->foreign('org_parent_role_id')->references('id')->on('job_roles')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_roles', function (Blueprint $table) {
            $table->dropForeign(['org_parent_role_id']);
            $table->dropColumn('org_parent_role_id');
        });
    }
};
