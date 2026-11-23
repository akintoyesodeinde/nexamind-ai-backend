<?php

declare(strict_types=1);

namespace App\Domain\AI\Providers;

use App\Domain\AI\Contracts\LlmProviderInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class GeminiLlmProvider implements LlmProviderInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta',
    ) {}

    public function providerKey(): string
    {
        return 'gemini';
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array{content: string, input_tokens: int, output_tokens: int, model: string}
     */
    public function complete(string $model, array $messages, array $options = []): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }

        $resolvedModel = $model === 'default-chat'
            ? (string) config('ai.providers.gemini.default_chat_model', 'gemini-2.5-flash')
            : $model;

        $systemParts = [];
        $contents = [];
        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemParts[] = ['text' => $msg['content']];
            } else {
                $contents[] = [
                    'role' => $msg['role'] === 'assistant' ? 'model' : 'user',
                    'parts' => [['text' => $msg['content']]],
                ];
            }
        }

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => (float) ($options['temperature'] ?? 0.2),
                'maxOutputTokens' => (int) ($options['max_tokens'] ?? 1024),
            ],
        ];
        if ($systemParts !== []) {
            $payload['systemInstruction'] = ['parts' => $systemParts];
        }

        $url = sprintf(
            '%s/models/%s:generateContent?key=%s',
            rtrim($this->baseUrl, '/'),
            rawurlencode($resolvedModel),
            rawurlencode($this->apiKey),
        );

        $response = Http::timeout(60)->post($url, $payload);
        if (! $response->successful()) {
            throw new RuntimeException('Gemini completion failed ('.$response->status().').');
        }

        /** @var array{candidates?: list<array{content?: array{parts?: list<array{text?: string}>}}>, usageMetadata?: array{promptTokenCount?: int, candidatesTokenCount?: int}} $body */
        $body = $response->json();
        $content = (string) ($body['candidates'][0]['content']['parts'][0]['text'] ?? '');

        return [
            'content' => $content,
            'input_tokens' => (int) ($body['usageMetadata']['promptTokenCount'] ?? 0),
            'output_tokens' => (int) ($body['usageMetadata']['candidatesTokenCount'] ?? 0),
            'model' => $resolvedModel,
        ];
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  callable(string): void  $onDelta
     * @param  array<string, mixed>  $options
     * @return array{content: string, input_tokens: int, output_tokens: int, model: string}
     */
    public function stream(string $model, array $messages, callable $onDelta, array $options = []): array
    {
        $result = $this->complete($model, $messages, $options);
        foreach (str_split($result['content'], 64) as $chunk) {
            $onDelta($chunk);
        }

        return $result;
    }
}
