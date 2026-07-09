<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add position column to obsidian_documents
        Schema::table('obsidian_documents', function (Blueprint $table) {
            $table->integer('position')->default(0);
        });

        // 2. Create pivot table for document job role visibility
        Schema::create('obsidian_document_job_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('document_id')->constrained('obsidian_documents')->onDelete('cascade');
            $table->foreignId('job_role_id')->constrained('job_roles')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['document_id', 'job_role_id'], 'obs_doc_role_unique');
        });

        // 3. Auto-populate existing documents to all active job roles for seamless backward compatibility
        try {
            $tenants = DB::table('tenants')->get();
            foreach ($tenants as $t) {
                $docs = DB::table('obsidian_documents')->where('tenant_id', $t->id)->get();
                $roles = DB::table('job_roles')->where('tenant_id', $t->id)->where('is_active', true)->get();
                
                $insertData = [];
                foreach ($docs as $doc) {
                    foreach ($roles as $role) {
                        $insertData[] = [
                            'tenant_id' => $t->id,
                            'document_id' => $doc->id,
                            'job_role_id' => $role->id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }
                if (!empty($insertData)) {
                    foreach (array_chunk($insertData, 200) as $chunk) {
                        DB::table('obsidian_document_job_role')->insert($chunk);
                    }
                }
            }
        } catch (\Exception $e) {
            // Silent catch to prevent migration failure in dry/empty dev environments
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('obsidian_document_job_role');

        Schema::table('obsidian_documents', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
