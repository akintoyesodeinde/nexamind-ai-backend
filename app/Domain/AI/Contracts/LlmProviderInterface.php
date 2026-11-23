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
}
