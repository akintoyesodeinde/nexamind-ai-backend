<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Services;

use App\Domain\Agents\Models\AgentRun;
use App\Domain\AI\Models\AiUsageRecord;
use App\Domain\Analytics\Models\EvaluationRun;
use App\Domain\Conversations\Models\Message;
use App\Domain\Conversations\Models\MessageCitation;
use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Models\KnowledgeBase;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantSetting;
use App\Domain\Workflows\Models\ApprovalRequest;
use App\Domain\Workflows\Models\WorkflowRun;

final class AnalyticsService
{
    /**
     * @return array<string, mixed>
     */
    public function overview(Tenant $tenant, int $days = 30): array
    {
        $days = min(max($days, 1), 365);
        $since = now('UTC')->subDays($days);
        $monthStart = now('UTC')->startOfMonth();

        /** @var TenantSetting|null $settings */
        $settings = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->first();

        $monthlyTokenBudget = $settings !== null ? (int) $settings->monthly_token_budget : 500000;
        $monthlyCostBudget = $settings !== null ? (string) $settings->monthly_cost_budget_usd : '25.000000';

        $monthlyRecords = AiUsageRecord::query()
            ->where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $monthStart)
            ->where('status', '!=', 'blocked_quota')
            ->get();

        $monthlyTokensUsed = 0;
        $monthlyCostUsd = '0.000000';
        foreach ($monthlyRecords as $rec) {
            $monthlyTokensUsed += (int) $rec->total_tokens;
            $monthlyCostUsd = bcadd($monthlyCostUsd, number_format((float) $rec->cost_usd, 6, '.', ''), 6);
        }

