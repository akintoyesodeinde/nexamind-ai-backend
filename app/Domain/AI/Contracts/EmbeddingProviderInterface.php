<?php

declare(strict_types=1);

namespace App\Domain\AI\Contracts;

interface EmbeddingProviderInterface
{
    public function providerKey(): string;

    public function dimensions(): int;

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embedBatch(string $model, array $texts): array;
}
