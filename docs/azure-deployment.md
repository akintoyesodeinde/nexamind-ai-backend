# NexaMind AI — Azure Free Trial Linux VM Deployment & Entra OIDC Reference

NexaMind AI deploys to a single low-cost Azure Free Trial resource group (`rg-nexamind-dev`) using modular Bicep, `cloud-init`, Docker Compose, Docker Hub, Azure SQL Database Free Offer, and keyless **Microsoft Entra ID OIDC -> Azure VM Run Command** deployments.

For the full 29-step beginner walkthrough from Windows PowerShell on your laptop (zero Azure Cloud Shell required), see **[docs/windows-powershell-azure-guide.md](windows-powershell-azure-guide.md)**.

---

## 1. Resource Inventory (`infrastructure/azure/main.bicep`)

| Module | Resources Provisioned | Key Security & Cost Guardrails |
| :--- | :--- | :--- |
| `modules/identity.bicep` | `id-nexamind-dev` | User-Assigned Managed Identity (zero stored Azure credentials on VM) |
| `modules/nsg.bicep` | `nsg-nexamind-dev` | Allows 443/80 public, locks TCP 22 to your laptop `/32` CIDR only, explicitly denies inbound TCP 1433 |
| `modules/network.bicep` | `vnet-nexamind-dev`, `snet-nexamind-app`, `pip-nexamind-dev` | Static Standard Public IPv4 (`~$3.65/mo`; billed even when VM stopped) |
| `modules/vm.bicep` | `vm-nexamind-dev`, `nic-nexamind-dev`, 64 GiB OS disk | Ubuntu 24.04 LTS, free-eligible `Standard_B1s` / `Standard_B2ats_v2`, SSH Ed25519 key ONLY (`disablePasswordAuthentication: true`), `cloud-init` via `customData` (zero secrets) |
| `modules/sql.bicep` *(optional, default `true`)* | `sql-nexamind-dev-<suffix>/nexamind` | Azure SQL Free Offer Serverless (`GP_S_Gen5`, `useFreeLimit: true`, `AutoPause`, 32 GB cap, TLS 1.2+), firewall pinned strictly to VM static IP |
| `modules/storage.bicep` *(optional, default `true`)* | `stnexaminddev<suffix>` | `Standard_LRS`, `allowBlobPublicAccess: false`, TLS 1.2+, Managed Identity `Storage Blob Data Contributor` |
| `modules/vmss.bicep` *(optional, default **`false`**)* | `vmss-nexamind-dev` | Flexible-orchestration VM Scale Set + `cloud-init` (future scaling option; NOT deployed initially to keep cost minimal) |
| `modules/monitoring.bicep` | `log-nexamind-dev-<suffix>`, `budget-nexamind-dev` | 30-day retention, 1 GB/day cap, Consumption Budget alerts at 50%, 80%, 100% |

---

## 2. Single VM vs Azure VM Scale Sets (`modules/vmss.bicep`)

| Dimension | Single Linux VM (Default Sandbox) | Flexible VM Scale Set (`deployVmss = true`) |
| :--- | :--- | :--- |
| **Scaling** | Vertical resize (`B1s` -> `B1ms`) | Automatic horizontal scale-out/scale-in across multiple VMs |
| **Networking** | Direct Static Public IPv4 (~$3.65/mo) | Requires Azure Standard Load Balancer (~$18+/mo) or App Gateway |
| **Availability** | Single instance with systemd + Docker restart | Multi-instance / Availability Zone fault tolerance |
| **Free Trial Fit** | Fits 750h/mo 12-month free allowance (~$3.65–$4/mo total) | 2+ instances exhaust 750h/mo in <= 15 days + LB charges |

---

## 3. Secure Azure SQL Networking & Private Endpoint Tradeoff
- **Default Sandbox Configuration:** Azure SQL firewall allows **ONLY** the VM's static Public IP address over mandatory TLS 1.2+ (`DB_ENCRYPT=yes`, `DB_TRUST_SERVER_CERTIFICATE=false`), and runtime queries use the least-privilege `nexamind_app` SQL user ([docker/sqlserver/setup-app-user.sql](../docker/sqlserver/setup-app-user.sql)), never the server admin login. TCP `1433` is never exposed to `0.0.0.0/0` or opened inbound on the VM.
- **Why Private Endpoint is Optional for Sandbox:** An Azure Private Endpoint + Private DNS Zone adds ~$7.30+/month in fixed hourly charges plus data processing fees. For production workloads beyond the Free Trial sandbox, attach a Private Endpoint to `snet-nexamind-app` and set `publicNetworkAccess: 'Disabled'` on `sql.bicep`.
