# NexaMind AI — Deployment & Rollback Operational Runbook

Both `ca-nexamind-api` and `ca-nexamind-web` run in Azure Container Apps with `activeRevisionsMode: 'Multiple'` and immutable `sha-<short>` container image tags, enabling near-instant traffic rollback without rebuilding containers.

---

## 1. Automated One-Click Rollback (GitHub Actions — Recommended)

### Backend API (`ca-nexamind-api`)
1. Open **Actions -> Rollback Backend Container App Revision (OIDC)** (`rollback.yml`) in `akintoyesodeinde/nexamind-ai-backend`.
2. Click **Run workflow**:
   - `environment`: `azure-dev`
   - `target_revision`: leave blank to automatically select the previous healthy revision (or paste an exact revision name from `az containerapp revision list`).
3. Approve the protected `azure-dev` environment gate.
4. The workflow authenticates via Microsoft Entra OIDC, activates the target revision, shifts **100% ingress traffic**, and verifies `GET https://<api-fqdn>/api/v1/health/ready` returns HTTP `200`.

### Frontend SPA (`ca-nexamind-web`)
1. Open **Actions -> Rollback Frontend Container App Revision (OIDC)** (`rollback.yml`) in `akintoyesodeinde/nexamind-ai-frontend`.
2. Click **Run workflow** -> approve `azure-dev` -> workflow shifts 100% traffic and verifies `GET https://<web-fqdn>/healthz` returns HTTP `200`.

---

## 2. Manual Azure CLI Rollback Drill (Emergency Fallback)

```bash
RG="rg-nexamind-dev"
APP="ca-nexamind-api"

# 1. List revisions with health state & traffic weight
az containerapp revision list \
  --resource-group "$RG" \
  --name "$APP" \
  --all \
  --output table

# 2. Shift 100% traffic to the previous healthy revision
PREV_REV="<paste-previous-healthy-revision-name>"
az containerapp revision activate --resource-group "$RG" --name "$APP" --revision "$PREV_REV"
az containerapp ingress traffic set --resource-group "$RG" --name "$APP" --revision-weight "${PREV_REV}=100"

# 3. Verify readiness
API_FQDN=$(az containerapp show --resource-group "$RG" --name "$APP" --query "properties.configuration.ingress.fqdn" -o tsv)
curl -fsS "https://${API_FQDN}/api/v1/health/ready"
```

---

## 3. Database Migration Safety Policy
- Follow **expand-and-contract** schema migrations: never drop or rename a column in the same release that removes application reads of that column, so rolling back a container revision never crashes against a mutated schema.
- Before applying schema changes in production/staging, verify the migration against a disposable SQL Server 2025 database (automatically enforced on every commit in `ci.yml`).

## 4. Incident Triage & Audit Integrity Verification
1. Inspect live logs in Azure Log Analytics (`ContainerAppConsoleLogs_CL`).
2. Check **Screen 17 (Security Audit Dashboard)** or call `GET /api/v1/audit-logs/verify-chain` to verify SHA-256 hash-chain integrity across tenant audit records.
3. Review high/critical alerts in `GET /api/v1/security-events?severity=critical`.
4. To rotate `APP_KEY`, SQL credentials, or AI provider API keys, update the `azure-dev` GitHub Environment secret and re-run `deploy.yml` (creates a fresh revision with updated Container Apps secret references).
