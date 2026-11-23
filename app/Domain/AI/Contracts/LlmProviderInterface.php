<?php

declare(strict_types=1);

namespace App\Domain\AI\Contracts;

interface LlmProviderInterface
{
    public function providerKey(): string;

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array{content: string, input_tokens: int, output_tokens: int, model: string}
     */
    public function complete(string $model, array $messages, array $options = []): array;

    /**
     * Streams delta chunks through $onDelta and returns the final assembled response + usage.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @param  callable(string): void  $onDelta
     * @param  array<string, mixed>  $options
     * @return array{content: string, input_tokens: int, output_tokens: int, model: string}
     */
    public function stream(string $model, array $messages, callable $onDelta, array $options = []): array;
}
