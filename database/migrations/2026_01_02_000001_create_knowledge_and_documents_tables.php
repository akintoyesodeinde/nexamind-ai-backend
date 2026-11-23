<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_bases', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->uuid('tenant_id');
            $table->string('name', 120);
            $table->string('slug', 100);
            $table->string('description', 1000)->nullable();
            $table->string('visibility', 20)->default('restricted');
            $table->string('status', 20)->default('active');
            $table->uuid('created_by');
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));
            $table->dateTime('updated_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->unique(['tenant_id', 'slug'], 'UQ_knowledge_bases_tenant_slug');
            $table->index(['tenant_id', 'status'], 'IX_knowledge_bases_tenant_status');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('created_by')->references('id')->on('users');
        });

        DB::statement("ALTER TABLE dbo.knowledge_bases ADD CONSTRAINT CK_knowledge_bases_visibility CHECK (visibility IN ('tenant', 'restricted'))");
        DB::statement("ALTER TABLE dbo.knowledge_bases ADD CONSTRAINT CK_knowledge_bases_status CHECK (status IN ('active', 'archived'))");

        Schema::create('knowledge_base_permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->uuid('tenant_id');
            $table->uuid('knowledge_base_id');
            $table->string('principal_type', 10);
            $table->uuid('principal_id');
            $table->string('permission_level', 20);
            $table->uuid('granted_by')->nullable();
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->unique(
                ['knowledge_base_id', 'principal_type', 'principal_id'],
                'UQ_kb_permissions_kb_principal',
            );
            $table->index(['tenant_id', 'principal_type', 'principal_id'], 'IX_kb_permissions_tenant_principal');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('knowledge_base_id')->references('id')->on('knowledge_bases');
            $table->foreign('granted_by')->references('id')->on('users');
        });

        DB::statement("ALTER TABLE dbo.knowledge_base_permissions ADD CONSTRAINT CK_kb_perm_principal_type CHECK (principal_type IN ('user', 'role'))");
        DB::statement("ALTER TABLE dbo.knowledge_base_permissions ADD CONSTRAINT CK_kb_perm_level CHECK (permission_level IN ('read', 'write', 'admin'))");

        Schema::create('documents', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->uuid('tenant_id');
            $table->uuid('knowledge_base_id');
            $table->uuid('uploaded_by');
            $table->string('filename', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('file_size_bytes');
            $table->string('storage_path', 1000);
            $table->char('checksum_sha256', 64);
            $table->unsignedInteger('current_version')->default(1);
            $table->string('access_policy', 20)->default('inherit');
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('page_count')->nullable();
            $table->unsignedInteger('chunk_count')->default(0);
            $table->unsignedInteger('character_count')->default(0);
            $table->string('error_message', 1000)->nullable();
            $table->longText('metadata_json')->nullable();
            $table->dateTime('processed_at', 3)->nullable();
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));
            $table->dateTime('updated_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->index(['tenant_id', 'knowledge_base_id', 'status'], 'IX_documents_tenant_knowledge');
            $table->index(['tenant_id', 'knowledge_base_id', 'checksum_sha256'], 'IX_documents_tenant_checksum');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('knowledge_base_id')->references('id')->on('knowledge_bases');
            $table->foreign('uploaded_by')->references('id')->on('users');
        });

        DB::statement("ALTER TABLE dbo.documents ADD CONSTRAINT CK_documents_status CHECK (status IN ('pending', 'processing', 'ready', 'failed', 'deleted'))");
        DB::statement("ALTER TABLE dbo.documents ADD CONSTRAINT CK_documents_access_policy CHECK (access_policy IN ('inherit', 'restricted'))");

        Schema::create('document_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->uuid('tenant_id');
            $table->uuid('document_id');
            $table->unsignedInteger('version_number');
            $table->string('filename', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('file_size_bytes');
            $table->string('storage_path', 1000);
            $table->char('checksum_sha256', 64);
            $table->uuid('uploaded_by');
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->unique(['document_id', 'version_number'], 'UQ_document_versions_doc_ver');
            $table->index(['tenant_id', 'document_id'], 'IX_document_versions_tenant_doc');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('document_id')->references('id')->on('documents');
            $table->foreign('uploaded_by')->references('id')->on('users');
        });

        Schema::create('document_permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->uuid('tenant_id');
            $table->uuid('document_id');
            $table->string('principal_type', 10);
            $table->uuid('principal_id');
            $table->string('permission_level', 20);
            $table->uuid('granted_by')->nullable();
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->unique(
                ['document_id', 'principal_type', 'principal_id'],
                'UQ_doc_permissions_doc_principal',
            );
            $table->index(['tenant_id', 'document_id', 'principal_type', 'principal_id'], 'IX_doc_permissions_lookup');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('document_id')->references('id')->on('documents');
            $table->foreign('granted_by')->references('id')->on('users');
        });

        DB::statement("ALTER TABLE dbo.document_permissions ADD CONSTRAINT CK_doc_perm_principal_type CHECK (principal_type IN ('user', 'role'))");
        DB::statement("ALTER TABLE dbo.document_permissions ADD CONSTRAINT CK_doc_perm_level CHECK (permission_level IN ('read', 'write', 'admin', 'deny'))");

        Schema::create('document_chunks', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->uuid('tenant_id');
            $table->uuid('knowledge_base_id');
            $table->uuid('document_id');
            $table->uuid('document_version_id');
            $table->unsignedInteger('chunk_index');
            $table->unsignedInteger('page_number')->nullable();
            $table->string('section_heading', 255)->nullable();
            $table->unsignedInteger('char_start')->default(0);
            $table->unsignedInteger('char_end')->default(0);
            $table->unsignedInteger('token_estimate')->default(0);
            $table->longText('content');
            $table->char('content_hash', 64);
            $table->string('embedding_model', 100)->nullable();
            $table->longText('embedding_json')->nullable();
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->index(
                ['tenant_id', 'knowledge_base_id', 'document_id', 'chunk_index'],
                'IX_document_chunks_tenant_kb_doc',
            );
            $table->index(['tenant_id', 'document_id'], 'IX_document_chunks_tenant_doc');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('knowledge_base_id')->references('id')->on('knowledge_bases');
            $table->foreign('document_id')->references('id')->on('documents');
            $table->foreign('document_version_id')->references('id')->on('document_versions');
        });

        // Version-aware native VECTOR(1536) column for SQL Server 2025 & Azure SQL
        $dimensions = max(1, (int) config('ai.embedding_dimensions', 1536));
        try {
            DB::statement("ALTER TABLE dbo.document_chunks ADD embedding VECTOR({$dimensions}) NULL");
        } catch (Throwable) {
            // Portable fallback: older SQL Server versions operate via embedding_json + SqlServerJsonVectorStore
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
        Schema::dropIfExists('document_permissions');
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('knowledge_base_permissions');
        Schema::dropIfExists('knowledge_bases');
    }
};
