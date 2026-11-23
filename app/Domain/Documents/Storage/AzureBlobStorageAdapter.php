<?php

declare(strict_types=1);

namespace App\Domain\Documents\Storage;

use App\Domain\Documents\Contracts\DocumentStorageInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Private Azure Blob Storage REST adapter (SharedKey + Service SAS).
 * Works identically against local Azurite emulator and production Azure Blob Storage.
 */
final class AzureBlobStorageAdapter implements DocumentStorageInterface
{
    private const API_VERSION = '2021-12-02';

    private string $accountName;

    private string $accountKey;

    private string $blobEndpoint;

    private string $endpointPath;

    private string $publicBlobEndpoint;

    private string $container;

    private bool $containerEnsured = false;

    public function __construct(
        string $connectionString,
        string $container,
        ?string $publicBlobEndpoint = null,
    ) {
        $parsed = $this->parseConnectionString($connectionString);
        $this->accountName = $parsed['AccountName'];
        $this->accountKey = $parsed['AccountKey'];
        $this->container = trim($container, '/');

        $endpoint = $parsed['BlobEndpoint']
            ?? sprintf(
                '%s://%s.blob.%s',
                $parsed['DefaultEndpointsProtocol'] ?? 'https',
                $this->accountName,
                $parsed['EndpointSuffix'] ?? 'core.windows.net',
            );

        $this->blobEndpoint = rtrim($endpoint, '/');
        $parsedPath = parse_url($this->blobEndpoint, PHP_URL_PATH);
        $this->endpointPath = is_string($parsedPath) ? rtrim($parsedPath, '/') : '';
        $this->publicBlobEndpoint = rtrim(
            ($publicBlobEndpoint !== null && trim($publicBlobEndpoint) !== '')
                ? $publicBlobEndpoint
                : $this->blobEndpoint,
            '/',
        );
    }

