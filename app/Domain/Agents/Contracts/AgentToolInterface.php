<?php

declare(strict_types=1);

namespace App\Domain\Agents\Contracts;

use App\Domain\Agents\Models\Agent;
use App\Domain\Agents\Models\AgentRun;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Tenant;

interface AgentToolInterface
{
    public function name(): string;

    public function description(): string;

    public function requiresApproval(): bool;

    public function riskLevel(): string;

    /**
     * Strict JSON Schema definition for tool input arguments.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{
     *     output: array<string, mixed>,
     *     tokens_used: int,
     *     cost_usd: string
     * }
     */
    public function execute(
        Tenant $tenant,
        User $user,
        Agent $agent,
        AgentRun $run,
        array $arguments,
    ): array;
}
