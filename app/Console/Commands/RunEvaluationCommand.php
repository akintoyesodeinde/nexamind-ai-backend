<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Analytics\Models\EvaluationDataset;
use App\Domain\Analytics\Services\EvaluationService;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use Illuminate\Console\Command;

final class RunEvaluationCommand extends Command
{
    protected $signature = 'nexamind:eval-run
                            {--tenant= : Tenant UUID or slug}
                            {--dataset= : Evaluation Dataset UUID (optional, seeds benchmark if omitted)}
                            {--model= : Model alias override}';

    protected $description = 'Execute a NexaMind AI evaluation dataset and report citation correctness, groundedness, and abstention metrics';

    public function handle(EvaluationService $evaluationService): int
    {
        $tenantOpt = $this->option('tenant');
        $tenantQuery = Tenant::query();
        if (is_string($tenantOpt) && trim($tenantOpt) !== '') {
            $tenantQuery->where('id', trim($tenantOpt))->orWhere('slug', trim($tenantOpt));
        }

        /** @var Tenant|null $tenant */
        $tenant = $tenantQuery->first();
        if ($tenant === null) {
            $this->error('No tenant found to run evaluation.');

            return self::FAILURE;
        }

        /** @var TenantMembership|null $membership */
        $membership = TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->first();

        /** @var User|null $actor */
        $actor = $membership !== null ? User::query()->find($membership->user_id) : User::query()->first();
        if ($actor === null) {
            $this->error('No active user found in tenant to authorize retrieval evaluation.');

            return self::FAILURE;
        }

        $datasetOpt = $this->option('dataset');
        /** @var EvaluationDataset|null $dataset */
        $dataset = (is_string($datasetOpt) && trim($datasetOpt) !== '')
            ? EvaluationDataset::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('id', trim($datasetOpt))->first()
            : $evaluationService->seedCuratedBenchmark($tenant, $actor);

        if ($dataset === null) {
            $this->error('Requested evaluation dataset was not found.');

            return self::FAILURE;
        }

        $modelOpt = $this->option('model');
        $run = $evaluationService->runEvaluation(
            tenant: $tenant,
            actor: $actor,
            dataset: $dataset,
            modelAlias: is_string($modelOpt) && trim($modelOpt) !== '' ? trim($modelOpt) : null,
        );

        $this->info("Evaluation Run ID: {$run->id}");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Dataset', $dataset->name],
                ['Cases Passed', "{$run->passed_cases} / {$run->total_cases}"],
                ['Citation Correctness', "{$run->citation_correctness_pct}% (target >= 95%)"],
                ['Groundedness Score', "{$run->groundedness_score_pct}%"],
                ['Abstention Accuracy', "{$run->abstention_accuracy_pct}%"],
                ['Avg Latency', "{$run->avg_latency_ms} ms"],
                ['Total Cost (USD)', '$'.$run->total_cost_usd],
            ],
        );

        return self::SUCCESS;
    }
}
