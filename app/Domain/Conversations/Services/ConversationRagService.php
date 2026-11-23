<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Services;

use App\Domain\AI\Services\ModelGatewayService;
use App\Domain\Audit\Contracts\AuditLoggerInterface;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\Message;
use App\Domain\Conversations\Models\MessageCitation;
use App\Domain\Identity\Models\User;
use App\Domain\Retrieval\Services\CitationVerifier;
use App\Domain\Retrieval\Services\GroundedPromptBuilder;
use App\Domain\Retrieval\Services\PromptInjectionGuard;
use App\Domain\Retrieval\Services\RetrievalService;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ConversationRagService
{
    private const MIN_EVIDENCE_SCORE = 0.08;

    private const ABSTENTION_MESSAGE = 'I cannot provide a reliable answer because no authorized documents in the selected knowledge bases contain sufficient supporting evidence.';

    public function __construct(
        private readonly RetrievalService $retrievalService,
        private readonly PromptInjectionGuard $injectionGuard,
        private readonly GroundedPromptBuilder $promptBuilder,
        private readonly ModelGatewayService $modelGateway,
        private readonly CitationVerifier $citationVerifier,
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    /**
     * @param  list<string>|null  $knowledgeBaseIds
     */
    public function createConversation(
        Tenant $tenant,
        User $user,
        ?string $title = null,
        ?string $modelAlias = null,
        ?array $knowledgeBaseIds = null,
    ): Conversation {
        /** @var TenantSetting|null $settings */
        $settings = TenantSetting::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $defaultModel = $modelAlias ?? ($settings !== null ? $settings->default_chat_model : 'default-chat');

        $conversation = Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'title' => ($title !== null && trim($title) !== '') ? Str::limit(trim($title), 180, '') : 'New Conversation',
            'default_model_alias' => $defaultModel,
            'knowledge_base_ids_json' => $knowledgeBaseIds ?? [],
            'status' => 'active',
        ]);

        $this->auditLogger->record(
            action: 'conversations.created',
            resourceType: 'conversation',
            resourceId: $conversation->id,
            metadata: ['model' => $defaultModel],
            tenantId: $tenant->id,
            actorUserId: $user->id,
        );

        return $conversation;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listUserConversations(Tenant $tenant, User $user, ?string $search = null): array
    {
        $query = Conversation::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at');

        if ($search !== null && trim($search) !== '') {
            $query->where('title', 'like', '%'.trim($search).'%');
        }

        $conversations = $query->get();
        if ($conversations->isEmpty()) {
            return [];
        }

        $counts = DB::table('messages')
            ->select('conversation_id', DB::raw('COUNT(*) as cnt'))
            ->where('tenant_id', $tenant->id)
            ->whereIn('conversation_id', $conversations->pluck('id')->all())
            ->groupBy('conversation_id')
            ->pluck('cnt', 'conversation_id');

        $rows = [];
        foreach ($conversations as $conv) {
            $cnt = (int) ($counts[$conv->id] ?? $counts[strtoupper($conv->id)] ?? $counts[strtolower($conv->id)] ?? 0);
            $rows[] = $conv->toApiArray($cnt);
        }

        return $rows;
    }

    public function getOwnedConversationOrFail(Tenant $tenant, User $user, string $conversationId): Conversation
    {
        /** @var Conversation|null $conversation */
        $conversation = Conversation::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('id', $conversationId)
            ->where('status', 'active')
            ->first();

        if ($conversation === null) {
            abort(404, 'Conversation not found.');
        }

        if (strtolower($conversation->user_id) !== strtolower($user->id)) {
            $this->auditLogger->recordSecurityEvent(
                eventType: 'conversations.unauthorized_access',
                severity: 'high',
                summary: 'User attempted to access another user conversation inside tenant',
                details: ['conversation_id' => $conversation->id],
                tenantId: $tenant->id,
                actorUserId: $user->id,
            );
            abort(403, 'You do not have access to this conversation.');
        }

        return $conversation;
    }

    /**
     * Executes full grounded RAG turn (sync or streaming via optional $onDelta callback).
     *
     * @param  list<string>|null  $knowledgeBaseIds
     * @param  array{temperature?: float, include_citations?: bool, top_k?: int}  $options
     * @param  (callable(string): void)|null  $onDelta
     * @return array<string, mixed>
     */
    public function ask(
        Tenant $tenant,
        User $user,
        Conversation $conversation,
        string $question,
        ?array $knowledgeBaseIds = null,
        ?string $modelAlias = null,
        array $options = [],
        ?callable $onDelta = null,
    ): array {
        $resolvedModel = ($modelAlias !== null && trim($modelAlias) !== '')
            ? trim($modelAlias)
            : $conversation->default_model_alias;
        $includeCitations = (bool) ($options['include_citations'] ?? true);
        $temperature = (float) ($options['temperature'] ?? 0.2);
        $topK = max(1, min((int) ($options['top_k'] ?? 6), 15));

        $effectiveKbIds = ($knowledgeBaseIds !== null && $knowledgeBaseIds !== [])
            ? $knowledgeBaseIds
            : ($conversation->knowledge_base_ids_json ?: null);

        // 1. Inspect query for prompt-injection attempts (logged as security event)
        $this->injectionGuard->inspectUserQuery($question, $tenant->id, $user->id);

        // 2. Enforce tenant quota BEFORE spending any retrieval/LLM compute
        $this->modelGateway->enforceTenantQuota($tenant, $user, $resolvedModel, 'rag_chat');

        // 3. Pre-retrieval ACL-enforced vector search (aborts 403 on unauthorized/cross-tenant KB)
        $retrieval = $this->retrievalService->search(
            tenant: $tenant,
            user: $user,
            queryText: $question,
            knowledgeBaseIds: $effectiveKbIds,
            topK: $topK,
            minScore: self::MIN_EVIDENCE_SCORE,
        );

        // 4. Persist user message & auto-title new conversations
        $userMessage = Message::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $question,
            'model_alias' => $resolvedModel,
            'status' => 'completed',
        ]);

        if ($conversation->title === 'New Conversation') {
            $conversation->title = Str::limit(trim($question), 80, '...');
        }
        $conversation->last_message_at = now('UTC');
        $conversation->save();

        // 5. Safe Abstention when authorized documents have no supporting evidence
        if ($retrieval['results'] === []) {
            if ($onDelta !== null) {
                $onDelta(self::ABSTENTION_MESSAGE);
            }

            $assistantMsg = Message::query()->create([
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => self::ABSTENTION_MESSAGE,
                'model_alias' => $resolvedModel,
                'status' => 'abstained',
                'abstained' => true,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'cost_usd' => '0.000000',
                'latency_ms' => 1,
                'metadata_json' => [
                    'retrieval_adapter' => $retrieval['adapter'],
                    'authorized_document_count' => $retrieval['authorized_document_count'],
                ],
            ]);

            $this->modelGateway->recordUsage(
                tenant: $tenant,
                user: $user,
                modelRecord: $this->modelGateway->resolveModel($tenant, $resolvedModel, 'chat'),
                modelAlias: $resolvedModel,
                operationType: 'rag_chat',
                inputTokens: 0,
                outputTokens: 0,
                costUsd: '0.000000',
                latencyMs: 1,
                status: 'abstained',
                conversationId: $conversation->id,
                messageId: $assistantMsg->id,
            );

            return [
                'conversation_id' => $conversation->id,
                'user_message_id' => $userMessage->id,
                'message_id' => $assistantMsg->id,
                'answer' => $assistantMsg->content,
                'citations' => [],
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
                'cost_usd' => '0.000000',
                'latency_ms' => 1,
                'abstained' => true,
                'status' => 'abstained',
            ];
        }

        // 6. Gather bounded recent conversation history (excluding current user message)
        $recentHistory = Message::query()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->where('id', '!=', $userMessage->id)
            ->orderByDesc('created_at')
            ->limit(6)
            ->get()
            ->reverse()
            ->map(static fn (Message $m): array => [
                'role' => $m->role,
                'content' => $m->content,
            ])
            ->values()
            ->all();

        // 7. Assemble bounded, untrusted-fenced prompt & invoke Model Gateway
        $promptMessages = $this->promptBuilder->buildMessages(
            question: $question,
            recentHistory: $recentHistory,
            retrievedChunks: $retrieval['results'],
            tenantId: $tenant->id,
            actorUserId: $user->id,
            includeCitations: $includeCitations,
        );

        $llmResult = $this->modelGateway->executeChat(
            tenant: $tenant,
            user: $user,
            modelAlias: $resolvedModel,
            operationType: 'rag_chat',
            messages: $promptMessages,
            options: ['temperature' => $temperature, 'mode' => 'rag_chat'],
            onDelta: null,
            conversationId: $conversation->id,
        );

        // 8. Post-generation Citation Verification (NEVER return unverified or fabricated citations!)
        $verified = $this->citationVerifier->verifyAndNormalize(
            rawAnswer: $llmResult['content'],
            retrievedChunks: $retrieval['results'],
            tenantId: $tenant->id,
            actorUserId: $user->id,
            includeCitations: $includeCitations,
        );

        // Stream verified text deltas AFTER citation verification so fabricated citations can NEVER leak over SSE
        if ($onDelta !== null) {
            $pieces = preg_split('/(\s+)/', $verified['answer'], -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$verified['answer']];
            $buf = '';
            $n = 0;
            foreach ($pieces as $piece) {
                $buf .= $piece;
                $n++;
                if ($n >= 6) {
                    $onDelta($buf);
                    $buf = '';
                    $n = 0;
                }
            }
            if ($buf !== '') {
                $onDelta($buf);
            }
        }

        // 9. Persist assistant message & verified message_citations atomically
        /** @var Message $assistantMsg */
        $assistantMsg = DB::transaction(function () use (
            $tenant,
            $conversation,
            $resolvedModel,
            $llmResult,
            $verified,
            $retrieval,
        ): Message {
            $msg = Message::query()->create([
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $verified['answer'],
                'model_alias' => $resolvedModel,
                'status' => $verified['abstained'] ? 'abstained' : 'completed',
                'abstained' => $verified['abstained'],
                'input_tokens' => $llmResult['input_tokens'],
                'output_tokens' => $llmResult['output_tokens'],
                'cost_usd' => $llmResult['cost_usd'],
                'latency_ms' => $llmResult['latency_ms'],
                'metadata_json' => [
                    'retrieval_adapter' => $retrieval['adapter'],
                    'provider_key' => $llmResult['provider_key'],
                    'fabricated_citations_stripped' => $verified['fabricated_citations_stripped'],
                ],
            ]);

            foreach ($verified['citations'] as $cit) {
                MessageCitation::query()->create([
                    'tenant_id' => $tenant->id,
                    'message_id' => $msg->id,
                    'document_id' => $cit['document_id'],
                    'chunk_id' => $cit['chunk_id'],
                    'citation_index' => $cit['citation_index'],
                    'document_name' => $cit['document_name'],
                    'page_number' => $cit['page_number'],
                    'section_heading' => $cit['section_heading'],
                    'excerpt' => $cit['excerpt'],
                    'similarity_score' => number_format($cit['similarity_score'], 6, '.', ''),
                    'verified' => true,
                ]);
            }

            DB::table('ai_usage_records')
                ->where('id', $llmResult['usage_record_id'])
                ->update(['message_id' => $msg->id]);

            $conversation->forceFill(['last_message_at' => now('UTC')])->save();

            return $msg->refresh();
        });

        $serializedCitations = MessageCitation::query()
            ->where('tenant_id', $tenant->id)
            ->where('message_id', $assistantMsg->id)
            ->orderBy('citation_index')
            ->get()
            ->map(static fn (MessageCitation $c): array => $c->toApiArray())
            ->values()
            ->all();

        return [
            'conversation_id' => $conversation->id,
            'user_message_id' => $userMessage->id,
            'message_id' => $assistantMsg->id,
            'answer' => $assistantMsg->content,
            'citations' => $serializedCitations,
            'usage' => [
                'input_tokens' => $assistantMsg->input_tokens,
                'output_tokens' => $assistantMsg->output_tokens,
            ],
            'cost_usd' => number_format((float) $assistantMsg->cost_usd, 6, '.', ''),
            'latency_ms' => $assistantMsg->latency_ms,
            'abstained' => $assistantMsg->abstained,
            'status' => $assistantMsg->status,
        ];
    }

    public function recordFeedback(
        Tenant $tenant,
        User $user,
        string $messageId,
        string $feedback,
        ?string $note = null,
    ): Message {
        /** @var Message|null $message */
        $message = Message::withoutGlobalScopes()
            ->with('conversation')
            ->where('tenant_id', $tenant->id)
            ->where('id', $messageId)
            ->first();

        if ($message === null || $message->conversation === null) {
            abort(404, 'Message not found.');
        }

        if (strtolower($message->conversation->user_id) !== strtolower($user->id)) {
            abort(403, 'You cannot submit feedback on another user message.');
        }

        $message->forceFill([
            'feedback' => $feedback,
            'feedback_note' => $note !== null ? Str::limit(trim($note), 500, '') : null,
        ])->save();

        return $message->load('citations');
    }
}
