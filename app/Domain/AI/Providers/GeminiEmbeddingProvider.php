<?php

declare(strict_types=1);

namespace App\Domain\AI\Providers;

use App\Domain\AI\Contracts\EmbeddingProviderInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class GeminiEmbeddingProvider implements EmbeddingProviderInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta',
        private readonly int $dimensions = 1536,
    ) {}

    public function providerKey(): string
    {
        return 'gemini';
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
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }

        $resolvedModel = $model === 'default-embedding'
            ? (string) config('ai.providers.gemini.default_embedding_model', 'text-embedding-004')
            : $model;

        $vectors = [];
        foreach ($texts as $text) {
            $url = sprintf(
                '%s/models/%s:embedContent?key=%s',
                rtrim($this->baseUrl, '/'),
                rawurlencode($resolvedModel),
                rawurlencode($this->apiKey),
            );

            $response = Http::timeout(30)->post($url, [
                'content' => ['parts' => [['text' => $text]]],
                'outputDimensionality' => $this->dimensions,
            ]);

            if (! $response->successful()) {
                throw new RuntimeException('Gemini embedding request failed ('.$response->status().').');
            }

            /** @var array{embedding?: array{values?: list<float>}} $body */
            $body = $response->json();
            $vectors[] = array_map(static fn (mixed $v): float => (float) $v, $body['embedding']['values'] ?? []);
        }

        return $vectors;
    }
}
