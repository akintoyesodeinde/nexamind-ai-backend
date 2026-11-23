# NexaMind AI — Azure Free Trial & Generative AI Cost Control Guide

NexaMind AI enforces strict cost controls across both Azure infrastructure and Generative AI inference so sandbox experimentation remains predictable and transparent.

---

## 1. Honest Azure Free Trial Cost Breakdown (Never Assume Everything Is Free)

| Component | Configuration | Free Allowance / Pricing Reality | Projected Monthly Cost |
| :--- | :--- | :--- | :--- |
| **Linux VM** | 1x `Standard_B1s` or `Standard_B2ats_v2` (Ubuntu 24.04 LTS) | 750 hrs/mo free for 12 months on qualifying Azure Free Account (1 VM 24/7 = 730–744 hrs). Running 2 VMs exceeds 750 hrs. | **$0.00** (or ~$7.59–$10/mo outside free allowance) |
| **OS Disk** | 64 GiB `Standard_LRS` | Within 2x 64 GiB 12-month free managed disk allowance | **$0.00** |
| **Static Public IPv4** | 1x Standard Regional IPv4 | `$0.005/hr` (~`$3.65/mo`) — **charged even when VM is deallocated!** Covered by `$200` 30-day credit. | **~$3.65/mo** |
| **Azure SQL Database** | Serverless `GP_S_Gen5`, `useFreeLimit: true`, `AutoPause` | Permanent Free Offer: 100,000 vCore-sec/mo + 32 GB data + 32 GB backup; auto-pauses when limit reached | **$0.00/mo** |
| **Private Blob Storage** | `Standard_LRS` (< 5 GB) | Optional (`deployStorage = true/false`); pennies per GB | **~$0.02–$0.20/mo** |
| **Log Analytics + Budgets** | 30-day retention, 1 GB/day cap | First 5 GB/mo free ingestion grant; Budget alerts free | **$0.00/mo** |
| **Docker Hub** | 2 repositories | Free for public repos (Personal Free allows only 1 private repo) | **$0.00/mo** (public) |
| **Optional VM Scale Set** | `deployVmss = false` | Disabled by default (avoids ~$18+/mo Standard Load Balancer + multi-VM hours) | **$0.00/mo** |
| **Total Sandbox Estimate** | Single VM + Azure SQL Free Offer | Covered by `$200` 30-day trial credit; tear down `rg-nexamind-dev` to return to `$0.00` | **~$3.65–$4.00/mo** |

---

## 2. 1 GiB VM Memory Discipline (`Standard_B1s` / `Standard_B2ats_v2`)
- SQL Server is **never** hosted inside the 1 GiB VM (runs externally on Azure SQL Free Offer).
- Docker images are **built in GitHub Actions** and pulled prebuilt from Docker Hub.
- `cloud-init` configures a **2 GiB swapfile** (`vm.swappiness=20`).
- `docker/compose.prod.yaml` caps container RAM (`backend: 384m`, `frontend: 64m`, `proxy: 64m`) and limits JSON log sizes (`10m x 3`).
- Laravel queue jobs drain via a **systemd timer (`nexamind-worker.timer`)** running `queue:work --stop-when-empty` every 2 minutes instead of holding resident RAM.

## 3. Generative AI Cost Controls
- **Zero-Spend Testing (`AI_DEFAULT_PROVIDER=mock`):** Full RAG, Document Intelligence, Agents, Workflows, and Evaluations work with `$0.00` inference spend.
- **Per-Tenant Hard Ceilings:** `ModelGatewayService` enforces monthly token budgets and `DECIMAL(18,6)` USD limits via `bcmath` before every LLM call (HTTP `429 QUOTA_EXCEEDED` + security event).
- **Agent & Upload Bounds:** Max upload size (default 20 MB), context cap (12,000 chars), agent step cap (1–12 steps), and model timeouts prevent runaway token usage.
