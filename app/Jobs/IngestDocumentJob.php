<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Documents\Services\DocumentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class IngestDocumentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly string $tenantId,
        public readonly string $documentId,
        public readonly string $documentVersionId,
    ) {}

    public function handle(DocumentService $documentService): void
    {
        /** @var Document|null $document */
        $document = Document::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->where('id', $this->documentId)
            ->first();

        /** @var DocumentVersion|null $version */
        $version = DocumentVersion::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->where('document_id', $this->documentId)
            ->where('id', $this->documentVersionId)
            ->first();

        if ($document === null || $version === null || $document->status === 'deleted') {
            return;
        }

        $documentService->ingestVersion($document, $version);
    }
}
