<?php

declare(strict_types=1);

namespace App\Domain\AI\Providers;

use App\Domain\AI\Contracts\LlmProviderInterface;

/**
 * Deterministic, grounded, citation-aware mock LLM provider for offline local development,
 * automated CI, and repeatable RAG / security regression testing with zero external API spend.
 */
final class DeterministicMockLlmProvider implements LlmProviderInterface
{
    public function providerKey(): string
    {
        return 'mock';
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array{content: string, input_tokens: int, output_tokens: int, model: string}
     */
    public function complete(string $model, array $messages, array $options = []): array
    {
        $combinedInput = implode("\n", array_map(static fn (array $m): string => $m['content'], $messages));
        $inputTokens = max(12, (int) ceil(strlen($combinedInput) / 4));

        $mode = is_string($options['mode'] ?? null) ? $options['mode'] : 'rag_chat';
        $content = match ($mode) {
            'doc_summarize' => $this->buildSummaryResponse($combinedInput),
            'doc_classify' => $this->buildClassificationResponse($combinedInput),
            'doc_extract' => $this->buildExtractionResponse($combinedInput, $options),
            'doc_compare' => $this->buildCompareResponse($combinedInput),
            default => $this->buildGroundedRagAnswer($messages),
        };

        $outputTokens = max(8, (int) ceil(strlen($content) / 4));

        return [
            'content' => $content,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'model' => $model,
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
        $words = preg_split('/(\s+)/', $result['content'], -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$result['content']];

        $buffer = '';
        $count = 0;
        foreach ($words as $piece) {
            $buffer .= $piece;
            $count++;
            if ($count >= 6) {
                $onDelta($buffer);
                $buffer = '';
                $count = 0;
            }
        }
        if ($buffer !== '') {
            $onDelta($buffer);
        }

        return $result;
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    private function buildGroundedRagAnswer(array $messages): string
    {
        $lastUser = '';
        foreach (array_reverse($messages) as $msg) {
            if ($msg['role'] === 'user') {
                $lastUser = $msg['content'];
                break;
            }
        }

        // Parse ONLY evidence wrapped inside <retrieved_document_chunk ...> tags
        preg_match_all(
            '/<retrieved_document_chunk\s+id="([^"]+)"\s+document="([^"]+)"(?:\s+page="([^"]*)")?>(.*?)<\/retrieved_document_chunk>/s',
            $lastUser,
            $matches,
            PREG_SET_ORDER,
        );

        if ($matches === []) {
            return 'ABSTAIN: The authorized organizational documents provided do not contain sufficient evidence to answer this question reliably.';
        }

        $bullets = [];
        foreach (array_slice($matches, 0, 3) as $match) {
            $chunkId = trim($match[1]);
            $docName = trim($match[2]);
            $page = trim($match[3]);
            $body = $this->stripInjectionDirectives(trim($match[4]));

            $sentences = preg_split('/(?<=[.!?])\s+/', $body) ?: [$body];
            $snippet = trim(implode(' ', array_slice($sentences, 0, 2)));
            if (strlen($snippet) > 260) {
                $snippet = substr($snippet, 0, 257).'...';
            }

            $pageRef = $page !== '' ? " (page {$page})" : '';
            $bullets[] = "- According to **{$docName}**{$pageRef}, {$snippet} [{$chunkId}]";
        }

        return "Based on the authorized organizational documents:\n\n".implode("\n", $bullets);
    }

    private function buildSummaryResponse(string $input): string
    {
        $excerpt = $this->extractFirstEvidenceText($input);
        $sentences = preg_split('/(?<=[.!?])\s+/', $excerpt) ?: [$excerpt];

        return json_encode([
            'executive_summary' => trim(implode(' ', array_slice($sentences, 0, 3))) ?: 'Document reviewed with no textual summary available.',
            'key_points' => array_values(array_filter(array_map('trim', array_slice($sentences, 0, 4)))),
            'compliance_notes' => 'Generated strictly from authorized document chunks.',
        ], JSON_THROW_ON_ERROR);
    }

    private function buildClassificationResponse(string $input): string
    {
        $lower = strtolower($input);
        $category = match (true) {
            str_contains($lower, 'loan') || str_contains($lower, 'credit') || str_contains($lower, 'collateral') => 'Financial & Credit Policy',
            str_contains($lower, 'vacation') || str_contains($lower, 'leave') || str_contains($lower, 'employee') => 'Human Resources Policy',
            str_contains($lower, 'compliance') || str_contains($lower, 'audit') || str_contains($lower, 'soc2') => 'Compliance & Governance',
            str_contains($lower, 'security') || str_contains($lower, 'access') || str_contains($lower, 'incident') => 'Information Security',
            default => 'General Enterprise Operations',
        };

        $sensitivity = (str_contains($lower, 'confidential') || str_contains($lower, 'whistleblower') || str_contains($lower, 'restricted'))
            ? 'confidential'
            : 'internal';

        return json_encode([
            'primary_category' => $category,
            'sensitivity_level' => $sensitivity,
            'confidence' => 0.92,
            'tags' => array_values(array_unique([$category, $sensitivity, 'enterprise-policy'])),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function buildExtractionResponse(string $input, array $options): string
    {
        $text = $this->extractFirstEvidenceText($input);
        $fields = is_array($options['schema_fields'] ?? null) ? $options['schema_fields'] : ['thresholds', 'approvers', 'effective_rules'];

        preg_match_all('/\$?\d[\d,]*(?:\.\d+)?\s*(?:USD|dollars|days|years|%)?/i', $text, $amounts);
        $extracted = [];

        foreach ($fields as $field) {
            if (! is_string($field) || trim($field) === '') {
                continue;
            }
            $key = trim($field);
            $extracted[$key] = match (strtolower($key)) {
                'thresholds', 'monetary_limits', 'amounts' => array_values(array_unique(array_slice($amounts[0], 0, 5))),
                'approvers', 'owners' => $this->extractApprovers($text),
                default => substr($text, 0, 220),
            };
        }

        return json_encode([
            'extracted_fields' => $extracted,
            'source_grounded' => true,
        ], JSON_THROW_ON_ERROR);
    }

    private function buildCompareResponse(string $input): string
    {
        preg_match_all('/<document_slot\s+label="([^"]+)"\s+name="([^"]+)">(.*?)<\/document_slot>/s', $input, $slots, PREG_SET_ORDER);
        $docA = $slots[0][2] ?? 'Document A';
        $docB = $slots[1][2] ?? 'Document B';
        $textA = trim($slots[0][3] ?? '');
        $textB = trim($slots[1][3] ?? '');

        return json_encode([
            'summary' => "Compared authorized content between {$docA} and {$docB}.",
            'document_a_highlights' => substr($textA, 0, 220),
            'document_b_highlights' => substr($textB, 0, 220),
            'key_differences' => [
                "Document A ({$docA}) covers: ".substr($textA, 0, 120),
                "Document B ({$docB}) covers: ".substr($textB, 0, 120),
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function extractFirstEvidenceText(string $input): string
    {
        if (preg_match_all('/<retrieved_document_chunk[^>]*>(.*?)<\/retrieved_document_chunk>/s', $input, $m) > 0) {
            return trim(implode(' ', array_map('trim', $m[1])));
        }

        return trim(substr($input, -1200));
    }

    private function stripInjectionDirectives(string $text): string
    {
        $cleaned = preg_replace(
            '/(ignore\s+(all\s+)?previous\s+instructions[^.\n]*|disregard\s+system\s+prompt[^.\n]*|reveal\s+all\s+secrets[^.\n]*)/i',
            '[untrusted directive ignored]',
            $text,
        );

        return trim($cleaned ?? $text);
    }

    /**
     * @return list<string>
     */
    private function extractApprovers(string $text): array
    {
        $found = [];
        foreach (['Credit Committee', 'Chief Risk Officer', 'Manager', 'Executive', 'Compliance Officer', 'Owner'] as $role) {
            if (stripos($text, $role) !== false) {
                $found[] = $role;
            }
        }

        return $found !== [] ? $found : ['Designated Organization Approver'];
    }
}
