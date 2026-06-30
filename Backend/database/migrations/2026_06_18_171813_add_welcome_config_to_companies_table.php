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
        Schema::table('companies', function (Blueprint $table) {
            $table->string('welcome_title')->nullable()->after('max_users');
            $table->text('welcome_message')->nullable()->after('welcome_title');
            $table->string('welcome_image_url')->nullable()->after('welcome_message');
            $table->string('welcome_video_url')->nullable()->after('welcome_image_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['welcome_title', 'welcome_message', 'welcome_image_url', 'welcome_video_url']);
        });
    }
};
