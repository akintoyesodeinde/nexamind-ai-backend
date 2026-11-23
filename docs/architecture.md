# NexaMind AI — System Architecture Reference

**Selected Architecture:** Option A — Enterprise Laravel Modular Monolith + React/TypeScript SPA  
**Database Engine:** Microsoft SQL Server 2025 (Local Docker) / Azure SQL Database (Native `VECTOR(1536)` GA)  
**Repositories:** Two strictly independent Git repositories (`nexamind-ai-backend` and `nexamind-ai-frontend`)

---

## 1. High-Level System Topology

```mermaid
flowchart LR
    User["Enterprise User (Browser)"] --> Web["ca-nexamind-web\nReact 19 + TS + Nginx SPA\nminReplicas: 0"]
    Web -->|"/api/v1 & /sanctum\nHttpOnly Cookies + X-Tenant-ID"| API["ca-nexamind-api\nLaravel 13 Modular Monolith (PHP 8.4)\nminReplicas: 0"]
    API --> SQL["Azure SQL Database (Serverless Free Offer)\nBusiness Tables + Native VECTOR(1536) + Outbox"]
    API --> Blob["Private Azure Blob Storage\nSAS Download Tokens (5-min TTL)"]
    API --> Gateway["Provider-Agnostic Model Gateway\nMock / OpenAI / Azure OpenAI / Gemini"]
    Worker["caj-nexamind-worker\nScheduled Container Apps Job (*/5 * * * *)\nqueue:work --stop-when-empty"] --> SQL
    Worker --> Blob
    Worker --> Gateway
    API --> Logs["Azure Log Analytics\n30-day retention, 1 GB/day cap"]
    Worker --> Logs
```

---

## 2. Backend Modular Monolith (`app/Domain/`)

Rather than incurring premature microservices overhead on an Azure Free Trial sandbox, the Laravel 13 backend enforces clean domain boundaries inside 11 explicit modules communicating through service interfaces and domain models:

| Module | Directory | Responsibility |
| :--- | :--- | :--- |
| **Identity** | `app/Domain/Identity/` | Users, Sanctum sessions/tokens, RBAC roles (`owner`, `admin`, `manager`, `member`, `viewer`) & 11 permissions |
| **Tenancy** | `app/Domain/Tenancy/` | Multi-tenant organizations, memberships, `TenantContext`, `BelongsToTenant` global scope, tenant AI/upload budgets |
| **Knowledge** | `app/Domain/Knowledge/` | Knowledge bases, visibility policies (`tenant`/`restricted`), `AuthorizedScopeResolver` |
| **Documents** | `app/Domain/Documents/` | Private Blob/Azurite adapter, magic-byte/zip-bomb upload validator, PDF/DOCX/TXT/MD/CSV extraction, semantic chunking, versioning, `IngestDocumentJob`, Document Intelligence |
| **Retrieval** | `app/Domain/Retrieval/` | Pre-retrieval ACL-filtered SQL Server `VECTOR_DISTANCE('cosine', ...)` native adapter + JSON fallback, `PromptInjectionGuard`, `GroundedPromptBuilder`, `CitationVerifier` |
| **AI** | `app/Domain/AI/` | Provider-agnostic `LlmProviderInterface` & `EmbeddingProviderInterface` (`Mock`, `OpenAI`, `Gemini`), `ModelGatewayService`, monthly token & `DECIMAL(18,6)` USD budget enforcement |
| **Conversations** | `app/Domain/Conversations/` | Tenant-scoped RAG chat threads, persisted verified citations, thumbs feedback, SSE streaming (`message.started`, `message.delta`, `message.completed`, `message.failed`) |
| **Agents** | `app/Domain/Agents/` | Hard-allowlisted `ToolRegistry` (5 safe tools), `JsonSchemaValidator`, bounded step `AgentOrchestratorService` (1–12 steps), full `agent_steps` audit trace |
| **Workflows** | `app/Domain/Workflows/` | Stateful multi-step workflow definitions & runs, flagship **AI Compliance Report Workflow**, atomic idempotent `ApprovalService`, deduplicated `outbox_events` |
| **Analytics** | `app/Domain/Analytics/` | Tenant usage/cost/latency aggregations, curated AI Evaluation Harness (`EvaluationService` & `nexamind:eval-run` CLI) |
| **Audit** | `app/Domain/Audit/` | Append-only SHA-256 hash-chained `audit_logs`, `security_events`, and live chain verification |

---

## 3. Grounded RAG & Citation Verification Pipeline

```mermaid
sequenceDiagram
    participant U as User
    participant API as ConversationRagService
    participant ACL as AuthorizedScopeResolver
    participant SQL as SQL Server 2025 / Azure SQL
    participant LLM as ModelGatewayService
    participant CV as CitationVerifier

    U->>API: Ask Question (X-Tenant-ID + Cookie)
    API->>API: PromptInjectionGuard::inspectUserQuery()
    API->>LLM: enforceTenantQuota() (Tokens & DECIMAL USD)
    API->>ACL: resolveAuthorizedSearchScope(tenant, user, kbIds)
    ACL-->>API: Authorized Document IDs (or 403 + security_event)
    API->>SQL: VECTOR_DISTANCE('cosine', embedding, CAST(? AS VECTOR(1536))) WHERE tenant_id = ? AND document_id IN (...)
    SQL-->>API: Top-K Authorized Chunks (>= 0.08 cosine)
    alt 0 Supporting Chunks
        API-->>U: Safe Abstention (0 citations, 0 LLM cost)
    else Supporting Chunks Found
        API->>API: Sanitize chunks & wrap in <untrusted_document_evidence>
        API->>LLM: executeChat() & record ai_usage_records
        LLM-->>API: Raw Answer with [chunk_id] citations
        API->>CV: verifyAndNormalize() against exact retrieved chunk IDs
        CV-->>API: Verified [n] Citations (fabricated refs stripped + logged)
        API-->>U: SSE Stream / JSON Answer + Verified Citations
    end
```

---

## 4. Database Design Principles (SQL Server 2025 & Azure SQL)

- **Primary Keys:** `UNIQUEIDENTIFIER DEFAULT NEWSEQUENTIALID()` across all domain tables to avoid page splits and prevent UUID enumeration.
- **UTC Timestamps:** `DATETIME2(3) DEFAULT SYSUTCDATETIME()` everywhere.
- **Financial Precision:** `DECIMAL(18,6)` for all per-token pricing, AI usage costs, and monthly cost ceilings (arithmetic computed via PHP `bcmath`).
- **Vector Portability:** `VectorStoreInterface` defaults to `SqlServerNativeVectorStore` (`VECTOR(1536)` + `VECTOR_DISTANCE('cosine', ...)`) and cleanly falls back to `SqlServerJsonVectorStore` when running against SQL Server engines lacking native vector types.
- **Idempotency & Concurrency:** `api_idempotency_keys` (route-scoped SHA-256 request hash caching), atomic `WHERE status = 'pending'` SQL updates on `approval_requests`, and filtered unique `dedup_key` index on `outbox_events`.
