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
        Schema::create('agents', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->uuid('tenant_id');
            $table->uuid('created_by')->nullable();
            $table->string('name', 160);
            $table->string('slug', 120);
            $table->string('description', 500)->nullable();
            $table->longText('system_prompt');
            $table->string('model_alias', 100)->default('default-chat');
            $table->longText('allowed_kb_ids_json')->nullable();
            $table->unsignedTinyInteger('max_steps')->default(8);
            $table->unsignedInteger('max_tokens_per_run')->default(12000);
            $table->boolean('require_approval')->default(true);
            $table->string('status', 20)->default('active');
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));
            $table->dateTime('updated_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->unique(['tenant_id', 'slug'], 'UQ_agents_tenant_slug');
            $table->index(['tenant_id', 'status'], 'IX_agents_tenant_status');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('created_by')->references('id')->on('users');
        });

        DB::statement("ALTER TABLE dbo.agents ADD CONSTRAINT CK_agents_status CHECK (status IN ('active', 'disabled'))");
        DB::statement('ALTER TABLE dbo.agents ADD CONSTRAINT CK_agents_max_steps CHECK (max_steps BETWEEN 1 AND 12)');

        Schema::create('agent_tools', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->uuid('tenant_id');
            $table->uuid('agent_id');
            $table->string('tool_name', 80);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('requires_approval')->default(false);
            $table->longText('config_json')->nullable();
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));
            $table->dateTime('updated_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->unique(['agent_id', 'tool_name'], 'UQ_agent_tools_agent_tool');
            $table->index(['tenant_id', 'agent_id'], 'IX_agent_tools_tenant_agent');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('agent_id')->references('id')->on('agents');
        });

        Schema::create('agent_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->uuid('tenant_id');
            $table->uuid('agent_id');
            $table->uuid('triggered_by')->nullable();
            $table->longText('goal');
            $table->longText('input_json')->nullable();
            $table->longText('output_json')->nullable();
            $table->string('status', 30)->default('queued');
            $table->unsignedSmallInteger('step_count')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->decimal('cost_usd', 18, 6)->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('error_message', 1000)->nullable();
            $table->dateTime('started_at', 3)->nullable();
            $table->dateTime('completed_at', 3)->nullable();
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));
            $table->dateTime('updated_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->index(['tenant_id', 'status', 'created_at'], 'IX_agent_runs_tenant_status');
            $table->index(['tenant_id', 'agent_id', 'created_at'], 'IX_agent_runs_tenant_agent');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('agent_id')->references('id')->on('agents');
            $table->foreign('triggered_by')->references('id')->on('users');
        });

        DB::statement("ALTER TABLE dbo.agent_runs ADD CONSTRAINT CK_agent_runs_status CHECK (status IN ('queued', 'running', 'awaiting_approval', 'completed', 'failed', 'cancelled'))");

        Schema::create('agent_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->uuid('tenant_id');
            $table->uuid('agent_run_id');
            $table->unsignedSmallInteger('step_number');
            $table->string('step_type', 40);
            $table->string('tool_name', 80)->nullable();
            $table->longText('input_json')->nullable();
            $table->longText('output_json')->nullable();
            $table->string('status', 30)->default('completed');
            $table->unsignedInteger('tokens_used')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('error_message', 1000)->nullable();
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->index(['tenant_id', 'agent_run_id', 'step_number'], 'IX_agent_steps_run_order');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('agent_run_id')->references('id')->on('agent_runs');
        });

        DB::statement("ALTER TABLE dbo.agent_steps ADD CONSTRAINT CK_agent_steps_status CHECK (status IN ('completed', 'blocked', 'failed', 'awaiting_approval'))");

        Schema::create('workflow_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->uuid('tenant_id');
            $table->uuid('agent_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->string('name', 160);
            $table->string('slug', 120);
            $table->string('description', 500)->nullable();
            $table->string('workflow_type', 60)->default('compliance_report');
            $table->longText('steps_definition_json');
            $table->string('approver_role', 40)->default('manager');
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));
            $table->dateTime('updated_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->unique(['tenant_id', 'slug'], 'UQ_workflow_defs_tenant_slug');
            $table->index(['tenant_id', 'is_active'], 'IX_workflow_defs_tenant_active');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('agent_id')->references('id')->on('agents');
            $table->foreign('created_by')->references('id')->on('users');
        });

        Schema::create('workflow_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->uuid('tenant_id');
            $table->uuid('workflow_definition_id');
            $table->uuid('agent_run_id')->nullable();
            $table->uuid('triggered_by')->nullable();
            $table->string('status', 30)->default('queued');
            $table->longText('input_json')->nullable();
            $table->longText('output_json')->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->dateTime('started_at', 3)->nullable();
            $table->dateTime('completed_at', 3)->nullable();
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));
            $table->dateTime('updated_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->index(['tenant_id', 'status', 'created_at'], 'IX_workflow_runs_tenant_status');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('workflow_definition_id')->references('id')->on('workflow_definitions');
            $table->foreign('agent_run_id')->references('id')->on('agent_runs');
            $table->foreign('triggered_by')->references('id')->on('users');
        });

        DB::statement("ALTER TABLE dbo.workflow_runs ADD CONSTRAINT CK_workflow_runs_status CHECK (status IN ('queued', 'running', 'awaiting_approval', 'completed', 'failed', 'cancelled'))");

        Schema::create('workflow_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->uuid('tenant_id');
            $table->uuid('workflow_run_id');
            $table->unsignedSmallInteger('step_order');
            $table->string('step_key', 80);
            $table->string('name', 160);
            $table->string('status', 30)->default('pending');
            $table->longText('payload_json')->nullable();
            $table->dateTime('started_at', 3)->nullable();
            $table->dateTime('completed_at', 3)->nullable();
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->index(['tenant_id', 'workflow_run_id', 'step_order'], 'IX_workflow_steps_run_order');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('workflow_run_id')->references('id')->on('workflow_runs');
        });

        DB::statement("ALTER TABLE dbo.workflow_steps ADD CONSTRAINT CK_workflow_steps_status CHECK (status IN ('pending', 'running', 'awaiting_approval', 'completed', 'rejected', 'failed', 'skipped'))");

        Schema::create('approval_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->uuid('tenant_id');
            $table->uuid('workflow_run_id')->nullable();
            $table->uuid('agent_run_id')->nullable();
            $table->uuid('requested_by')->nullable();
            $table->uuid('assigned_approver_id')->nullable();
            $table->string('required_role', 40)->default('manager');
            $table->string('title', 200);
            $table->string('action_type', 80)->default('publish_approved_report');
            $table->longText('summary');
            $table->longText('payload_json');
            $table->string('status', 20)->default('pending');
            $table->uuid('decided_by')->nullable();
            $table->string('decision_note', 1000)->nullable();
            $table->dateTime('decided_at', 3)->nullable();
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));
            $table->dateTime('updated_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->index(['tenant_id', 'status', 'created_at'], 'IX_approval_requests_tenant_status');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('workflow_run_id')->references('id')->on('workflow_runs');
            $table->foreign('agent_run_id')->references('id')->on('agent_runs');
            $table->foreign('requested_by')->references('id')->on('users');
            $table->foreign('assigned_approver_id')->references('id')->on('users');
            $table->foreign('decided_by')->references('id')->on('users');
        });

        DB::statement("ALTER TABLE dbo.approval_requests ADD CONSTRAINT CK_approval_requests_status CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled'))");

        Schema::table('outbox_events', function (Blueprint $table): void {
            $table->string('dedup_key', 160)->nullable();
        });

        DB::statement('CREATE UNIQUE INDEX UQ_outbox_events_tenant_dedup ON dbo.outbox_events (tenant_id, dedup_key) WHERE dedup_key IS NOT NULL');

        Schema::create('evaluation_datasets', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->uuid('tenant_id');
            $table->uuid('created_by')->nullable();
            $table->string('name', 160);
            $table->string('description', 500)->nullable();
            $table->longText('knowledge_base_ids_json')->nullable();
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));
            $table->dateTime('updated_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->index(['tenant_id', 'created_at'], 'IX_eval_datasets_tenant');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('created_by')->references('id')->on('users');
        });

        Schema::create('evaluation_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->uuid('tenant_id');
            $table->uuid('evaluation_dataset_id');
            $table->string('category', 60)->default('grounded_qa');
            $table->longText('question');
            $table->longText('expected_keywords_json')->nullable();
            $table->boolean('expect_abstention')->default(false);
            $table->boolean('require_citations')->default(true);
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->index(['tenant_id', 'evaluation_dataset_id'], 'IX_eval_cases_dataset');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('evaluation_dataset_id')->references('id')->on('evaluation_datasets');
        });

        Schema::create('evaluation_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->uuid('tenant_id');
            $table->uuid('evaluation_dataset_id');
            $table->uuid('triggered_by')->nullable();
            $table->string('model_alias', 100)->default('default-chat');
            $table->string('status', 20)->default('completed');
            $table->unsignedInteger('total_cases')->default(0);
            $table->unsignedInteger('passed_cases')->default(0);
            $table->decimal('citation_correctness_pct', 6, 2)->default(0);
            $table->decimal('groundedness_score_pct', 6, 2)->default(0);
            $table->decimal('abstention_accuracy_pct', 6, 2)->default(0);
            $table->unsignedInteger('avg_latency_ms')->default(0);
            $table->decimal('total_cost_usd', 18, 6)->default(0);
            $table->longText('case_results_json')->nullable();
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));
            $table->dateTime('completed_at', 3)->nullable();

            $table->index(['tenant_id', 'created_at'], 'IX_eval_runs_tenant');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('evaluation_dataset_id')->references('id')->on('evaluation_datasets');
            $table->foreign('triggered_by')->references('id')->on('users');
        });

        DB::statement("ALTER TABLE dbo.evaluation_runs ADD CONSTRAINT CK_evaluation_runs_status CHECK (status IN ('running', 'completed', 'failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_runs');
        Schema::dropIfExists('evaluation_cases');
        Schema::dropIfExists('evaluation_datasets');
        DB::statement('DROP INDEX IF EXISTS UQ_outbox_events_tenant_dedup ON dbo.outbox_events');
        Schema::table('outbox_events', function (Blueprint $table): void {
            $table->dropColumn('dedup_key');
        });
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('workflow_steps');
        Schema::dropIfExists('workflow_runs');
        Schema::dropIfExists('workflow_definitions');
        Schema::dropIfExists('agent_steps');
        Schema::dropIfExists('agent_runs');
        Schema::dropIfExists('agent_tools');
        Schema::dropIfExists('agents');
    }
};
