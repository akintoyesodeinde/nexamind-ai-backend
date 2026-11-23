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
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->uuid('tenant_id')->nullable();
            $table->uuid('actor_user_id')->nullable();
            $table->string('action', 120);
            $table->string('resource_type', 80);
            $table->string('resource_id', 64)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->longText('metadata_json')->nullable();
            $table->char('previous_hash', 64);
            $table->char('record_hash', 64);
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->index(['tenant_id', 'created_at'], 'IX_audit_logs_tenant_created');
            $table->index(['tenant_id', 'action'], 'IX_audit_logs_tenant_action');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('actor_user_id')->references('id')->on('users');
        });

        Schema::create('security_events', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->uuid('tenant_id')->nullable();
            $table->uuid('actor_user_id')->nullable();
            $table->string('event_type', 80);
            $table->string('severity', 20)->default('medium');
            $table->string('request_id', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('summary', 255);
            $table->longText('details_json')->nullable();
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->index(['tenant_id', 'severity', 'created_at'], 'IX_security_events_tenant_sev');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('actor_user_id')->references('id')->on('users');
        });

        DB::statement("ALTER TABLE dbo.security_events ADD CONSTRAINT CK_security_events_severity CHECK (severity IN ('low', 'medium', 'high', 'critical'))");

        Schema::create('api_idempotency_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->string('idempotency_key', 128);
            $table->string('request_method', 10);
            $table->string('request_path', 255);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status');
            $table->longText('response_body_json');
            $table->dateTime('expires_at', 3);
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->unique(
                ['tenant_id', 'idempotency_key', 'request_method', 'request_path'],
                'UQ_api_idempotency_tenant_key_route',
            );
            $table->index('expires_at', 'IX_api_idempotency_expires');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('user_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('audit_logs');
    }
};
