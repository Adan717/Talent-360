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
        // 1. Vaults table
        Schema::create('obsidian_vaults', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->string('name');
            $table->string('local_path')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        // 2. Documents table
        Schema::create('obsidian_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('vault_id')->nullable()->constrained('obsidian_vaults')->onDelete('set null');
            $table->string('slug');
            $table->string('filename');
            $table->string('title');
            $table->longText('content')->nullable(); // Parsed HTML content
            $table->longText('raw_content')->nullable(); // Raw markdown
            $table->jsonb('frontmatter')->nullable(); // Extracted YAML frontmatter
            $table->string('icon')->nullable();
            $table->string('type')->default('nota'); // puesto, proceso, tarea, nota
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });

        // 3. Links table
        Schema::create('obsidian_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('source_document_id')->constrained('obsidian_documents')->onDelete('cascade');
            $table->foreignId('target_document_id')->constrained('obsidian_documents')->onDelete('cascade');
            $table->string('link_text')->nullable();
            $table->timestamps();
        });

        // 4. Suggestions table
        Schema::create('obsidian_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('document_id')->constrained('obsidian_documents')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('author_name')->nullable();
            $table->longText('original_content');
            $table->longText('proposed_content');
            $table->text('comment')->nullable();
            $table->string('status')->default('pending'); // pending, approved, rejected
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_comment')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('obsidian_suggestions');
        Schema::dropIfExists('obsidian_links');
        Schema::dropIfExists('obsidian_documents');
        Schema::dropIfExists('obsidian_vaults');
    }
};