        $windowRecords = AiUsageRecord::query()
            ->where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $since)
            ->get();

        $totalRequests = $windowRecords->count();
        $totalLatency = 0;
        $windowTokens = 0;
        $windowCost = '0.000000';
        foreach ($windowRecords as $rec) {
            $totalLatency += (int) $rec->latency_ms;
            $windowTokens += (int) $rec->total_tokens;
            $windowCost = bcadd($windowCost, number_format((float) $rec->cost_usd, 6, '.', ''), 6);
        }
        $avgLatencyMs = $totalRequests > 0 ? (int) round($totalLatency / $totalRequests) : 0;

        $assistantMessages = Message::query()
            ->where('tenant_id', $tenant->id)
            ->where('role', 'assistant')
            ->where('created_at', '>=', $since)
            ->get();

        $assistantTotal = $assistantMessages->count();
        $abstainedCount = $assistantMessages->where('abstained', true)->count();
        $positiveFeedback = $assistantMessages->where('feedback', 'positive')->count();
        $negativeFeedback = $assistantMessages->where('feedback', 'negative')->count();
        $abstentionRate = $assistantTotal > 0 ? round(($abstainedCount / $assistantTotal) * 100, 2) : 0.0;

        $verifiedCitationsCount = MessageCitation::query()
            ->where('tenant_id', $tenant->id)
            ->where('verified', true)
            ->where('created_at', '>=', $since)
            ->count();

        $agentRunsTotal = AgentRun::query()->where('tenant_id', $tenant->id)->count();
        $workflowRunsTotal = WorkflowRun::query()->where('tenant_id', $tenant->id)->count();
        $workflowsCompleted = WorkflowRun::query()->where('tenant_id', $tenant->id)->where('status', 'completed')->count();
        $pendingApprovals = ApprovalRequest::query()->where('tenant_id', $tenant->id)->where('status', 'pending')->count();
        $approvedApprovals = ApprovalRequest::query()->where('tenant_id', $tenant->id)->where('status', 'approved')->count();
        $rejectedApprovals = ApprovalRequest::query()->where('tenant_id', $tenant->id)->where('status', 'rejected')->count();

        /** @var EvaluationRun|null $latestEval */
        $latestEval = EvaluationRun::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'completed')
            ->orderByDesc('created_at')
            ->first();

        $tokenUtilPct = $monthlyTokenBudget > 0 ? round(($monthlyTokensUsed / $monthlyTokenBudget) * 100, 2) : 0.0;
        $costBudgetFloat = (float) $monthlyCostBudget;
        $costUtilPct = $costBudgetFloat > 0.0 ? round(((float) $monthlyCostUsd / $costBudgetFloat) * 100, 2) : 0.0;

        return [
            'window_days' => $days,
            'budget' => [
                'monthly_tokens_used' => $monthlyTokensUsed,
                'monthly_token_budget' => $monthlyTokenBudget,
                'token_utilization_pct' => min($tokenUtilPct, 100.0),
                'monthly_cost_usd' => $monthlyCostUsd,
                'monthly_cost_budget_usd' => number_format($costBudgetFloat, 6, '.', ''),
                'cost_utilization_pct' => min($costUtilPct, 100.0),
            ],
            'ai_usage' => [
                'total_requests' => $totalRequests,
                'total_tokens' => $windowTokens,
                'total_cost_usd' => $windowCost,
                'avg_latency_ms' => $avgLatencyMs,
                'verified_citations_count' => $verifiedCitationsCount,
                'abstention_rate_pct' => $abstentionRate,
                'positive_feedback_count' => $positiveFeedback,
                'negative_feedback_count' => $negativeFeedback,
            ],
            'knowledge' => [
                'knowledge_bases_count' => KnowledgeBase::query()->where('tenant_id', $tenant->id)->where('status', 'active')->count(),
                'documents_ready_count' => Document::query()->where('tenant_id', $tenant->id)->where('status', 'ready')->count(),
                'documents_total_count' => Document::query()->where('tenant_id', $tenant->id)->where('status', '!=', 'deleted')->count(),
            ],
            'automation' => [
                'agent_runs_total' => $agentRunsTotal,
                'workflow_runs_total' => $workflowRunsTotal,
                'workflows_completed' => $workflowsCompleted,
                'pending_approvals_count' => $pendingApprovals,
                'approvals_approved' => $approvedApprovals,
                'approvals_rejected' => $rejectedApprovals,
            ],
            'evaluation' => [
                'latest_run_id' => $latestEval?->id,
                'citation_correctness_pct' => $latestEval !== null ? (float) $latestEval->citation_correctness_pct : null,
                'groundedness_score_pct' => $latestEval !== null ? (float) $latestEval->groundedness_score_pct : null,
                'abstention_accuracy_pct' => $latestEval !== null ? (float) $latestEval->abstention_accuracy_pct : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function usageBreakdown(Tenant $tenant, int $days = 30): array
    {
        $days = min(max($days, 1), 365);
        $since = now('UTC')->subDays($days);

        $records = AiUsageRecord::query()
            ->where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $since)
            ->orderBy('created_at')
            ->get();

        /** @var array<string, array{model_alias: string, provider_key: string, requests: int, input_tokens: int, output_tokens: int, total_tokens: int, cost_usd: string, latency_sum: int}> $byModel */
        $byModel = [];
        /** @var array<string, array{operation_type: string, requests: int, total_tokens: int, cost_usd: string}> $byOp */
        $byOp = [];
        /** @var array<string, array{date: string, requests: int, total_tokens: int, cost_usd: string}> $daily */
        $daily = [];
        /** @var array<string, array{user_id: string, requests: int, total_tokens: int, cost_usd: string}> $byUser */
        $byUser = [];

        foreach ($records as $rec) {
            $costStr = number_format((float) $rec->cost_usd, 6, '.', '');

            $modelKey = $rec->provider_key.':'.$rec->model_alias;
            if (! isset($byModel[$modelKey])) {
                $byModel[$modelKey] = [
                    'model_alias' => $rec->model_alias,
                    'provider_key' => $rec->provider_key,
                    'requests' => 0,
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'total_tokens' => 0,
                    'cost_usd' => '0.000000',
                    'latency_sum' => 0,
                ];
            }
            $byModel[$modelKey]['requests']++;
            $byModel[$modelKey]['input_tokens'] += (int) $rec->input_tokens;
            $byModel[$modelKey]['output_tokens'] += (int) $rec->output_tokens;
            $byModel[$modelKey]['total_tokens'] += (int) $rec->total_tokens;
            $byModel[$modelKey]['cost_usd'] = bcadd($byModel[$modelKey]['cost_usd'], $costStr, 6);
            $byModel[$modelKey]['latency_sum'] += (int) $rec->latency_ms;

            $opKey = $rec->operation_type;
            if (! isset($byOp[$opKey])) {
                $byOp[$opKey] = [
                    'operation_type' => $opKey,
                    'requests' => 0,
                    'total_tokens' => 0,
                    'cost_usd' => '0.000000',
                ];
            }
            $byOp[$opKey]['requests']++;
            $byOp[$opKey]['total_tokens'] += (int) $rec->total_tokens;
            $byOp[$opKey]['cost_usd'] = bcadd($byOp[$opKey]['cost_usd'], $costStr, 6);

            $dateKey = $rec->created_at->setTimezone('UTC')->format('Y-m-d');
            if (! isset($daily[$dateKey])) {
                $daily[$dateKey] = [
                    'date' => $dateKey,
                    'requests' => 0,
                    'total_tokens' => 0,
                    'cost_usd' => '0.000000',
                ];
            }
            $daily[$dateKey]['requests']++;
            $daily[$dateKey]['total_tokens'] += (int) $rec->total_tokens;
            $daily[$dateKey]['cost_usd'] = bcadd($daily[$dateKey]['cost_usd'], $costStr, 6);

            if ($rec->user_id !== null) {
                $uKey = strtolower($rec->user_id);
                if (! isset($byUser[$uKey])) {
                    $byUser[$uKey] = [
                        'user_id' => $rec->user_id,
                        'requests' => 0,
                        'total_tokens' => 0,
                        'cost_usd' => '0.000000',
                    ];
                }
                $byUser[$uKey]['requests']++;
                $byUser[$uKey]['total_tokens'] += (int) $rec->total_tokens;
                $byUser[$uKey]['cost_usd'] = bcadd($byUser[$uKey]['cost_usd'], $costStr, 6);
            }
        }

        $modelRows = array_values(array_map(static fn (array $m): array => [
            'model_alias' => $m['model_alias'],
            'provider_key' => $m['provider_key'],
            'requests' => $m['requests'],
            'input_tokens' => $m['input_tokens'],
            'output_tokens' => $m['output_tokens'],
            'total_tokens' => $m['total_tokens'],
            'cost_usd' => $m['cost_usd'],
            'avg_latency_ms' => $m['requests'] > 0 ? (int) round($m['latency_sum'] / $m['requests']) : 0,
        ], $byModel));

        $userIds = array_values(array_map(static fn (array $u): string => $u['user_id'], $byUser));
        $usersMap = [];
        if ($userIds !== []) {
            foreach (User::query()->whereIn('id', $userIds)->get() as $u) {
                $usersMap[strtolower($u->id)] = $u;
            }
        }

        $topConsumers = array_values(array_map(static function (array $u) use ($usersMap): array {
            $user = $usersMap[strtolower($u['user_id'])] ?? null;

            return [
                'user_id' => $u['user_id'],
                'name' => $user !== null ? $user->name : 'Unknown User',
                'email' => $user !== null ? $user->email : '',
                'requests' => $u['requests'],
                'total_tokens' => $u['total_tokens'],
                'cost_usd' => $u['cost_usd'],
            ];
        }, $byUser));

        usort($topConsumers, static fn (array $a, array $b): int => $b['total_tokens'] <=> $a['total_tokens']);

        return [
            'window_days' => $days,
            'by_model' => $modelRows,
            'by_operation' => array_values($byOp),
            'daily_trend' => array_values($daily),
            'top_consumers' => array_slice($topConsumers, 0, 10),
        ];
    }
}
