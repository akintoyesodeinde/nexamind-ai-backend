<?php

declare(strict_types=1);

namespace App\Domain\Documents\Contracts;

interface DocumentStorageInterface
{
    public function put(string $storagePath, string $contents, string $mimeType): void;

    public function get(string $storagePath): string;

    public function delete(string $storagePath): void;

    public function temporaryDownloadUrl(string $storagePath, string $downloadFilename, int $ttlSeconds = 300): string;
}
