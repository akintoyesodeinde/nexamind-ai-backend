# NexaMind AI — Backend (`nexamind-ai-backend`)

Enterprise Generative AI Knowledge & Workflow Automation Platform REST API built as a **Laravel Modular Monolith** on **PHP 8.4+** with **Microsoft SQL Server / Azure SQL**, **Laravel Sanctum**, **RBAC**, and **Azure Blob Storage**.

## Domain Modules (`app/Domain/`)
1. `Identity` — Users, Sanctum sessions/tokens, RBAC roles & permissions
2. `Tenancy` — Organizations, memberships, tenant settings, mandatory tenant context scoping
3. `Knowledge` — Knowledge bases & ACL permissions (Phase 2)
4. `Documents` — Secure Blob uploads, text extraction, chunking, versioning (Phase 2)
5. `Conversations` — Grounded AI chat history & SSE streaming (Phase 3)
6. `AI` — Provider-agnostic LLM & embedding gateway, budgets, quotas (Phase 3)
7. `Retrieval` — Portable SQL Server / Azure SQL vector search + pre-retrieval ACL filters (Phase 2–3)
8. `Agents` — Allowlisted tool-calling agents & execution state machine (Phase 4)
9. `Workflows` — Multi-step approval workflows, outbox, idempotency (Phase 4)
10. `Analytics` — Usage/cost metrics & groundedness evaluation harness (Phase 4)
11. `Audit` — Hash-chained tamper-evident `audit_logs` & `security_events`

## Local Docker Profiles (8 GB RAM Friendly)
See [docs/local-development.md](docs/local-development.md) for detailed instructions on:
- **`minimal` (default for 8 GB laptops):** SQL Server 2025 (2048 MB cap) + Azurite + Laravel API; run queue jobs on demand.
- **`cloud-db` (lowest local RAM):** Connect local Laravel container to Azure SQL Free Tier (skips local SQL Server container).
- **`full`:** Adds dedicated continuous Laravel queue worker container.

```bash
docker compose --profile minimal up -d --build
docker compose exec backend php artisan nexamind:probe-sqlserver
docker compose exec backend php artisan migrate --seed
docker compose exec backend php artisan test
```
