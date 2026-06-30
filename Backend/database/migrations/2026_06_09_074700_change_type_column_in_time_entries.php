<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // For SQLite enum changing, we need to bypass or drop it.
        // Easiest is to change type to string.
        Schema::table('time_entries', function (Blueprint $table) {
            $table->string('type')->change();
        });
    }

    public function down(): void
    {
    }
};
