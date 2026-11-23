<?php

declare(strict_types=1);

namespace App\Domain\AI\Providers;

use App\Domain\AI\Contracts\LlmProviderInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class OpenAiLlmProvider implements LlmProviderInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.openai.com/v1',
    ) {}

    public function providerKey(): string
    {
        return 'openai';
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array{content: string, input_tokens: int, output_tokens: int, model: string}
     */
    public function complete(string $model, array $messages, array $options = []): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY is not configured.');
        }

        $resolvedModel = $model === 'default-chat'
            ? (string) config('ai.providers.openai.default_chat_model', 'gpt-4o-mini')
            : $model;

        $payload = [
            'model' => $resolvedModel,
            'messages' => $messages,
            'temperature' => (float) ($options['temperature'] ?? 0.2),
            'max_tokens' => (int) ($options['max_tokens'] ?? 1024),
        ];

        $response = Http::timeout(60)
            ->withToken($this->apiKey)
            ->post(rtrim($this->baseUrl, '/').'/chat/completions', $payload);

        if (! $response->successful()) {
            throw new RuntimeException('OpenAI chat completion failed ('.$response->status().').');
        }

        /** @var array{choices?: list<array{message?: array{content?: string}}>, usage?: array{prompt_tokens?: int, completion_tokens?: int}} $body */
        $body = $response->json();
        $content = (string) ($body['choices'][0]['message']['content'] ?? '');

        return [
            'content' => $content,
            'input_tokens' => (int) ($body['usage']['prompt_tokens'] ?? 0),
            'output_tokens' => (int) ($body['usage']['completion_tokens'] ?? 0),
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
