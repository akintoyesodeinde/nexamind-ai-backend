# NexaMind AI — Cloud & Generative AI Cost Control Guide

NexaMind AI enforces **two layers of cost guardrails**: cloud infrastructure cost controls (tuned for the Azure Free Trial sandbox) and application-level per-tenant Generative AI token/USD ceilings.

---

## 1. Azure Infrastructure Cost Guardrails (Bicep-Enforced)

| Control | Implementation in Bicep | Impact |
| :--- | :--- | :--- |
| **Azure SQL Free Offer** | `useFreeLimit: true`, `freeLimitExhaustionBehavior: 'AutoPause'`, `maxSizeBytes: 32 GB` in `sql-database.bicep` | First 100,000 vCore seconds/month + 32 GB storage are free; auto-pauses cleanly rather than accruing surprise bills. |
| **Serverless Auto-Pause** | `GP_S_Gen5`, `minCapacity: 0.5`, `autoPauseDelay: 60` minutes | Compute pauses automatically after 1 hour of inactivity. |
| **Scale-to-Zero Compute** | `minReplicas: 0`, `maxReplicas: 2` on `ca-nexamind-api` and `ca-nexamind-web` | Zero compute billing when the portfolio demo is idle; Consumption tier includes free monthly vCPU-s & request grants. |
| **Scheduled Queue Worker Job** | `caj-nexamind-worker` (`Microsoft.App/jobs`, cron `*/5 * * * *`) running `queue:work --stop-when-empty` | Eliminates the #1 Container Apps cost trap (a continuous `queue:work` daemon preventing scale-to-zero). |
| **Capped Observability** | Log Analytics `retentionInDays: 30`, `dailyQuotaGb: 1` | Stays comfortably within the 5 GB/month free Log Analytics ingestion grant. |
| **Automated Budget Alerts** | `Microsoft.Consumption/budgets` (`budget.bicep`) at **50%, 80%, 100% Actual & Forecasted** | Immediate email alerts before sandbox spend reaches the monthly ceiling (default `$25`). |
| **Single Resource Group** | Everything lives inside `rg-nexamind-dev` | Complete cleanup in one command (`az group delete --name rg-nexamind-dev`). |

---

## 2. Generative AI Token & Fixed-Precision Cost Controls

1. **Zero-Spend Local & CI Mode (`AI_DEFAULT_PROVIDER=mock`):**
   - `DeterministicMockEmbeddingProvider` (1536-d L2-normalized vectors) and `DeterministicMockLlmProvider` allow full local development, all 24 PHPUnit tests, and all GitHub Actions CI runs with **$0.00 external LLM API spend**.
2. **Per-Tenant Monthly Budgets (`tenant_settings` + `ModelGatewayService`):**
   - Every tenant defines `monthly_token_budget` (default `500,000`) and `monthly_cost_budget_usd` (`DECIMAL(18,6)`, default `$25.000000`).
   - Before every RAG, Document Intelligence, Agent, or Evaluation call, `ModelGatewayService::enforceTenantQuota()` sums month-to-date `ai_usage_records` using exact `bcmath` arithmetic.
   - Exceeding either limit immediately blocks execution with HTTP `429 QUOTA_EXCEEDED` and records an `ai.quota_exceeded` security event.
3. **Bounded Context & Agent Step Caps:**
   - `GroundedPromptBuilder` caps injected context at `12,000` characters and 6 conversation turns.
   - `AgentOrchestratorService` enforces hard per-agent caps on `max_steps` (1–12) and `max_tokens_per_run`.
4. **Live Visibility:**
   - Screen 13 (**AI Usage & Cost Analytics**, `/analytics/usage`) displays live monthly token/cost progress gauges, daily consumption bars, model breakdowns, and top consumers.
