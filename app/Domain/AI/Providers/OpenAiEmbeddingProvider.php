<?php

declare(strict_types=1);

namespace App\Domain\AI\Providers;

use App\Domain\AI\Contracts\EmbeddingProviderInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class OpenAiEmbeddingProvider implements EmbeddingProviderInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.openai.com/v1',
        private readonly int $dimensions = 1536,
    ) {}

    public function providerKey(): string
    {
        return 'openai';
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embedBatch(string $model, array $texts): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY is not configured.');
        }

        $resolvedModel = $model === 'default-embedding'
            ? (string) config('ai.providers.openai.default_embedding_model', 'text-embedding-3-small')
            : $model;

        $response = Http::timeout(45)
            ->withToken($this->apiKey)
            ->post(rtrim($this->baseUrl, '/').'/embeddings', [
                'model' => $resolvedModel,
                'input' => $texts,
                'dimensions' => $this->dimensions,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('OpenAI embedding request failed ('.$response->status().').');
        }

        /** @var array{data?: list<array{embedding?: list<float>}>} $body */
        $body = $response->json();
        $vectors = [];
        foreach ($body['data'] ?? [] as $item) {
            $vectors[] = array_map(static fn (mixed $v): float => (float) $v, $item['embedding'] ?? []);
        }

        return $vectors;
    }
}
