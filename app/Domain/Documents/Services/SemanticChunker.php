<?php

declare(strict_types=1);

namespace App\Domain\Documents\Services;

final class SemanticChunker
{
    public function __construct(
        private readonly int $maxChars = 900,
        private readonly int $overlapChars = 150,
    ) {}

    /**
     * Splits normalized page text into semantic chunks preserving page references and section headings.
     *
     * @param  list<array{page_number: int, text: string}>  $pages
     * @return list<array{
     *     chunk_index: int,
     *     page_number: int,
     *     section_heading: string|null,
     *     char_start: int,
     *     char_end: int,
     *     token_estimate: int,
     *     content: string,
     *     content_hash: string
     * }>
     */
    public function chunkPages(array $pages): array
    {
        $chunks = [];
        $chunkIndex = 0;
        $globalOffset = 0;
        $activeHeading = null;

        foreach ($pages as $page) {
            $pageNumber = $page['page_number'];
            $paragraphs = preg_split("/\n\n+/", trim($page['text'])) ?: [];

            $buffer = '';
            $bufferStart = $globalOffset;

            foreach ($paragraphs as $para) {
                $para = trim($para);
                if ($para === '') {
                    continue;
                }

                if ($this->looksLikeHeading($para)) {
                    if ($buffer !== '') {
                        $chunks[] = $this->makeChunk($chunkIndex++, $pageNumber, $activeHeading, $bufferStart, $bufferStart + strlen($buffer), $buffer);
                        $buffer = '';
                    }
                    $activeHeading = ltrim($para, "# \t");
                    $activeHeading = substr($activeHeading, 0, 250);
                    $bufferStart = $globalOffset;
                    $buffer = $para;
                    $globalOffset += strlen($para) + 2;

                    continue;
                }

                if ($buffer !== '' && (strlen($buffer) + strlen($para) + 2) > $this->maxChars) {
                    $chunks[] = $this->makeChunk($chunkIndex++, $pageNumber, $activeHeading, $bufferStart, $bufferStart + strlen($buffer), $buffer);

                    $overlap = $this->overlapChars > 0 ? substr($buffer, -$this->overlapChars) : '';
                    $bufferStart = max(0, $globalOffset - strlen($overlap));
                    $buffer = trim($overlap !== '' ? $overlap."\n\n".$para : $para);
                } elseif (strlen($para) > $this->maxChars) {
                    if ($buffer !== '') {
                        $chunks[] = $this->makeChunk($chunkIndex++, $pageNumber, $activeHeading, $bufferStart, $bufferStart + strlen($buffer), $buffer);
                        $buffer = '';
                    }
                    foreach ($this->splitLongParagraph($para) as $slice) {
                        $chunks[] = $this->makeChunk($chunkIndex++, $pageNumber, $activeHeading, $globalOffset, $globalOffset + strlen($slice), $slice);
                    }
                } else {
                    if ($buffer === '') {
                        $bufferStart = $globalOffset;
                        $buffer = $para;
                    } else {
                        $buffer .= "\n\n".$para;
                    }
                }

                $globalOffset += strlen($para) + 2;
            }

            if ($buffer !== '') {
                $chunks[] = $this->makeChunk($chunkIndex++, $pageNumber, $activeHeading, $bufferStart, $bufferStart + strlen($buffer), $buffer);
            }
        }

        return $chunks;
    }

    private function looksLikeHeading(string $paragraph): bool
    {
        if (str_contains($paragraph, "\n") || strlen($paragraph) > 120) {
            return false;
        }

        return str_starts_with($paragraph, '#')
            || preg_match('/^(Section|Article|Policy|Chapter)\s+\d+/i', $paragraph) === 1
            || (preg_match('/^\d+(\.\d+)*\s+[A-Z]/', $paragraph) === 1 && ! str_ends_with($paragraph, '.'));
    }

    /**
     * @return list<string>
     */
    private function splitLongParagraph(string $paragraph): array
    {
        $slices = [];
        $len = strlen($paragraph);
        $step = max(100, $this->maxChars - $this->overlapChars);
        for ($pos = 0; $pos < $len; $pos += $step) {
            $slice = trim(substr($paragraph, $pos, $this->maxChars));
            if ($slice !== '') {
                $slices[] = $slice;
            }
            if ($pos + $this->maxChars >= $len) {
                break;
            }
        }

        return $slices;
    }

    /**
     * @return array{
     *     chunk_index: int,
     *     page_number: int,
     *     section_heading: string|null,
     *     char_start: int,
     *     char_end: int,
     *     token_estimate: int,
     *     content: string,
     *     content_hash: string
     * }
     */
    private function makeChunk(
        int $index,
        int $pageNumber,
        ?string $heading,
        int $charStart,
        int $charEnd,
        string $content,
    ): array {
        $trimmed = trim($content);

        return [
            'chunk_index' => $index,
            'page_number' => $pageNumber,
            'section_heading' => $heading,
            'char_start' => $charStart,
            'char_end' => $charEnd,
            'token_estimate' => max(1, (int) ceil(strlen($trimmed) / 4)),
            'content' => $trimmed,
            'content_hash' => hash('sha256', $trimmed),
        ];
    }
}
