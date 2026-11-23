<?php

declare(strict_types=1);

namespace App\Domain\Documents\Services;

use App\Domain\Audit\Contracts\AuditLoggerInterface;
use App\Domain\Tenancy\Models\TenantSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use ZipArchive;

final class UploadSecurityValidator
{
    private const MAX_DOCX_ENTRIES = 500;

    private const MAX_DOCX_UNCOMPRESSED_BYTES = 52_428_800; // 50 MB

    private const MAX_DOCX_COMPRESSION_RATIO = 100.0;

    /**
     * @var array<string, string>
     */
    private const EXTENSION_TO_MIME = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt' => 'text/plain',
        'md' => 'text/markdown',
        'csv' => 'text/csv',
    ];

    public function __construct(
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    /**
     * @return array{sanitized_filename: string, extension: string, mime_type: string, size_bytes: int, checksum_sha256: string, contents: string}
     */
    public function validateAndRead(
        UploadedFile $file,
        TenantSetting $settings,
        string $tenantId,
        string $actorUserId,
    ): array {
        $rawName = $file->getClientOriginalName();
        $sanitizedFilename = $this->sanitizeFilename($rawName);
        $extension = strtolower(pathinfo($sanitizedFilename, PATHINFO_EXTENSION));

        $allowedTypes = array_map('strtolower', $settings->allowed_file_types_json ?? ['pdf', 'docx', 'txt', 'md', 'csv']);
        if ($extension === '' || ! in_array($extension, $allowedTypes, true) || ! isset(self::EXTENSION_TO_MIME[$extension])) {
            $this->reject($tenantId, $actorUserId, 'disallowed_extension', "File type '.{$extension}' is not permitted by organization policy.", ['filename' => $sanitizedFilename]);
        }

        $sizeBytes = (int) $file->getSize();
        $maxBytes = max(1, $settings->max_upload_mb) * 1024 * 1024;
        if ($sizeBytes <= 0 || $sizeBytes > $maxBytes) {
            $this->reject($tenantId, $actorUserId, 'file_size_limit', "File size must be between 1 byte and {$settings->max_upload_mb} MB.", ['size_bytes' => $sizeBytes, 'max_bytes' => $maxBytes]);
        }

        $realPath = $file->getRealPath();
        if ($realPath === false) {
            $this->reject($tenantId, $actorUserId, 'unreadable_upload', 'Uploaded file could not be read.', []);
        }

        $contents = file_get_contents($realPath);
        if ($contents === false || $contents === '') {
            $this->reject($tenantId, $actorUserId, 'empty_upload', 'Uploaded file is empty or unreadable.', []);
        }

        // Magic-byte & structure verification (never trust client-reported MIME)
        $this->verifyContentMatchesExtension($extension, $contents, $realPath, $tenantId, $actorUserId, $sanitizedFilename);

        return [
            'sanitized_filename' => $sanitizedFilename,
            'extension' => $extension,
            'mime_type' => self::EXTENSION_TO_MIME[$extension],
            'size_bytes' => strlen($contents),
            'checksum_sha256' => hash('sha256', $contents),
            'contents' => $contents,
        ];
    }

    private function verifyContentMatchesExtension(
        string $extension,
        string $contents,
        string $realPath,
        string $tenantId,
        string $actorUserId,
        string $filename,
    ): void {
        // Reject executable headers universally (PE/MZ, ELF, Mach-O, PHP tags in text files)
        $head4 = substr($contents, 0, 4);
        if (str_starts_with($contents, 'MZ') || $head4 === "\x7fELF" || $head4 === "\xfe\xed\xfa\xce" || $head4 === "\xfe\xed\xfa\xcf") {
            $this->reject($tenantId, $actorUserId, 'executable_spoofing', 'Executable binary payload rejected.', ['filename' => $filename, 'extension' => $extension]);
        }

        if ($extension === 'pdf') {
            if (! str_starts_with(ltrim($contents), '%PDF-')) {
                $this->reject($tenantId, $actorUserId, 'invalid_pdf_magic', 'File does not have a valid PDF signature.', ['filename' => $filename]);
            }

            return;
        }

        if ($extension === 'docx') {
            if (! str_starts_with($contents, "PK\x03\x04")) {
                $this->reject($tenantId, $actorUserId, 'invalid_docx_magic', 'File does not have a valid DOCX archive signature.', ['filename' => $filename]);
            }

            $this->inspectDocxArchiveSafety($realPath, $tenantId, $actorUserId, $filename);

            return;
        }

        // Plain text formats (.txt, .md, .csv)
        if (str_contains($contents, "\0") || ! mb_check_encoding($contents, 'UTF-8')) {
            $this->reject($tenantId, $actorUserId, 'invalid_text_encoding', 'Text file contains null bytes or invalid UTF-8 binary data.', ['filename' => $filename]);
        }

        if (preg_match('/<\?(php|=)/i', $contents) === 1 || preg_match('/<script\b/i', $contents) === 1) {
            $this->reject($tenantId, $actorUserId, 'active_script_payload', 'Embedded script content is not allowed in uploaded documents.', ['filename' => $filename]);
        }
    }

    private function inspectDocxArchiveSafety(
        string $realPath,
        string $tenantId,
        string $actorUserId,
        string $filename,
    ): void {
        $zip = new ZipArchive;
        if ($zip->open($realPath) !== true) {
            $this->reject($tenantId, $actorUserId, 'corrupt_docx', 'DOCX archive could not be opened safely.', ['filename' => $filename]);
        }

        try {
            if ($zip->numFiles > self::MAX_DOCX_ENTRIES) {
                $this->reject($tenantId, $actorUserId, 'zip_bomb_entries', 'DOCX archive exceeds safe entry limit.', ['entries' => $zip->numFiles]);
            }

            $totalUncompressed = 0;
            $totalCompressed = 0;
            $hasDocumentXml = false;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (! is_array($stat)) {
                    continue;
                }

                $entryName = (string) $stat['name'];
                if (str_contains($entryName, '../') || str_starts_with($entryName, '/')) {
                    $this->reject($tenantId, $actorUserId, 'zip_path_traversal', 'DOCX archive contains unsafe path traversal entries.', ['entry' => $entryName]);
                }

                if ($entryName === 'word/document.xml') {
                    $hasDocumentXml = true;
                }

                $uncompressed = (int) $stat['size'];
                $compressed = max(1, (int) $stat['comp_size']);
                $totalUncompressed += $uncompressed;
                $totalCompressed += $compressed;

                if ($totalUncompressed > self::MAX_DOCX_UNCOMPRESSED_BYTES) {
                    $this->reject($tenantId, $actorUserId, 'zip_bomb_size', 'DOCX uncompressed size exceeds security limit.', ['uncompressed' => $totalUncompressed]);
                }
            }

            if ($totalCompressed > 0 && ($totalUncompressed / $totalCompressed) > self::MAX_DOCX_COMPRESSION_RATIO) {
                $this->reject($tenantId, $actorUserId, 'zip_bomb_ratio', 'DOCX compression ratio exceeds safety threshold.', [
                    'ratio' => round($totalUncompressed / $totalCompressed, 2),
                ]);
            }

            if (! $hasDocumentXml) {
                $this->reject($tenantId, $actorUserId, 'invalid_docx_structure', 'Archive is not a valid Word (.docx) document.', ['filename' => $filename]);
            }
        } finally {
            $zip->close();
        }
    }

    private function sanitizeFilename(string $raw): string
    {
        $base = basename(str_replace(['\\', "\0"], ['/', ''], $raw));
        $clean = preg_replace('/[^\w.\- ()]/', '_', $base) ?? 'document';
        $clean = trim($clean, ". \t\n\r\0\x0B");

        return $clean !== '' ? substr($clean, 0, 200) : 'document';
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function reject(
        string $tenantId,
        string $actorUserId,
        string $reason,
        string $message,
        array $details,
    ): never {
        $this->auditLogger->recordSecurityEvent(
            eventType: 'document.upload_rejected_security',
            severity: 'high',
            summary: "Document upload rejected ({$reason}): {$message}",
            details: array_merge(['reason' => $reason], $details),
            tenantId: $tenantId,
            actorUserId: $actorUserId,
        );

        throw ValidationException::withMessages([
            'file' => [$message],
        ]);
    }
}
