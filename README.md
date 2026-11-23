# NexaMind AI — Backend (`nexamind-ai-backend`)

Enterprise Generative AI Knowledge & Workflow Automation Platform REST API built as a **Laravel 13 Modular Monolith** on **PHP 8.4+** with **Microsoft SQL Server 2025 / Azure SQL (native `VECTOR(1536)`)**, **Laravel Sanctum**, **RBAC + pre-retrieval resource ACLs**, **private Azure Blob Storage / Azurite**, and **Azure Free Trial Bicep IaC**.

> **Independent Repository Architecture:** `nexamind-ai-backend` and [`nexamind-ai-frontend`](https://github.com/akintoyesodeinde/nexamind-ai-frontend) are maintained as two completely independent Git repositories with independent CI/CD pipelines.

---

## Domain Modules (`app/Domain/`)
1. **Identity** — Users, Sanctum cookie/token auth, 5 RBAC roles & 11 least-privilege permissions
2. **Tenancy** — Multi-tenant orgs, memberships, `TenantContext`, `BelongsToTenant`, tenant AI/upload budgets
3. **Knowledge** — Knowledge bases, visibility policies, pre-retrieval `AuthorizedScopeResolver`
4. **Documents** — Private Azure Blob/Azurite SAS storage, magic-byte/zip-bomb validator, PDF/DOCX/TXT/MD/CSV extraction, semantic chunking, versioning, `IngestDocumentJob`, Document Intelligence (`summarize`, `classify`, `extract_structured`, `compare`)
5. **Retrieval** — SQL Server 2025 / Azure SQL native `VECTOR_DISTANCE('cosine', ...)` + JSON fallback, `PromptInjectionGuard`, `GroundedPromptBuilder`, `CitationVerifier`
6. **AI** — Provider-agnostic gateway (`Mock`, `OpenAI`, `Gemini`), monthly token & `DECIMAL(18,6)` USD budget enforcement (`429 QUOTA_EXCEEDED`)
7. **Conversations** — Grounded RAG threads, persisted verified citations, thumbs feedback, SSE streaming (`message.started`, `message.delta`, `message.completed`, `message.failed`)
8. **Agents** — Hard-allowlisted `ToolRegistry` (5 safe tools, zero SQL/shell/HTTP tools), `JsonSchemaValidator`, bounded step `AgentOrchestratorService`
9. **Workflows** — Flagship **AI Compliance Report Workflow**, atomic idempotent `ApprovalService`, `api_idempotency_keys`, deduplicated `outbox_events`
10. **Analytics** — Usage/cost/latency aggregations & AI Evaluation Harness (`EvaluationService`, `php artisan nexamind:eval-run`)
11. **Audit** — Append-only SHA-256 hash-chained `audit_logs`, `security_events`, and chain integrity verifier

---

## Local Docker Quickstart (8 GB RAM Friendly)

```bash
cp .env.example .env
docker compose --profile minimal up -d --build
docker compose exec backend php artisan nexamind:probe-sqlserver
docker compose exec backend php artisan migrate --seed
docker compose exec backend ./vendor/bin/pint --test
docker compose exec backend ./vendor/bin/phpstan analyse --memory-limit=256M
docker compose exec backend ./vendor/bin/phpunit --testdox
```

---

## CI/CD & Azure Free Trial Infrastructure
- **CI (`.github/workflows/ci.yml`):** PHP 8.4 + ODBC 18 + `pdo_sqlsrv`, live SQL Server 2025 + Azurite containers, `composer audit`, Laravel Pint, Larastan Level 5, native `VECTOR(1536)` probe, migrations/seeders, 24 automated tests (Unit/Feature/Integration/Security), production Docker build + Trivy CRITICAL scan, and Azure Bicep lint/build validation.
- **CD & Rollback (`.github/workflows/deploy.yml`, `.github/workflows/rollback.yml`):** Keyless Microsoft Entra OIDC (`azure/login@v2`), protected `azure-dev` environment approval, Bicep `what-if`, immutable `sha-` ACR tags, scale-to-zero Container Apps + scheduled worker job, and instant revision rollback.

---

## Documentation Index
- [OpenAPI 3.1 Specification](docs/openapi.yaml)
- [System Architecture Reference](docs/architecture.md)
- [Security Architecture & Controls](docs/security.md)
- [Local Development Guide (8 GB RAM & Cloud-DB Profiles)](docs/local-development.md)
- [Azure Free Trial Deployment & Entra OIDC Setup](docs/azure-deployment.md)
- [Cloud & AI Cost Control Guide](docs/cost-control.md)
- [AI Evaluation & Groundedness Methodology](docs/ai-evaluation.md)
- [Deployment & Rollback Runbook](docs/runbook-rollback.md)
