<?php

declare(strict_types=1);

namespace App\Domain\Agents\Contracts;

interface AgentToolInterface
{
    public function name(): string;

    public function description(): string;

    public function requiresApproval(): bool;

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function execute(array $arguments, array $context): array;
}
