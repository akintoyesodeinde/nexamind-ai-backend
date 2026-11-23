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
        Schema::create('ai_providers', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->uuid('tenant_id')->nullable();
            $table->string('provider_key', 40);
            $table->string('display_name', 120);
            $table->string('endpoint_url', 500)->nullable();
            $table->longText('encrypted_config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));
            $table->dateTime('updated_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->index(['tenant_id', 'provider_key', 'is_active'], 'IX_ai_providers_lookup');
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        DB::statement("ALTER TABLE dbo.ai_providers ADD CONSTRAINT CK_ai_providers_key CHECK (provider_key IN ('mock', 'openai', 'azure_openai', 'gemini'))");

        Schema::create('ai_models', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->uuid('ai_provider_id');
            $table->string('alias', 100);
            $table->string('provider_model_name', 120);
            $table->string('capability', 30)->default('chat');
            $table->unsignedInteger('max_context_tokens')->default(16000);
            $table->decimal('input_cost_per_1k_usd', 18, 6)->default(0);
            $table->decimal('output_cost_per_1k_usd', 18, 6)->default(0);
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));
            $table->dateTime('updated_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->unique(['ai_provider_id', 'alias'], 'UQ_ai_models_provider_alias');
            $table->index(['alias', 'capability', 'is_active'], 'IX_ai_models_alias');
            $table->foreign('ai_provider_id')->references('id')->on('ai_providers');
        });

        DB::statement("ALTER TABLE dbo.ai_models ADD CONSTRAINT CK_ai_models_capability CHECK (capability IN ('chat', 'embedding'))");

        Schema::create('conversations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->string('title', 200)->default('New Conversation');
            $table->string('default_model_alias', 100)->default('default-chat');
            $table->longText('knowledge_base_ids_json')->nullable();
            $table->string('status', 20)->default('active');
            $table->dateTime('last_message_at', 3)->nullable();
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));
            $table->dateTime('updated_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->index(['tenant_id', 'user_id', 'status', 'last_message_at'], 'IX_conversations_tenant_user');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('user_id')->references('id')->on('users');
        });

        DB::statement("ALTER TABLE dbo.conversations ADD CONSTRAINT CK_conversations_status CHECK (status IN ('active', 'archived'))");

        Schema::create('messages', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->uuid('tenant_id');
            $table->uuid('conversation_id');
            $table->string('role', 20);
            $table->longText('content');
            $table->string('model_alias', 100)->nullable();
            $table->string('status', 20)->default('completed');
            $table->boolean('abstained')->default(false);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 18, 6)->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('feedback', 20)->nullable();
            $table->string('feedback_note', 500)->nullable();
            $table->longText('metadata_json')->nullable();
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->index(['tenant_id', 'conversation_id', 'created_at'], 'IX_messages_tenant_conv');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('conversation_id')->references('id')->on('conversations');
        });

        DB::statement("ALTER TABLE dbo.messages ADD CONSTRAINT CK_messages_role CHECK (role IN ('user', 'assistant', 'system'))");
        DB::statement("ALTER TABLE dbo.messages ADD CONSTRAINT CK_messages_status CHECK (status IN ('streaming', 'completed', 'abstained', 'failed'))");
        DB::statement("ALTER TABLE dbo.messages ADD CONSTRAINT CK_messages_feedback CHECK (feedback IS NULL OR feedback IN ('positive', 'negative'))");

        Schema::create('message_citations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->uuid('tenant_id');
            $table->uuid('message_id');
            $table->uuid('document_id');
            $table->uuid('chunk_id');
            $table->unsignedSmallInteger('citation_index');
            $table->string('document_name', 255);
            $table->unsignedInteger('page_number')->nullable();
            $table->string('section_heading', 255)->nullable();
            $table->string('excerpt', 600);
            $table->decimal('similarity_score', 8, 6)->default(0);
            $table->boolean('verified')->default(true);
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->index(['tenant_id', 'message_id', 'citation_index'], 'IX_message_citations_msg');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('message_id')->references('id')->on('messages');
            $table->foreign('document_id')->references('id')->on('documents');
            $table->foreign('chunk_id')->references('id')->on('document_chunks');
        });

        Schema::create('ai_usage_records', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->uuid('tenant_id');
            $table->uuid('user_id')->nullable();
            $table->uuid('ai_model_id')->nullable();
            $table->string('provider_key', 40);
            $table->string('model_alias', 100);
            $table->string('operation_type', 40);
            $table->uuid('conversation_id')->nullable();
            $table->uuid('message_id')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->decimal('cost_usd', 18, 6)->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('status', 20)->default('completed');
            $table->string('request_id', 64)->nullable();
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->index(['tenant_id', 'created_at'], 'IX_ai_usage_tenant_created');
            $table->index(['tenant_id', 'model_alias', 'created_at'], 'IX_ai_usage_tenant_model');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('ai_model_id')->references('id')->on('ai_models');
        });

        DB::statement("ALTER TABLE dbo.ai_usage_records ADD CONSTRAINT CK_ai_usage_status CHECK (status IN ('completed', 'failed', 'blocked_quota', 'abstained'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_records');
        Schema::dropIfExists('message_citations');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('ai_providers');
    }
};
