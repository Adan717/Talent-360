<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('tenants') && Schema::hasColumn('tenants', 'deleted_at')) {
            $deletedTenants = DB::table('tenants')
                ->whereNotNull('deleted_at')
                ->where('subdomain', 'not like', '%_deleted_%')
                ->get();

            foreach ($deletedTenants as $tenant) {
                $suffix = '_deleted_' . time() . '_' . $tenant->id;
                DB::table('tenants')
                    ->where('id', $tenant->id)
                    ->update([
                        'subdomain' => $tenant->subdomain . $suffix,
                        'public_slug' => $tenant->public_slug ? ($tenant->public_slug . $suffix) : null,
                    ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No action needed for releasing deleted subdomains
    }
};
