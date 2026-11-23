<?php

declare(strict_types=1);

namespace App\Domain\AI\Providers;

use App\Domain\AI\Contracts\EmbeddingProviderInterface;

/**
 * Deterministic token-hashed 1536-dimensional L2-normalized embedding provider.
 * Ensures lexical/semantic overlap yields high cosine similarity in local dev & CI
 * with zero external API spend.
 */
final class DeterministicMockEmbeddingProvider implements EmbeddingProviderInterface
{
    public function __construct(
        private readonly int $dimensions = 1536,
    ) {}

    public function providerKey(): string
    {
        return 'mock';
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
        $vectors = [];
        foreach ($texts as $text) {
            $vectors[] = $this->embedOne($text);
        }

        return $vectors;
    }

    /**
     * @return list<float>
     */
    private function embedOne(string $text): array
    {
        $vec = array_fill(0, $this->dimensions, 0.0);
        $normalized = strtolower($text);
        $tokens = preg_split('/[^a-z0-9]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($tokens === []) {
            $vec[0] = 1.0;

            return $vec;
        }

        // Unigram + bigram + character trigram feature hashing for robust lexical similarity
        $features = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];
            if (strlen($tok) < 2) {
                continue;
            }
            $features[] = 'w:'.$tok;
            if ($i + 1 < $count) {
                $features[] = 'b:'.$tok.'_'.$tokens[$i + 1];
            }
            if (strlen($tok) >= 4) {
                $len = strlen($tok);
                for ($c = 0; $c <= $len - 3; $c++) {
                    $features[] = 't:'.substr($tok, $c, 3);
                }
            }
        }

        foreach ($features as $feature) {
            $hash = crc32($feature);
            $bucket = (int) (abs($hash) % $this->dimensions);
            $sign = (($hash & 1) === 0) ? 1.0 : -1.0;
            $weight = str_starts_with($feature, 'w:') ? 2.0 : (str_starts_with($feature, 'b:') ? 1.5 : 0.5);
            $vec[$bucket] += $sign * $weight;
        }

        $norm = 0.0;
        for ($i = 0; $i < $this->dimensions; $i++) {
            $norm += $vec[$i] * $vec[$i];
        }
        $norm = sqrt($norm);

        if ($norm <= 1e-12) {
            $vec[0] = 1.0;

            return $vec;
        }

        for ($i = 0; $i < $this->dimensions; $i++) {
            $vec[$i] = round($vec[$i] / $norm, 6);
        }

        return $vec;
    }
}
