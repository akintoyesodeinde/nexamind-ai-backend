<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\AI\Contracts\EmbeddingProviderInterface;
use App\Domain\AI\Providers\DeterministicMockEmbeddingProvider;
use App\Domain\AI\Providers\GeminiEmbeddingProvider;
use App\Domain\AI\Providers\OpenAiEmbeddingProvider;
use App\Domain\Audit\Contracts\AuditLoggerInterface;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Documents\Contracts\DocumentStorageInterface;
use App\Domain\Documents\Storage\AzureBlobStorageAdapter;
use App\Domain\Identity\Models\User;
use App\Domain\Retrieval\Adapters\SqlServerJsonVectorStore;
use App\Domain\Retrieval\Adapters\SqlServerNativeVectorStore;
use App\Domain\Retrieval\Contracts\VectorStoreInterface;
use App\Domain\Tenancy\Contracts\TenantContextInterface;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContextInterface::class, TenantContext::class);
        $this->app->scoped(AuditLoggerInterface::class, AuditLogger::class);

        $this->app->singleton(DocumentStorageInterface::class, function (): DocumentStorageInterface {
            return new AzureBlobStorageAdapter(
                connectionString: (string) config('ai.storage.connection_string'),
                container: (string) config('ai.storage.container', 'nexamind-documents'),
                publicBlobEndpoint: (string) config('ai.storage.public_blob_endpoint'),
            );
        });

        $this->app->singleton(EmbeddingProviderInterface::class, function (): EmbeddingProviderInterface {
            $provider = strtolower((string) config('ai.default_provider', 'mock'));
            $dims = max(1, (int) config('ai.embedding_dimensions', 1536));

            return match ($provider) {
                'openai' => new OpenAiEmbeddingProvider(
                    apiKey: (string) config('ai.providers.openai.api_key', ''),
                    baseUrl: (string) config('ai.providers.openai.base_url', 'https://api.openai.com/v1'),
                    dimensions: $dims,
                ),
                'gemini' => new GeminiEmbeddingProvider(
                    apiKey: (string) config('ai.providers.gemini.api_key', ''),
                    baseUrl: (string) config('ai.providers.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'),
                    dimensions: $dims,
                ),
                default => new DeterministicMockEmbeddingProvider(dimensions: $dims),
            };
        });

        $this->app->singleton(VectorStoreInterface::class, function (): VectorStoreInterface {
            $driver = strtolower((string) config('ai.vector_driver', 'auto'));
            $dims = max(1, (int) config('ai.embedding_dimensions', 1536));

            if ($driver === 'json') {
                return new SqlServerJsonVectorStore;
            }

            return new SqlServerNativeVectorStore(dimensions: $dims);
        });
    }

    public function boot(): void
    {
        Gate::before(function (?User $user, string $ability): ?bool {
            if ($user === null) {
                return false;
            }

            /** @var TenantContextInterface $context */
            $context = app(TenantContextInterface::class);
            if (! $context->hasTenant()) {
                return null;
            }

            if (str_contains($ability, '.')) {
                return $context->hasPermission($ability);
            }

            return null;
        });

        RateLimiter::for('api', function (Request $request): Limit {
            $user = $request->user();
            $key = $user !== null ? (string) $user->id : (string) ($request->ip() ?? 'anonymous');

            return Limit::perMinute(120)->by($key);
        });

        RateLimiter::for('auth', function (Request $request): Limit {
            return Limit::perMinute(20)->by((string) ($request->ip() ?? 'anonymous'));
        });
    }
}
