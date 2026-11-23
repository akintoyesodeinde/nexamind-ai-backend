# NexaMind AI — AI Evaluation & Groundedness Methodology

NexaMind AI treats Generative AI output quality as a measurable engineering discipline rather than an informal demo. The built-in evaluation harness (`app/Domain/Analytics/Services/EvaluationService.php`) runs every test case through the exact same ACL-enforced retrieval, prompt-injection sanitization, model gateway, and `CitationVerifier` pipeline used in production chat.

---

## 1. Core Quality Metrics

| Metric | Target | Definition |
| :--- | :--- | :--- |
| **Citation Correctness %** | **>= 95%** | Percentage of non-abstention cases returning non-empty verified citations where every cited chunk ID exists in that request's exact authorized retrieved set and `fabricated_citations_stripped == 0`. |
| **Groundedness Score %** | **>= 95% (explicitly scored)** | Average fraction of expected policy terms/facts substantiated directly by the normalized response and verified source chunk excerpts. |
| **Abstention Accuracy %** | **100%** | Percentage of out-of-scope control questions where the engine cleanly abstains (`abstained = true`) and emits `0` citations instead of hallucinating. |
| **Latency & Cost** | Tracked per run | Average end-to-end retrieval + synthesis latency (`ms`) and exact `DECIMAL(18,6)` total cost (`USD`). |

---

## 2. Running Evaluations

### Via Enterprise UI (Screen 14 — `/evaluations`)
1. Open **AI Evaluations** (`/evaluations`) with `analytics.view` permission.
2. Click **Seed Curated Benchmark** to provision the *Enterprise Policy & Abstention Benchmark* dataset.
3. Click **Run Benchmark** and inspect the scorecard (`>= 95% Target Met` badge) and per-case pass/fail diffs.

### Via Artisan CLI (CI / Automated Regression Checks)
```bash
docker compose exec backend php artisan nexamind:eval-run --tenant=<tenant-uuid-or-slug>
```

### Via REST API
```http
POST /api/v1/evaluations/datasets/seed-benchmark
POST /api/v1/evaluations/runs
GET  /api/v1/evaluations/runs/{runId}
```

---

## 3. Automated Regression Gate
`tests/Feature/AnalyticsEvaluationAndAuditTest.php` runs on every commit and pull request against live SQL Server 2025 + Azurite, asserting `citation_correctness_pct >= 95.0`, `groundedness_score_pct >= 95.0`, `abstention_accuracy_pct == 100.0`, and clean `nexamind:eval-run` CLI execution.
