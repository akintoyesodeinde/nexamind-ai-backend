<?php

declare(strict_types=1);

namespace App\Domain\Documents\Services;

use DOMDocument;
use DOMXPath;
use RuntimeException;
use Smalot\PdfParser\Parser;
use ZipArchive;

final class DocumentTextExtractor
{
    private const MAX_PDF_PAGES = 500;

    /**
     * Returns normalized pages with 1-based page numbers.
     *
     * @return list<array{page_number: int, text: string}>
     */
    public function extractPages(string $contents, string $mimeType, string $filename): array
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if ($ext === 'pdf' || $mimeType === 'application/pdf') {
            return $this->extractPdf($contents);
        }

        if ($ext === 'docx' || str_contains($mimeType, 'wordprocessingml')) {
            return $this->extractDocx($contents);
        }

        return [
            [
                'page_number' => 1,
                'text' => $this->normalizeText($contents),
            ],
        ];
    }

    /**
     * @return list<array{page_number: int, text: string}>
     */
    private function extractPdf(string $contents): array
    {
        $parser = new Parser;
        $pdf = $parser->parseContent($contents);
        $pages = $pdf->getPages();

        if (count($pages) > self::MAX_PDF_PAGES) {
            throw new RuntimeException('PDF exceeds maximum supported page count ('.self::MAX_PDF_PAGES.').');
        }

        $result = [];
        foreach ($pages as $index => $page) {
            $text = $this->normalizeText($page->getText());
            if ($text !== '') {
                $result[] = [
                    'page_number' => $index + 1,
                    'text' => $text,
                ];
            }
        }

        return $result;
    }

    /**
     * @return list<array{page_number: int, text: string}>
     */
    private function extractDocx(string $contents): array
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'nxm_docx_');
        if ($tmpPath === false) {
            throw new RuntimeException('Unable to allocate temporary file for DOCX parsing.');
        }

        try {
            file_put_contents($tmpPath, $contents);
            $zip = new ZipArchive;
            if ($zip->open($tmpPath) !== true) {
                throw new RuntimeException('Unable to open DOCX archive.');
            }

            $xml = $zip->getFromName('word/document.xml');
            $zip->close();

            if ($xml === false || $xml === '') {
                throw new RuntimeException('DOCX archive does not contain word/document.xml.');
            }

            $dom = new DOMDocument;
            // Strict XXE prevention: disable network loading & do not substitute external entities
            $prevErrors = libxml_use_internal_errors(true);
            $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
            libxml_clear_errors();
            libxml_use_internal_errors($prevErrors);

            if (! $loaded) {
                throw new RuntimeException('Unable to parse DOCX XML safely.');
            }

            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $paragraphs = $xpath->query('//w:body//w:p');

            $lines = [];
            if ($paragraphs !== false) {
                foreach ($paragraphs as $p) {
                    $textNodes = $xpath->query('.//w:t', $p);
                    if ($textNodes === false) {
                        continue;
                    }
                    $buf = '';
                    foreach ($textNodes as $t) {
                        $buf .= $t->textContent;
                    }
                    $trimmed = trim($buf);
                    if ($trimmed !== '') {
                        $lines[] = $trimmed;
                    }
                }
            }

            $text = $this->normalizeText(implode("\n\n", $lines));

            return $text !== '' ? [['page_number' => 1, 'text' => $text]] : [];
        } finally {
            @unlink($tmpPath);
        }
    }

    private function normalizeText(string $raw): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $raw);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? $text;
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
