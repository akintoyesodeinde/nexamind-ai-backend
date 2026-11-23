# NexaMind AI — Azure Free Trial Sandbox Deployment & Entra OIDC Guide

This guide deploys NexaMind AI into a single Azure Free Trial resource group (`rg-nexamind-dev`) using modular Bicep IaC and **keyless Microsoft Entra ID OpenID Connect (OIDC)** from both independent GitHub repositories.

> **Important:** No live Azure resources are provisioned automatically during local development. Always run `az deployment group what-if` and review expected resource costs before executing `az deployment group create`.

---

## 1. Target Azure Sandbox Architecture (`rg-nexamind-dev`)

| Resource | Name Pattern | SKU / Cost-Control Profile |
| :--- | :--- | :--- |
| Resource Group | `rg-nexamind-dev` | Single sandbox RG for instant teardown |
| User-Assigned Identity | `id-nexamind-dev` | `AcrPull` + `Storage Blob Data Contributor` |
| Log Analytics | `log-nexamind-dev-<suffix>` | `PerGB2018`, 30-day retention, 1 GB/day cap |
| Container Registry | `acrnexaminddev<suffix>` | `Basic`, admin user disabled |
| Blob Storage | `stnexaminddev<suffix>` | `Standard_LRS`, private container `nexamind-documents`, TLS 1.2+ |
| Azure SQL Database | `sql-nexamind-dev-<suffix>/nexamind` | Serverless `GP_S_Gen5` (2 vCore max, 0.5 min), **Free Offer (`useFreeLimit: true`, `AutoPause`)**, 32 GB cap, native `VECTOR` GA |
| Container Apps Env | `cae-nexamind-dev` | Consumption workload profile |
| Backend API | `ca-nexamind-api` | 0.5 vCPU / 1 GiB, **`minReplicas: 0`**, `maxReplicas: 2`, Multiple revisions |
| Frontend SPA | `ca-nexamind-web` | 0.25 vCPU / 0.5 GiB, **`minReplicas: 0`**, `maxReplicas: 2`, Multiple revisions |
| Queue Worker Job | `caj-nexamind-worker` | Scheduled Job (`*/5 * * * *`), `queue:work --stop-when-empty` |
| Consumption Budget | `budget-nexamind-dev` | Monthly alerts at 50%, 80%, 100% Actual & Forecasted |

---

## 2. One-Time Azure Resource Group & Entra OIDC Setup

```bash
# 1. Authenticate locally to your Azure Free Trial subscription
az login
SUBSCRIPTION_ID=$(az account show --query id -o tsv)
TENANT_ID=$(az account show --query tenantId -o tsv)
LOCATION="eastus2"
RG="rg-nexamind-dev"

# 2. Create single sandbox Resource Group
az group create --name "$RG" --location "$LOCATION"

# 3. Create Microsoft Entra ID App Registration & Service Principal
APP_ID=$(az ad app create --display-name "github-nexamind-oidc-dev" --query appId -o tsv)
az ad sp create --id "$APP_ID"

# 4. Grant Contributor + User Access Administrator scoped ONLY to rg-nexamind-dev
az role assignment create \
  --assignee "$APP_ID" \
  --role "Contributor" \
  --scope "/subscriptions/${SUBSCRIPTION_ID}/resourceGroups/${RG}"

az role assignment create \
  --assignee "$APP_ID" \
  --role "User Access Administrator" \
  --scope "/subscriptions/${SUBSCRIPTION_ID}/resourceGroups/${RG}"

# 5. Add Federated OIDC Credentials for BOTH independent GitHub repositories
az ad app federated-credential create --id "$APP_ID" --parameters '{
  "name": "nexamind-backend-azure-dev",
  "issuer": "https://token.actions.githubusercontent.com",
  "subject": "repo:akintoyesodeinde/nexamind-ai-backend:environment:azure-dev",
  "audiences": ["api://AzureADTokenExchange"]
}'

az ad app federated-credential create --id "$APP_ID" --parameters '{
  "name": "nexamind-frontend-azure-dev",
  "issuer": "https://token.actions.githubusercontent.com",
  "subject": "repo:akintoyesodeinde/nexamind-ai-frontend:environment:azure-dev",
  "audiences": ["api://AzureADTokenExchange"]
}'

echo "AZURE_CLIENT_ID=$APP_ID"
echo "AZURE_TENANT_ID=$TENANT_ID"
echo "AZURE_SUBSCRIPTION_ID=$SUBSCRIPTION_ID"
```

---

## 3. Configure Protected GitHub Environments (`azure-dev`)

In **both** `akintoyesodeinde/nexamind-ai-backend` and `akintoyesodeinde/nexamind-ai-frontend`:
1. Go to **Settings -> Environments -> New environment -> `azure-dev`**.
2. Enable **Required reviewers** (add yourself) so no cloud deployment executes without explicit approval.
3. Configure **Environment Secrets**:
   - `AZURE_CLIENT_ID` (from Step 2)
   - `AZURE_TENANT_ID` (from Step 2)
   - `AZURE_SUBSCRIPTION_ID` (from Step 2)
   - *(Backend repo only)* `NEXAMIND_SQL_ADMIN_PASSWORD` (strong SQL password)
   - *(Backend repo only)* `NEXAMIND_APP_KEY` (`php artisan key:generate --show`)
4. Configure **Environment Variables**:
   - `AZURE_RESOURCE_GROUP` = `rg-nexamind-dev`
   - `AZURE_ACR_NAME` = `<acrName output from initial Bicep preview/deploy>`
   - *(Frontend repo only)* `VITE_API_BASE_URL` = `https://<ca-nexamind-api-fqdn>/api/v1`

---

## 4. Preview Infrastructure Changes (`what-if`) Before Provisioning

```bash
export NEXAMIND_SQL_ADMIN_PASSWORD="YourStrong_AzureSQL_Pass2026!"
export NEXAMIND_APP_KEY="base64:YOUR_GENERATED_LARAVEL_KEY"

az deployment group what-if \
  --resource-group rg-nexamind-dev \
  --template-file infrastructure/azure/main.bicep \
  --parameters infrastructure/azure/parameters.dev.bicepparam
```

Review the `what-if` output carefully. Once approved, run either:
- **GitHub Actions (Recommended):** Trigger **Deploy Backend to Azure Container Apps (OIDC)** (`deploy.yml`) with `run_what_if_only=false`, approve the `azure-dev` environment gate, then trigger **Deploy Frontend to Azure Container Apps (OIDC)** (`deploy.yml`).
- **One-Click Teardown (Zero Residual Spend):**
  ```bash
  az group delete --name rg-nexamind-dev --yes --no-wait
  ```
