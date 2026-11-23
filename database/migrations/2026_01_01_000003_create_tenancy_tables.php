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
        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->string('name', 120);
            $table->string('slug', 80)->unique();
            $table->string('plan', 40)->default('sandbox');
            $table->string('status', 20)->default('active');
            $table->uuid('created_by');
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));
            $table->dateTime('updated_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->foreign('created_by')->references('id')->on('users');
            $table->index('status', 'IX_tenants_status');
        });

        DB::statement("ALTER TABLE dbo.tenants ADD CONSTRAINT CK_tenants_status CHECK (status IN ('active', 'suspended'))");

        Schema::create('tenant_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->uuid('tenant_id')->unique();
            $table->string('default_chat_model', 100)->default('default-chat');
            $table->string('default_embedding_model', 100)->default('default-embedding');
            $table->unsignedSmallInteger('max_upload_mb')->default(20);
            $table->unsignedBigInteger('monthly_token_budget')->default(500000);
            $table->decimal('monthly_cost_budget_usd', 18, 6)->default(25.000000);
            $table->text('allowed_file_types_json');
            $table->unsignedSmallInteger('retention_days')->default(365);
            $table->boolean('require_approval_for_external_actions')->default(true);
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));
            $table->dateTime('updated_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        Schema::create('tenant_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->string('status', 20)->default('active');
            $table->uuid('invited_by')->nullable();
            $table->dateTime('joined_at', 3)->nullable();
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));
            $table->dateTime('updated_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->unique(['tenant_id', 'user_id'], 'UQ_tenant_memberships_tenant_user');
            $table->index(['user_id', 'status'], 'IX_tenant_memberships_user_status');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('invited_by')->references('id')->on('users');
        });

        DB::statement("ALTER TABLE dbo.tenant_memberships ADD CONSTRAINT CK_tenant_memberships_status CHECK (status IN ('active', 'invited', 'suspended'))");

        Schema::create('user_roles', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('NEWSEQUENTIALID()'));
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->uuid('role_id');
            $table->uuid('assigned_by')->nullable();
            $table->dateTime('created_at', 3)->default(DB::raw('SYSUTCDATETIME()'));

            $table->unique(['tenant_id', 'user_id', 'role_id'], 'UQ_user_roles_tenant_user_role');
            $table->index(['tenant_id', 'user_id'], 'IX_user_roles_tenant_user');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('role_id')->references('id')->on('roles');
            $table->foreign('assigned_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('tenant_memberships');
        Schema::dropIfExists('tenant_settings');
        Schema::dropIfExists('tenants');
    }
};
