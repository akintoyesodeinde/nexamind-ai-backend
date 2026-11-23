<?php

declare(strict_types=1);

namespace App\Domain\AI\Services;

use App\Domain\AI\Contracts\LlmProviderInterface;
use App\Domain\AI\Exceptions\QuotaExceededException;
use App\Domain\AI\Models\AiModel;
use App\Domain\AI\Models\AiUsageRecord;
use App\Domain\Audit\Contracts\AuditLoggerInterface;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantSetting;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ModelGatewayService
{
    public function __construct(
        private readonly LlmProviderInterface $llmProvider,
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listAvailableModels(Tenant $tenant, ?string $capability = null): array
    {
        $query = AiModel::query()
            ->with('provider')
            ->where('is_active', true)
            ->whereHas('provider', function ($q) use ($tenant): void {
                $q->where('is_active', true)
                    ->where(function ($sub) use ($tenant): void {
                        $sub->whereNull('tenant_id')->orWhere('tenant_id', $tenant->id);
                    });
            })
            ->orderBy('alias');

        if ($capability !== null) {
            $query->where('capability', $capability);
        }

        return $query->get()
            ->map(static fn (AiModel $m): array => $m->toApiArray())
            ->values()
            ->all();
    }

    public function resolveModel(Tenant $tenant, string $requestedAlias, string $capability = 'chat'): ?AiModel
    {
        /** @var AiModel|null $model */
        $model = AiModel::query()
            ->with('provider')
            ->where('alias', $requestedAlias)
            ->where('capability', $capability)
            ->where('is_active', true)
            ->whereHas('provider', function ($q) use ($tenant): void {
                $q->where('is_active', true)
                    ->where(function ($sub) use ($tenant): void {
                        $sub->whereNull('tenant_id')->orWhere('tenant_id', $tenant->id);
                    });
            })
            ->first();

        return $model;
    }

    /**
     * Checks per-tenant monthly token and fixed-precision USD budgets before LLM execution.
     */
    public function enforceTenantQuota(Tenant $tenant, ?User $user, string $modelAlias, string $operationType): void
    {
        /** @var TenantSetting|null $settings */
        $settings = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->first();

        if ($settings === null) {
            return;
        }

        $monthStart = now('UTC')->startOfMonth();

        /** @var object{tokens: int|string|null, cost: string|float|null}|null $agg */
        $agg = DB::table('ai_usage_records')
            ->where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $monthStart)
            ->where('status', '!=', 'blocked_quota')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) as tokens, COALESCE(SUM(cost_usd), 0) as cost')
            ->first();

        $usedTokens = (int) ($agg !== null ? ($agg->tokens ?? 0) : 0);
        $usedCost = number_format((float) ($agg !== null ? ($agg->cost ?? 0) : 0), 6, '.', '');
        $maxCost = number_format((float) $settings->monthly_cost_budget_usd, 6, '.', '');

        $tokenExceeded = $usedTokens >= $settings->monthly_token_budget;
        $costExceeded = bccomp($usedCost, $maxCost, 6) >= 0;

        if ($tokenExceeded || $costExceeded) {
            $reqId = request()->attributes->get('request_id');
            AiUsageRecord::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user?->id,
                'provider_key' => $this->llmProvider->providerKey(),
                'model_alias' => $modelAlias,
                'operation_type' => $operationType,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
                'cost_usd' => '0.000000',
                'latency_ms' => 0,
                'status' => 'blocked_quota',
                'request_id' => is_string($reqId) ? $reqId : null,
            ]);

            $this->auditLogger->recordSecurityEvent(
                eventType: 'ai.quota_exceeded',
                severity: 'medium',
                summary: 'AI request blocked because tenant monthly token or cost budget was exceeded',
                details: [
                    'used_tokens' => $usedTokens,
                    'monthly_token_budget' => $settings->monthly_token_budget,
                    'used_cost_usd' => $usedCost,
                    'monthly_cost_budget_usd' => $maxCost,
                ],
                tenantId: $tenant->id,
                actorUserId: $user?->id,
            );

            throw new QuotaExceededException(
                'Monthly AI token or cost budget has been reached for this organization.',
                [
                    'used_tokens' => $usedTokens,
                    'monthly_token_budget' => $settings->monthly_token_budget,
                    'used_cost_usd' => $usedCost,
                    'monthly_cost_budget_usd' => $maxCost,
                ],
            );
        }
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @param  (callable(string): void)|null  $onDelta
     * @return array{
     *     content: string,
     *     input_tokens: int,
     *     output_tokens: int,
     *     total_tokens: int,
     *     cost_usd: string,
     *     latency_ms: int,
     *     model_alias: string,
     *     provider_key: string,
     *     usage_record_id: string
     * }
     */
    public function executeChat(
        Tenant $tenant,
        User $user,
        string $modelAlias,
        string $operationType,
        array $messages,
        array $options = [],
        ?callable $onDelta = null,
        ?string $conversationId = null,
        ?string $messageId = null,
    ): array {
        $this->enforceTenantQuota($tenant, $user, $modelAlias, $operationType);

        $modelRecord = $this->resolveModel($tenant, $modelAlias, 'chat');
        $providerModelName = $modelRecord !== null ? $modelRecord->provider_model_name : $modelAlias;

        $startedAt = microtime(true);
        try {
            $result = $onDelta !== null
                ? $this->llmProvider->stream($providerModelName, $messages, $onDelta, $options)
                : $this->llmProvider->complete($providerModelName, $messages, $options);
        } catch (Throwable $e) {
            $latencyMs = max(1, (int) round((microtime(true) - $startedAt) * 1000));
            $this->recordUsage(
                tenant: $tenant,
                user: $user,
                modelRecord: $modelRecord,
                modelAlias: $modelAlias,
                operationType: $operationType,
                inputTokens: 0,
                outputTokens: 0,
                costUsd: '0.000000',
                latencyMs: $latencyMs,
                status: 'failed',
                conversationId: $conversationId,
                messageId: $messageId,
            );
            throw $e;
        }

        $latencyMs = max(1, (int) round((microtime(true) - $startedAt) * 1000));
        $inputTokens = max(0, $result['input_tokens']);
        $outputTokens = max(0, $result['output_tokens']);
        $costUsd = $this->calculateFixedCostUsd($modelRecord, $inputTokens, $outputTokens);

        $usage = $this->recordUsage(
            tenant: $tenant,
            user: $user,
            modelRecord: $modelRecord,
            modelAlias: $modelAlias,
            operationType: $operationType,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            costUsd: $costUsd,
            latencyMs: $latencyMs,
            status: 'completed',
            conversationId: $conversationId,
            messageId: $messageId,
        );

        return [
            'content' => $result['content'],
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $outputTokens,
            'cost_usd' => $costUsd,
            'latency_ms' => $latencyMs,
            'model_alias' => $modelAlias,
            'provider_key' => $this->llmProvider->providerKey(),
            'usage_record_id' => $usage->id,
        ];
    }

    public function calculateFixedCostUsd(?AiModel $modelRecord, int $inputTokens, int $outputTokens): string
    {
        $inRate = $modelRecord !== null
            ? number_format((float) $modelRecord->input_cost_per_1k_usd, 6, '.', '')
            : '0.000150';
        $outRate = $modelRecord !== null
            ? number_format((float) $modelRecord->output_cost_per_1k_usd, 6, '.', '')
            : '0.000600';

        $inUnits = bcdiv((string) $inputTokens, '1000', 9);
        $outUnits = bcdiv((string) $outputTokens, '1000', 9);

        $inCost = bcmul($inUnits, $inRate, 6);
        $outCost = bcmul($outUnits, $outRate, 6);

        return bcadd($inCost, $outCost, 6);
    }

    public function recordUsage(
        Tenant $tenant,
        User $user,
        ?AiModel $modelRecord,
        string $modelAlias,
        string $operationType,
        int $inputTokens,
        int $outputTokens,
        string $costUsd,
        int $latencyMs,
        string $status,
        ?string $conversationId = null,
        ?string $messageId = null,
    ): AiUsageRecord {
        $reqId = request()->attributes->get('request_id');

        return AiUsageRecord::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'ai_model_id' => $modelRecord?->id,
            'provider_key' => $this->llmProvider->providerKey(),
            'model_alias' => $modelAlias,
            'operation_type' => $operationType,
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $outputTokens,
            'cost_usd' => $costUsd,
            'latency_ms' => $latencyMs,
            'status' => $status,
            'request_id' => is_string($reqId) ? $reqId : null,
        ]);
    }
}
