<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Conversations\Models\Message;
use App\Domain\Conversations\Services\ConversationRagService;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Contracts\TenantContextInterface;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class ConversationController extends Controller
{
    public function __construct(
        private readonly ConversationRagService $ragService,
        private readonly TenantContextInterface $tenantContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $search = $request->query('search');

        return ApiResponse::success(
            $this->ragService->listUserConversations($tenant, $user, is_string($search) ? $search : null),
            200,
            $request,
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'model' => ['nullable', 'string', 'max:100'],
            'knowledge_base_ids' => ['sometimes', 'array'],
            'knowledge_base_ids.*' => ['uuid'],
        ]);

        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        /** @var list<string>|null $kbIds */
        $kbIds = isset($validated['knowledge_base_ids']) ? array_values($validated['knowledge_base_ids']) : null;

        $conversation = $this->ragService->createConversation(
            tenant: $tenant,
            user: $user,
            title: isset($validated['title']) ? (string) $validated['title'] : null,
            modelAlias: isset($validated['model']) ? (string) $validated['model'] : null,
            knowledgeBaseIds: $kbIds,
        );

        return ApiResponse::success($conversation->toApiArray(0), 201, $request);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return ApiResponse::error('NOT_FOUND', 'Conversation not found.', 404, null, $request);
        }

        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $conversation = $this->ragService->getOwnedConversationOrFail($tenant, $user, $id);
        $messages = $conversation->messages()
            ->with('citations')
            ->get()
            ->map(static fn (Message $m): array => $m->toApiArray())
            ->values()
            ->all();

        return ApiResponse::success([
            'conversation' => $conversation->toApiArray(count($messages)),
            'messages' => $messages,
        ], 200, $request);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return ApiResponse::error('NOT_FOUND', 'Conversation not found.', 404, null, $request);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'min:1', 'max:200'],
        ]);

        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $conversation = $this->ragService->getOwnedConversationOrFail($tenant, $user, $id);
        $conversation->forceFill(['title' => trim((string) $validated['title'])])->save();

        return ApiResponse::success($conversation->toApiArray(), 200, $request);
    }

    public function destroy(Request $request, string $id): Response|JsonResponse
    {
        if (! Str::isUuid($id)) {
            return ApiResponse::error('NOT_FOUND', 'Conversation not found.', 404, null, $request);
        }

        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $conversation = $this->ragService->getOwnedConversationOrFail($tenant, $user, $id);
        $conversation->forceFill(['status' => 'archived'])->save();

        return response()->noContent();
    }

    public function storeMessage(Request $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return ApiResponse::error('NOT_FOUND', 'Conversation not found.', 404, null, $request);
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:4000'],
            'knowledge_base_ids' => ['sometimes', 'array'],
            'knowledge_base_ids.*' => ['uuid'],
            'model' => ['sometimes', 'nullable', 'string', 'max:100'],
            'options' => ['sometimes', 'array'],
            'options.temperature' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'options.include_citations' => ['sometimes', 'boolean'],
            'options.top_k' => ['sometimes', 'integer', 'min:1', 'max:15'],
        ]);

        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $conversation = $this->ragService->getOwnedConversationOrFail($tenant, $user, $id);

        /** @var list<string>|null $kbIds */
        $kbIds = isset($validated['knowledge_base_ids']) ? array_values($validated['knowledge_base_ids']) : null;
        /** @var array{temperature?: float, include_citations?: bool, top_k?: int} $opts */
        $opts = isset($validated['options']) && is_array($validated['options']) ? $validated['options'] : [];

        $result = $this->ragService->ask(
            tenant: $tenant,
            user: $user,
            conversation: $conversation,
            question: (string) $validated['message'],
            knowledgeBaseIds: $kbIds,
            modelAlias: isset($validated['model']) ? (string) $validated['model'] : null,
            options: $opts,
        );

        return ApiResponse::success($result, 201, $request);
    }

    /**
     * Server-Sent Events (SSE) grounded AI response stream.
     * Emits typed events: message.started, message.delta, message.completed, message.failed.
     */
    public function stream(Request $request, string $id): StreamedResponse|JsonResponse
    {
        if (! Str::isUuid($id)) {
            return ApiResponse::error('NOT_FOUND', 'Conversation not found.', 404, null, $request);
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:4000'],
            'knowledge_base_ids' => ['sometimes', 'array'],
            'knowledge_base_ids.*' => ['uuid'],
            'model' => ['sometimes', 'nullable', 'string', 'max:100'],
            'temperature' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'include_citations' => ['sometimes', 'boolean'],
        ]);

        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $conversation = $this->ragService->getOwnedConversationOrFail($tenant, $user, $id);

        /** @var list<string>|null $kbIds */
        $kbIds = isset($validated['knowledge_base_ids']) ? array_values($validated['knowledge_base_ids']) : null;
        $question = (string) $validated['message'];
        $modelAlias = isset($validated['model']) ? (string) $validated['model'] : $conversation->default_model_alias;
        $options = [
            'temperature' => isset($validated['temperature']) ? (float) $validated['temperature'] : 0.2,
            'include_citations' => array_key_exists('include_citations', $validated)
                ? (bool) $validated['include_citations']
                : true,
        ];

        $emitEvent = static function (string $eventName, array $payload): void {
            echo "event: {$eventName}\n";
            echo 'data: '.json_encode($payload, JSON_THROW_ON_ERROR)."\n\n";
            if (function_exists('ob_flush') && ob_get_level() > 0) {
                @ob_flush();
            }
            flush();
        };

        return response()->stream(function () use (
            $tenant,
            $user,
            $conversation,
            $question,
            $kbIds,
            $modelAlias,
            $options,
            $emitEvent,
        ): void {
            $emitEvent('message.started', [
                'conversation_id' => $conversation->id,
                'model' => $modelAlias,
                'timestamp' => now('UTC')->toISOString(),
            ]);

            try {
                $result = $this->ragService->ask(
                    tenant: $tenant,
                    user: $user,
                    conversation: $conversation,
                    question: $question,
                    knowledgeBaseIds: $kbIds,
                    modelAlias: $modelAlias,
                    options: $options,
                    onDelta: static function (string $delta) use ($emitEvent): void {
                        $emitEvent('message.delta', ['delta' => $delta]);
                    },
                );

                $emitEvent('message.completed', $result);
            } catch (Throwable $e) {
                $emitEvent('message.failed', [
                    'code' => 'STREAM_FAILED',
                    'message' => $e->getMessage(),
                ]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function feedback(Request $request, string $messageId): JsonResponse
    {
        if (! Str::isUuid($messageId)) {
            return ApiResponse::error('NOT_FOUND', 'Message not found.', 404, null, $request);
        }

        $validated = $request->validate([
            'feedback' => ['required', 'string', 'in:positive,negative'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $updated = $this->ragService->recordFeedback(
            tenant: $tenant,
            user: $user,
            messageId: $messageId,
            feedback: (string) $validated['feedback'],
            note: isset($validated['note']) ? (string) $validated['note'] : null,
        );

        return ApiResponse::success($updated->toApiArray(), 200, $request);
    }
}
