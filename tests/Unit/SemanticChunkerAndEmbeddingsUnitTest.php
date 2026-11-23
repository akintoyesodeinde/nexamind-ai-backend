<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\AI\Providers\DeterministicMockEmbeddingProvider;
use App\Domain\Documents\Services\SemanticChunker;
use PHPUnit\Framework\TestCase;

final class SemanticChunkerAndEmbeddingsUnitTest extends TestCase
{
    public function test_semantic_chunker_preserves_headings_and_pages(): void
    {
        $chunker = new SemanticChunker(maxChars: 220, overlapChars: 40);

        $chunks = $chunker->chunkPages([
            [
                'page_number' => 1,
                'text' => "# Section 1 Loan Approval Policy\n\nCommercial loans above \$250,000 require credit committee review and dual manager sign-off.\n\nAll underwriting memos must cite borrower DSCR ratios and collateral appraisals.",
            ],
            [
                'page_number' => 2,
                'text' => "# Section 2 Audit Retention\n\nApproved credit packages must be retained for seven years in immutable storage.",
            ],
        ]);

        $this->assertGreaterThanOrEqual(2, count($chunks));
        $this->assertSame(1, $chunks[0]['page_number']);
        $this->assertSame('Section 1 Loan Approval Policy', $chunks[0]['section_heading']);

        $last = $chunks[count($chunks) - 1];
        $this->assertSame(2, $last['page_number']);
        $this->assertSame('Section 2 Audit Retention', $last['section_heading']);
        $this->assertGreaterThan(0, $last['token_estimate']);
    }

    public function test_mock_embedding_provider_produces_higher_similarity_for_matching_topic(): void
    {
        $provider = new DeterministicMockEmbeddingProvider(1536);

        [$query, $loanDoc, $hrDoc] = $provider->embedBatch('default-embedding', [
            'What are the commercial loan approval thresholds and credit committee requirements?',
            'Commercial loan approval thresholds above 250000 require credit committee sign-off.',
            'Employees accrue twenty paid vacation days annually under the holiday leave schedule.',
        ]);

        $this->assertCount(1536, $query);

        $dotLoan = 0.0;
        $dotHr = 0.0;
        for ($i = 0; $i < 1536; $i++) {
            $dotLoan += $query[$i] * $loanDoc[$i];
            $dotHr += $query[$i] * $hrDoc[$i];
        }

        $this->assertGreaterThan($dotHr, $dotLoan);
        $this->assertGreaterThan(0.25, $dotLoan);
    }
}