    public function put(string $storagePath, string $contents, string $mimeType): void
    {
        $this->ensureContainerExists();

        $normalizedPath = ltrim($storagePath, '/');
        $date = gmdate('D, d M Y H:i:s').' GMT';
        $contentLength = strlen($contents);

        $msHeaders = [
            'x-ms-blob-type' => 'BlockBlob',
            'x-ms-date' => $date,
            'x-ms-version' => self::API_VERSION,
        ];

        $authHeader = $this->buildAuthorizationHeader(
            method: 'PUT',
            canonicalResource: "/{$this->accountName}{$this->endpointPath}/{$this->container}/".$this->encodePath($normalizedPath),
            contentLength: $contentLength > 0 ? (string) $contentLength : '',
            contentType: $mimeType,
            msHeaders: $msHeaders,
        );

        $url = "{$this->blobEndpoint}/{$this->container}/".$this->encodePath($normalizedPath);

        $response = Http::timeout(30)
            ->withHeaders(array_merge($msHeaders, [
                'Authorization' => $authHeader,
            ]))
            ->withBody($contents, $mimeType)
            ->put($url);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Azure Blob PUT failed (%d): %s',
                $response->status(),
                substr($response->body(), 0, 300),
            ));
        }
    }

    public function get(string $storagePath): string
    {
        $normalizedPath = ltrim($storagePath, '/');
        $date = gmdate('D, d M Y H:i:s').' GMT';

        $msHeaders = [
            'x-ms-date' => $date,
            'x-ms-version' => self::API_VERSION,
        ];

        $authHeader = $this->buildAuthorizationHeader(
            method: 'GET',
            canonicalResource: "/{$this->accountName}{$this->endpointPath}/{$this->container}/".$this->encodePath($normalizedPath),
            contentLength: '',
            contentType: '',
            msHeaders: $msHeaders,
        );

        $url = "{$this->blobEndpoint}/{$this->container}/".$this->encodePath($normalizedPath);

        $response = Http::timeout(30)
            ->withHeaders(array_merge($msHeaders, [
                'Authorization' => $authHeader,
            ]))
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Azure Blob GET failed (%d) for path %s',
                $response->status(),
                $normalizedPath,
            ));
        }

        return $response->body();
    }

    public function delete(string $storagePath): void
    {
        $normalizedPath = ltrim($storagePath, '/');
        $date = gmdate('D, d M Y H:i:s').' GMT';

        $msHeaders = [
            'x-ms-date' => $date,
            'x-ms-version' => self::API_VERSION,
        ];

        $authHeader = $this->buildAuthorizationHeader(
            method: 'DELETE',
            canonicalResource: "/{$this->accountName}{$this->endpointPath}/{$this->container}/".$this->encodePath($normalizedPath),
            contentLength: '',
            contentType: '',
            msHeaders: $msHeaders,
        );

        $url = "{$this->blobEndpoint}/{$this->container}/".$this->encodePath($normalizedPath);

        $response = Http::timeout(15)
            ->withHeaders(array_merge($msHeaders, [
                'Authorization' => $authHeader,
            ]))
            ->delete($url);

        if (! $response->successful() && $response->status() !== 404) {
            throw new RuntimeException(sprintf(
                'Azure Blob DELETE failed (%d) for path %s',
                $response->status(),
                $normalizedPath,
            ));
        }
    }

    public function temporaryDownloadUrl(
        string $storagePath,
        string $downloadFilename,
        int $ttlSeconds = 300,
    ): string {
        $normalizedPath = ltrim($storagePath, '/');
        $safeFilename = preg_replace('/[^A-Za-z0-9._ -]/', '_', $downloadFilename) ?? 'document';
        $expiry = gmdate('Y-m-d\TH:i:s\Z', time() + max(30, min($ttlSeconds, 3600)));
        $rscd = 'attachment; filename="'.$safeFilename.'"';
        $canonicalResource = "/blob/{$this->accountName}/{$this->container}/{$normalizedPath}";

        $stringToSign = implode("\n", [
            'r',                 // sp: read only
            '',                  // st
            $expiry,             // se
            $canonicalResource,  // canonicalizedResource
            '',                  // si
            '',                  // sip
            '',                  // spr
            self::API_VERSION,   // sv
            'b',                 // sr: blob
            '',                  // snapshot
            '',                  // encryptionScope
            '',                  // rscc
            $rscd,               // rscd
            '',                  // rsce
            '',                  // rscl
            '',                  // rsct
        ]);

        $decodedKey = base64_decode($this->accountKey, true);
        if ($decodedKey === false) {
            throw new RuntimeException('Invalid Azure Storage AccountKey.');
        }

        $sig = base64_encode(hash_hmac('sha256', $stringToSign, $decodedKey, true));

        $query = http_build_query([
            'sv' => self::API_VERSION,
            'sr' => 'b',
            'sp' => 'r',
            'se' => $expiry,
            'rscd' => $rscd,
            'sig' => $sig,
        ], '', '&', PHP_QUERY_RFC3986);

        return "{$this->publicBlobEndpoint}/{$this->container}/".$this->encodePath($normalizedPath)."?{$query}";
    }

    private function ensureContainerExists(): void
    {
        if ($this->containerEnsured) {
            return;
        }

        $date = gmdate('D, d M Y H:i:s').' GMT';
        $msHeaders = [
            'x-ms-date' => $date,
            'x-ms-version' => self::API_VERSION,
        ];

        $authHeader = $this->buildAuthorizationHeader(
            method: 'PUT',
            canonicalResource: "/{$this->accountName}{$this->endpointPath}/{$this->container}\nrestype:container",
            contentLength: '',
            contentType: '',
            msHeaders: $msHeaders,
        );

        $response = Http::timeout(15)
            ->withHeaders(array_merge($msHeaders, [
                'Authorization' => $authHeader,
            ]))
            ->send('PUT', "{$this->blobEndpoint}/{$this->container}?restype=container", [
                'body' => '',
            ]);

        if ($response->successful() || $response->status() === 409) {
            $this->containerEnsured = true;

            return;
        }

        throw new RuntimeException(sprintf(
            'Unable to ensure private Azure Blob container (%d): %s',
            $response->status(),
            substr($response->body(), 0, 300),
        ));
    }

    /**
     * @param  array<string, string>  $msHeaders
     */
    private function buildAuthorizationHeader(
        string $method,
        string $canonicalResource,
        string $contentLength,
        string $contentType,
        array $msHeaders,
    ): string {
        ksort($msHeaders);
        $canonicalHeaders = '';
        foreach ($msHeaders as $k => $v) {
            $canonicalHeaders .= strtolower($k).':'.trim($v)."\n";
        }

        $stringToSign = implode("\n", [
            strtoupper($method),
            '', // Content-Encoding
            '', // Content-Language
            $contentLength,
            '', // Content-MD5
            $contentType,
            '', // Date (empty when x-ms-date is present)
            '', // If-Modified-Since
            '', // If-Match
            '', // If-None-Match
            '', // If-Unmodified-Since
            '', // Range
            $canonicalHeaders.$canonicalResource,
        ]);

        $decodedKey = base64_decode($this->accountKey, true);
        if ($decodedKey === false) {
            throw new RuntimeException('Invalid Azure Storage AccountKey.');
        }

        $signature = base64_encode(hash_hmac('sha256', $stringToSign, $decodedKey, true));

        return "SharedKey {$this->accountName}:{$signature}";
    }

    /**
     * @return array<string, string>
     */
    private function parseConnectionString(string $connectionString): array
    {
        $parts = [];
        foreach (explode(';', $connectionString) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || ! str_contains($segment, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $segment, 2);
            $parts[trim($k)] = trim($v);
        }

        if (! isset($parts['AccountName'], $parts['AccountKey'])) {
            throw new RuntimeException('AZURE_STORAGE_CONNECTION_STRING must include AccountName and AccountKey.');
        }

        return $parts;
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
