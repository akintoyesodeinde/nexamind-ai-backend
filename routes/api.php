<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AgentController;
use App\Http\Controllers\Api\V1\AiModelController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\ApprovalController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\DocumentIntelligenceController;
use App\Http\Controllers\Api\V1\EvaluationController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\KnowledgeBaseController;
use App\Http\Controllers\Api\V1\RetrievalController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\TenantMemberController;
use App\Http\Controllers\Api\V1\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Public liveness and readiness probes
    Route::get('/health/live', [HealthController::class, 'live']);
    Route::get('/health/ready', [HealthController::class, 'ready']);

    // Public auth endpoints (rate-limited)
    Route::middleware('throttle:auth')->group(function (): void {
        Route::post('/auth/register', [AuthController::class, 'register']);
        Route::post('/auth/login', [AuthController::class, 'login']);
    });

    // Authenticated user-level endpoints (pre-tenant & cross-org)
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::patch('/me/profile', [AuthController::class, 'updateProfile']);
        Route::post('/tenants', [TenantController::class, 'store']);
        Route::get('/roles', [TenantController::class, 'roles']);

        // Strictly tenant-scoped endpoints (requires valid active membership via X-Tenant-ID)
        Route::middleware(['tenant.context', 'idempotency'])->group(function (): void {
            Route::get('/tenants/current', [TenantController::class, 'current']);

            Route::patch('/tenants/current/settings', [TenantController::class, 'updateSettings'])
                ->middleware('permission:tenant.settings.manage');

            Route::get('/tenants/current/members', [TenantMemberController::class, 'index'])
                ->middleware('permission:users.manage');
            Route::post('/tenants/current/members', [TenantMemberController::class, 'store'])
                ->middleware('permission:users.manage');
            Route::patch('/tenants/current/members/{membershipId}', [TenantMemberController::class, 'update'])
                ->middleware('permission:users.manage');
            Route::delete('/tenants/current/members/{membershipId}', [TenantMemberController::class, 'destroy'])
                ->middleware('permission:users.manage');

            // Phase 2: Knowledge Bases & ACLs
            Route::get('/knowledge-bases', [KnowledgeBaseController::class, 'index']);
            Route::post('/knowledge-bases', [KnowledgeBaseController::class, 'store'])
                ->middleware('permission:knowledge.create');
            Route::get('/knowledge-bases/{id}', [KnowledgeBaseController::class, 'show']);
            Route::patch('/knowledge-bases/{id}', [KnowledgeBaseController::class, 'update'])
                ->middleware('permission:knowledge.create');
            Route::get('/knowledge-bases/{id}/permissions', [KnowledgeBaseController::class, 'permissions']);
            Route::put('/knowledge-bases/{id}/permissions', [KnowledgeBaseController::class, 'syncPermissions']);

            // Phase 2: Documents, Versioning, Signed Downloads & ACLs
            Route::get('/documents', [DocumentController::class, 'index']);
            Route::post('/documents', [DocumentController::class, 'store'])
                ->middleware('permission:documents.upload');
            Route::post('/documents/compare', [DocumentIntelligenceController::class, 'compare'])
                ->middleware('permission:chat.ask');
            Route::get('/documents/{id}', [DocumentController::class, 'show']);
            Route::get('/documents/{id}/status', [DocumentController::class, 'status']);
            Route::get('/documents/{id}/versions', [DocumentController::class, 'versions']);
            Route::get('/documents/{id}/chunks', [DocumentController::class, 'chunks']);
            Route::get('/documents/{id}/download-url', [DocumentController::class, 'downloadUrl']);
            Route::get('/documents/{id}/permissions', [DocumentController::class, 'permissions']);
            Route::put('/documents/{id}/permissions', [DocumentController::class, 'syncPermissions']);
            Route::post('/documents/{id}/analyze', [DocumentIntelligenceController::class, 'analyze'])
                ->middleware('permission:chat.ask');
            Route::delete('/documents/{id}', [DocumentController::class, 'destroy']);

            // Phase 2: Pre-Retrieval ACL-Enforced Vector Search
            Route::post('/retrieval/search', [RetrievalController::class, 'search'])
                ->middleware('permission:chat.ask');

            // Phase 3: AI Models, Grounded RAG Conversations & SSE Streaming
            Route::get('/ai/models', [AiModelController::class, 'index']);
            Route::get('/conversations', [ConversationController::class, 'index'])
                ->middleware('permission:chat.ask');
            Route::post('/conversations', [ConversationController::class, 'store'])
                ->middleware('permission:chat.ask');
            Route::get('/conversations/{id}', [ConversationController::class, 'show'])
                ->middleware('permission:chat.ask');
            Route::patch('/conversations/{id}', [ConversationController::class, 'update'])
                ->middleware('permission:chat.ask');
            Route::delete('/conversations/{id}', [ConversationController::class, 'destroy'])
                ->middleware('permission:chat.ask');
            Route::post('/conversations/{id}/messages', [ConversationController::class, 'storeMessage'])
                ->middleware('permission:chat.ask');
            Route::get('/conversations/{id}/stream', [ConversationController::class, 'stream'])
                ->middleware('permission:chat.ask');
            Route::post('/messages/{messageId}/feedback', [ConversationController::class, 'feedback'])
                ->middleware('permission:chat.ask');

            // Phase 4: Controlled Enterprise AI Agents & Tool Registry
            Route::get('/agents/tools', [AgentController::class, 'toolsCatalog']);
            Route::get('/agents', [AgentController::class, 'index']);
            Route::post('/agents', [AgentController::class, 'store'])
                ->middleware('permission:agents.manage');
            Route::get('/agents/runs', [AgentController::class, 'listRuns']);
            Route::get('/agents/runs/{runId}', [AgentController::class, 'showRun']);
            Route::post('/agents/runs/{runId}/cancel', [AgentController::class, 'cancelRun'])
                ->middleware('permission:agents.manage');
            Route::get('/agents/{id}', [AgentController::class, 'show']);
            Route::patch('/agents/{id}', [AgentController::class, 'update'])
                ->middleware('permission:agents.manage');
            Route::post('/agents/{id}/runs', [AgentController::class, 'run'])
                ->middleware('permission:agents.manage');

            // Phase 4: Workflows & Human-in-the-Loop Approval Inbox
            Route::get('/workflows', [WorkflowController::class, 'index']);
            Route::post('/workflows', [WorkflowController::class, 'store'])
                ->middleware('permission:workflows.manage');
            Route::post('/workflows/templates/compliance-report', [WorkflowController::class, 'seedComplianceTemplate'])
                ->middleware('permission:workflows.manage');
            Route::get('/workflows/runs', [WorkflowController::class, 'listRuns']);
            Route::get('/workflows/runs/{runId}', [WorkflowController::class, 'showRun']);
            Route::post('/workflows/{id}/runs', [WorkflowController::class, 'launchRun'])
                ->middleware('permission:workflows.manage');

            Route::get('/approvals', [ApprovalController::class, 'index'])
                ->middleware('permission:approvals.act');
            Route::get('/approvals/{id}', [ApprovalController::class, 'show'])
                ->middleware('permission:approvals.act');
            Route::post('/approvals/{id}/approve', [ApprovalController::class, 'approve'])
                ->middleware('permission:approvals.act');
            Route::post('/approvals/{id}/reject', [ApprovalController::class, 'reject'])
                ->middleware('permission:approvals.act');

            // Phase 4: AI Usage Analytics & Evaluation Harness
            Route::get('/analytics/overview', [AnalyticsController::class, 'overview'])
                ->middleware('permission:analytics.view');
            Route::get('/analytics/usage', [AnalyticsController::class, 'usage'])
                ->middleware('permission:analytics.view');

            Route::get('/evaluations/datasets', [EvaluationController::class, 'listDatasets'])
                ->middleware('permission:analytics.view');
            Route::post('/evaluations/datasets', [EvaluationController::class, 'storeDataset'])
                ->middleware('permission:analytics.view');
            Route::post('/evaluations/datasets/seed-benchmark', [EvaluationController::class, 'seedBenchmark'])
                ->middleware('permission:analytics.view');
            Route::get('/evaluations/runs', [EvaluationController::class, 'listRuns'])
                ->middleware('permission:analytics.view');
            Route::post('/evaluations/runs', [EvaluationController::class, 'runEvaluation'])
                ->middleware('permission:analytics.view');
            Route::get('/evaluations/runs/{runId}', [EvaluationController::class, 'showRun'])
                ->middleware('permission:analytics.view');

            // Phase 1 & Phase 4: Tamper-Evident Security Audit & Chain Verification
            Route::get('/audit-logs', [AuditLogController::class, 'index'])
                ->middleware('permission:audit.view');
            Route::get('/audit-logs/verify-chain', [AuditLogController::class, 'verifyChain'])
                ->middleware('permission:audit.view');
            Route::get('/security-events', [AuditLogController::class, 'securityEvents'])
                ->middleware('permission:audit.view');
        });
    });
});
