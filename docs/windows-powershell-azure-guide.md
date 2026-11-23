# NexaMind AI — Beginner Windows PowerShell Azure Deployment Guide (29 Steps)

This guide is written specifically for running **every administrative step locally from Windows PowerShell on your Lenovo Windows laptop** (no Azure Cloud Shell required). Every step includes **what it does**, **where to run it**, **exact command**, **expected output**, **common errors**, and **safe recovery**.

> **Security Rules:**
> - Never paste your Azure password, MFA code, access token, SSH private key, SQL password, or Docker Hub token into chat or GitHub commits.
> - Sign in interactively using your authorized Microsoft Entra account (`kennedywilliams157@gmail.com`) and complete MFA inside the official Microsoft browser/WAM prompt.

---

## Step 1 — Install Azure CLI Locally
- **What it does:** Installs Microsoft's official Azure CLI (`az`) on Windows via WinGet.
- **Where to run:** Windows PowerShell (Start menu -> type `Windows PowerShell` -> Open).
- **Exact command:**
  ```powershell
  winget install --exact --id Microsoft.AzureCLI
  ```
- **Expected output:** `Successfully installed` (then **close and reopen** Windows PowerShell so `PATH` reloads).
- **Common errors & recovery:** If `winget` is blocked or fails, download and run the official Microsoft MSI installer from https://aka.ms/installazurecliwindows, then reopen PowerShell.

## Step 2 — Check Azure CLI Version
- **What it does:** Verifies `az` is on `PATH` and displays installed CLI/Python versions.
- **Where to run:** Newly reopened Windows PowerShell.
- **Exact command:**
  ```powershell
  az --version
  ```
- **Expected output:** Displays `azure-cli 2.x.x`, `core`, `telemetry`, and `Python location`.
- **Common errors & recovery:** `'az' is not recognized` -> Close ALL PowerShell windows and open a fresh one; verify `C:\Program Files\Microsoft SDKs\Azure\CLI2\wbin` exists.

## Step 3 — Sign In to Azure with Mandatory Entra MFA
- **What it does:** Opens the official Microsoft browser / Windows Web Account Manager (WAM) prompt so you authenticate and complete Microsoft Entra MFA Phase 2 safely.
- **Where to run:** Windows PowerShell.
- **Exact command:**
  ```powershell
  az login
  ```
- **Expected output:** A browser window opens -> sign in as your Azure Free Trial email -> approve Microsoft Authenticator / MFA -> PowerShell prints a JSON/table list of your subscriptions.
- **Common errors & recovery:**
  - If Windows Web Account Manager (WAM) hangs or loops: run `az config set core.enable_broker_on_windows=false` and retry `az login`, or use `az login --use-device-code` (open the printed `https://microsoft.com/devicelogin` URL yourself and enter the code).
  - Never share device codes or MFA prompts with anyone.

## Step 4 — Select the Correct Microsoft Entra Tenant
- **What it does:** Confirms your active Entra tenant ID (or switches tenants if your account belongs to more than one directory).
- **Where to run:** Windows PowerShell.
- **Exact command:**
  ```powershell
  az account tenant list --output table
  az account show --query "{User:user.name, TenantId:tenantId}" --output table
  ```
- **Expected output:** Shows your signed-in email and target `TenantId` GUID.
- **Common errors & recovery:** If signed into the wrong directory, run `az login --tenant "<TARGET_TENANT_ID>"`.

## Step 5 — Select the Correct Azure Subscription (Free Trial)
- **What it does:** Lists accessible subscriptions, verifies state is `Enabled`, and pins your Free Trial subscription as active.
- **Where to run:** Windows PowerShell.
- **Exact command:**
  ```powershell
  az account list --output table
  az account set --subscription "<YOUR_SUBSCRIPTION_ID>"
  az account show --query "{Name:name, Id:id, Tenant:tenantId, State:state}" --output table
  ```
- **Expected output:** `State: Enabled` and your Free Trial subscription selected as default (`IsDefault: True`).
- **Common errors & recovery:** `SubscriptionNotFound` -> verify you signed into the right tenant in Step 4 and check that your Azure Free Trial has not expired in the Azure Portal under *Cost Management + Billing*.

## Step 6 — Check Your Azure User Permissions (RBAC)
- **What it does:** Confirms whether your existing account already has `Owner` or `Contributor` permissions (you do **not** need a second human Entra user if you already have `Owner`).
- **Where to run:** Windows PowerShell.
- **Exact command:**
  ```powershell
  az role assignment list `
    --assignee (az ad signed-in-user show --query id -o tsv) `
    --include-inherited --all `
    --query "[].{Role:roleDefinitionName, Scope:scope}" --output table
  ```
- **Expected output:** Table displaying `Owner` (or `Contributor`) on `/subscriptions/<id>`.
- **Common errors & recovery:** If `az ad signed-in-user show` returns `Authorization_RequestDenied` (restricted directory read), run `az role assignment list --all --output table` instead.

## Step 7 — Create the Sandbox Resource Group
- **What it does:** Creates a single isolated container (`rg-nexamind-dev`, **$0 cost**) so all sandbox resources can be monitored and deleted together cleanly.
- **Where to run:** Windows PowerShell.
- **Exact command:**
  ```powershell
  $ResourceGroup = "rg-nexamind-dev"
  $Location = "eastus2"
  az group create --name $ResourceGroup --location $Location --output table
  ```
- **Expected output:** `ProvisioningState: Succeeded`.
- **Common errors & recovery:** `InvalidLocation` -> run `az account list-locations --query "[].name" -o table` to pick an allowed region.

## Step 8 — Check VM Availability and Eligible Free-Tier Sizes
- **What it does:** Checks whether free-tier eligible burstable VMs (`Standard_B1s`, `Standard_B2ats_v2`, `Standard_B2pts_v2`) or fallback `Standard_B1ms` are unrestricted in your chosen region.
- **Where to run:** Windows PowerShell inside `c:\Users\HP\NexaMindAI\nexamind-ai-backend`.
- **Exact command:**
  ```powershell
  .\infrastructure\scripts\01-check-azure-prereqs.ps1 -Location $Location
  ```
- **Expected output:** Table showing `Restricted: False` and `Restrictions: Available` for at least one burstable VM size.
- **Common errors & recovery:** If `Standard_B1s` shows `NotAvailableForSubscription` in `eastus2`, re-run with `-Location eastus`, `-Location centralus`, or `-Location westeurope` until you see `Available`.

## Step 9 — Install Azure Bicep
- **What it does:** Installs the Bicep compiler/linter used to validate and deploy NexaMind AI infrastructure declaratively.
- **Where to run:** Windows PowerShell.
- **Exact command:**
  ```powershell
  az bicep install
  az bicep version
  ```
- **Expected output:** `Bicep CLI version 0.x.x`.
- **Common errors & recovery:** If behind a strict proxy, run `az bicep upgrade` or install via `winget install Microsoft.Bicep`.

## Step 10 — Prepare Dedicated SSH Key Pair on Your Laptop
- **What it does:** Generates a dedicated Ed25519 SSH key pair (`nexamind_azure` private key stays on your laptop; `nexamind_azure.pub` public key goes to Azure VM).
- **Where to run:** Windows PowerShell.
- **Exact command:**
  ```powershell
  ssh-keygen -t ed25519 -f "$HOME\.ssh\nexamind_azure"
  Get-ChildItem "$HOME\.ssh\nexamind_azure*"
  ```
- **Expected output:** Prompts for a passphrase (set one!) and lists `nexamind_azure` and `nexamind_azure.pub`.
- **Common errors & recovery:** Never copy or commit `nexamind_azure` (without `.pub`) into any Git repo.

## Step 11 — Validate Bicep Templates Locally (Zero Provisioning)
- **What it does:** Runs Bicep strict linting (`bicepconfig.json`) and compiles `main.bicep` without contacting Azure billing.
- **Where to run:** Windows PowerShell in `c:\Users\HP\NexaMindAI\nexamind-ai-backend`.
- **Exact command:**
  ```powershell
  az bicep lint --file infrastructure\azure\main.bicep
  az bicep build --file infrastructure\azure\main.bicep --stdout | Out-Null
  Write-Host "Bicep compiled cleanly!"
  ```
- **Expected output:** Zero errors and `Bicep compiled cleanly!`.
- **Common errors & recovery:** Ensure you are inside `c:\Users\HP\NexaMindAI\nexamind-ai-backend`.

## Step 12 — Run Azure `what-if` Preview (Zero Billable Changes)
- **What it does:** Detects your laptop's current public IP (`/32` CIDR for NSG SSH lockdown), reads your SSH public key, and asks Azure to calculate every resource that would be created.
- **Where to run:** Windows PowerShell in `c:\Users\HP\NexaMindAI\nexamind-ai-backend`.
- **Exact command:**
  ```powershell
  .\infrastructure\scripts\03-validate-and-whatif.ps1 -ResourceGroup $ResourceGroup -Location $Location -VmSize "Standard_B1s"
  ```
- **Expected output:** Green `+ Create` plan listing VNet, NSG, Static Public IP, NIC, User-Assigned Identity, Linux VM, Azure SQL Server + Free Offer Database, Private Blob Storage, Log Analytics, and Budget Alert (and **NO** VMSS since `deployVmss = false`).
- **Common errors & recovery:** If Azure SQL reports quota/region restriction, try `-Location eastus` or set `deploySql = false` temporarily to inspect.

## Step 13 — Deploy the Approved Bicep Configuration
- **What it does:** Re-runs `what-if`, pauses for your explicit `DEPLOY` confirmation, and provisions the approved single-VM stack.
- **Where to run:** Windows PowerShell in `c:\Users\HP\NexaMindAI\nexamind-ai-backend`.
- **Exact command:**
  ```powershell
  .\infrastructure\scripts\04-deploy-azure-vm.ps1 -ResourceGroup $ResourceGroup -Location $Location -VmSize "Standard_B1s"
  ```
- **Expected output:** Displays `VM Name`, `VM Public IP`, `VM FQDN`, `SQL Server FQDN`, and `Storage Account`.
- **Common errors & recovery:** `SkuNotAvailable` -> retry with `-VmSize "Standard_B2ats_v2"` or an available region from Step 8.

## Step 14 — Read the Azure VM Public IP
- **What it does:** Fetches the VM's static IPv4 address from Azure.
- **Where to run:** Windows PowerShell.
- **Exact command:**
  ```powershell
  $PublicIP = az vm show --resource-group $ResourceGroup --name vm-nexamind-dev --show-details --query publicIps -o tsv
  Write-Host "VM Public IP: $PublicIP"
  ```
- **Expected output:** Prints a valid IPv4 address (e.g., `20.x.x.x`).
- **Common errors & recovery:** If blank, ensure deployment finished cleanly in Step 13.

## Step 15 — Connect to Ubuntu Using Your SSH Key
- **What it does:** Opens a passwordless, key-authenticated SSH session from your laptop to Ubuntu 24.04 LTS.
- **Where to run:** Windows PowerShell.
- **Exact command:**
  ```powershell
  ssh -i "$HOME\.ssh\nexamind_azure" "nexamindops@$PublicIP"
  ```
- **Expected output:** Prompts to trust host fingerprint (type `yes`) and your SSH key passphrase, then lands at `nexamindops@vm-nexamind-dev:~$`.
- **Common errors & recovery:** `Connection timed out` -> your home ISP IP changed; update NSG rule `Allow-SSH-Admin-Only` to your new `/32` IP via `az network nsg rule update`.

## Step 16 — Inspect `cloud-init` Execution & Bootstrap Status
- **What it does:** Verifies that `cloud-init` finished updating Ubuntu, creating the 2 GiB swapfile, installing Docker, configuring UFW, and enabling systemd units.
- **Where to run:** Inside the SSH session on Ubuntu.
- **Exact command:**
  ```bash
  sudo cloud-init status --wait
  cat /var/log/nexamind-bootstrap.status
  sudo tail -n 60 /var/log/cloud-init-output.log
  ```
- **Expected output:** `status: done` and `SUCCESS at <UTC timestamp>`.
- **Common errors & recovery:** If `FAILED`, inspect `/var/log/cloud-init-output.log` for the failing package/network line and re-run that command safely.

## Step 17 — Verify Docker Engine, Compose, Swap & Firewall
- **What it does:** Confirms Docker Engine, Compose v2, 2 GiB swap, and UFW rules (`22`, `80`, `443` only; no `1433`).
- **Where to run:** Inside the SSH session on Ubuntu.
- **Exact command:**
  ```bash
  sudo systemctl status docker --no-pager
  docker --version && docker compose version
  free -h
  sudo ufw status verbose
  ```
- **Expected output:** `active (running)`, Docker 27+/28+/29+, ~2.0Gi swap active, UFW active allowing only `22/tcp`, `80/tcp`, `443/tcp`.
- **Common errors & recovery:** If `docker` gives permission denied without `sudo`, log out (`exit`) and SSH back in so your `docker` group membership activates.

## Step 18 — Verify Least-Privilege SQL User & Laravel Backend Readiness
- **What it does:** Creates the least-privilege `nexamind_app` SQL user (using `docker/sqlserver/setup-app-user.sql`), places runtime secrets into `/opt/nexamind/secrets/backend.env` (`chmod 600`), and verifies Laravel container `/api/v1/health/ready`.
- **Where to run:** Inside the SSH session on Ubuntu (after first deployment in Step 22).
- **Exact command:**
  ```bash
  docker ps
  docker exec nexamind-backend php artisan nexamind:probe-sqlserver
  curl -kfsS https://127.0.0.1/api/v1/health/ready
  ```
- **Expected output:** `"status":"ready"`, `"database":"ok"`, and `VECTOR(1536)` verified.
- **Common errors & recovery:** If SQL login fails, confirm `DB_HOST`, `DB_USERNAME=nexamind_app`, `DB_ENCRYPT=yes`, `DB_TRUST_SERVER_CERTIFICATE=false`, and that the VM's static IP is in the Azure SQL firewall rule.

## Step 19 — Verify the React Frontend Application
- **What it does:** Confirms the non-root Nginx SPA container serves the React app through the HTTPS reverse proxy.
- **Where to run:** Inside the SSH session on Ubuntu.
- **Exact command:**
  ```bash
  curl -kfsS https://127.0.0.1/ | grep -o "<title>.*</title>"
  sudo /usr/local/bin/nexamind-healthcheck.sh
  ```
- **Expected output:** `<title>NexaMind AI</title>` and `HEALTHY: proxy, backend API, and frontend SPA responding over HTTPS`.

## Step 20 — Configure Microsoft Entra ID & GitHub Actions OIDC
- **What it does:** Creates a dedicated Entra App Registration (`github-nexamind-deploy-oidc`), grants **least-privilege `Virtual Machine Contributor`** strictly on `rg-nexamind-dev`, and configures OIDC federated trust for BOTH independent GitHub repos (zero Azure client secrets!).
- **Where to run:** Local Windows PowerShell in `c:\Users\HP\NexaMindAI\nexamind-ai-backend`.
- **Exact command:**
  ```powershell
  .\infrastructure\scripts\02-setup-entra-oidc.ps1 -ResourceGroup $ResourceGroup -Location $Location
  ```
- **Expected output:** Prints non-secret `AZURE_CLIENT_ID`, `AZURE_TENANT_ID`, `AZURE_SUBSCRIPTION_ID`, `AZURE_RESOURCE_GROUP`, and `AZURE_VM_NAME`. Copy these into **Settings -> Environments -> `azure-dev` -> Environment Variables** in BOTH GitHub repositories (with Required Reviewers enabled!).
- **Common errors & recovery:** If `az ad app create` fails with insufficient Entra permissions, ask your tenant administrator (or verify you signed in as the subscription's Global/Application Administrator).

## Step 21 — Configure Docker Hub (Separate Username & Scoped Token)
- **What it does:** Connects GitHub Actions to your Docker Hub account without using your Docker Hub password.
- **Where to run:** Docker Hub web portal + GitHub repo Settings (BOTH repos).
- **Exact steps:**
  1. Sign in to https://hub.docker.com and create two repositories: `<your-dockerhub-username>/nexamind-ai-backend` and `<your-dockerhub-username>/nexamind-ai-frontend`. *(Note: Docker Hub Personal Free includes unlimited public repos and 1 private repo.)*
  2. Go to **Account Settings -> Personal Access Tokens -> Generate New Token** with **Read & Write** scope (narrowly scoped token — NEVER use your account password!).
  3. In **BOTH** GitHub repositories (`Settings -> Environments -> azure-dev`):
     - Add **Environment Variable**: `DOCKERHUB_USERNAME` = `<your-exact-dockerhub-username>` (do not assume it matches your GitHub username!).
     - Add **Environment Secret**: `DOCKERHUB_TOKEN` = `<your-scoped-pat>`.
     - *(If your Docker Hub organization/plan supports OIDC, switch `docker/login-action` to OIDC token federation instead of PAT.)*

## Step 22 — Trigger the First Automated Deployment
- **What it does:** Builds, scans (Trivy), pushes immutable `sha-<short>` images to Docker Hub, authenticates to Azure via Entra OIDC, deploys safely via **Azure VM Run Command** (no SSH opened to GitHub!), runs `php artisan migrate --force`, and verifies live health checks.
- **Where to run:** GitHub Actions UI in `nexamind-ai-backend` first, then `nexamind-ai-frontend`.
- **Exact steps:**
  1. Open **Actions -> Deploy Backend to Azure VM (Docker Hub + Entra OIDC)** -> click **Run workflow** -> approve the `azure-dev` environment gate.
  2. Once Backend finishes green, open `nexamind-ai-frontend` -> **Actions -> Deploy Frontend to Azure VM (Docker Hub + Entra OIDC)** -> click **Run workflow** -> approve `azure-dev`.
- **Expected output:** Both workflows complete with green checkmarks and print verified `/api/v1/health/ready` and SPA smoke-test results.

## Step 23 — Open the Live Application
- **What it does:** Opens your live NexaMind AI workspace over HTTPS (optionally configure Let's Encrypt Certbot for a custom DNS domain so browsers show a trusted padlock without self-signed warnings).
- **Where to run:** Browser on your Lenovo laptop.
- **URL:** `https://<VM_FQDN>` (e.g., `https://nexamind-dev-xxxxxx.eastus2.cloudapp.azure.com`) or `https://<PublicIP>`.
- **Optional Let's Encrypt HTTPS (on VM after pointing your domain DNS A record to `$PublicIP`):**
  ```bash
  sudo apt-get install -y certbot
  sudo certbot certonly --webroot -w /var/www/certbot -d yourdomain.com --email you@example.com --agree-tos --non-interactive
  sudo ln -sf /etc/letsencrypt/live/yourdomain.com/fullchain.pem /opt/nexamind/certs/fullchain.pem
  sudo ln -sf /etc/letsencrypt/live/yourdomain.com/privkey.pem /opt/nexamind/certs/privkey.pem
  docker restart nexamind-proxy
  ```

## Step 24 — Read Live Application & Worker Logs
- **What it does:** Inspects real-time logs for the Laravel API, Nginx proxy, React SPA, and low-RAM queue timer.
- **Where to run:** SSH session on Ubuntu.
- **Exact command:**
  ```bash
  docker compose -f /opt/nexamind/compose.prod.yaml logs --tail=100 backend proxy frontend
  tail -n 50 /opt/nexamind/logs/worker.log
  systemctl list-timers nexamind-worker.timer
  ```

## Step 25 — Troubleshooting Authentication Failures (Azure CLI, MFA & OIDC)
- **WAM browser error on Windows:** Run `az config set core.enable_broker_on_windows=false` then `az login --use-device-code`.
- **GitHub OIDC `AADSTS70021` ("No matching federated identity record found"):** Verify the exact subject in Entra ID matches `repo:akintoyesodeinde/<repo>:environment:azure-dev` (case-sensitive!) and that the workflow job specifies `environment: azure-dev` and `permissions: { id-token: write }`.
- **Secure sign-out from laptop:** Run `az logout` when finished with administrative sessions.

## Step 26 — Troubleshooting VM & Docker Failures
- **Out-of-memory on 1 GiB VM:** Check `free -h` and `docker stats --no-stream`. Confirm `/swapfile` (2 GiB) is active (`swapon --show`). If traffic exceeds 1 GiB + swap, resize to `Standard_B1ms` (`az vm resize --resource-group $ResourceGroup --name vm-nexamind-dev --size Standard_B1ms`).
- **Container crash loop:** Run `docker logs --tail=100 nexamind-backend` and verify `/opt/nexamind/secrets/backend.env` exists (`chmod 600`) with valid `APP_KEY` and Azure SQL credentials.
- **Reboot recovery verification:** Run `sudo reboot` on the VM, wait 60s, SSH back in, and verify `docker ps` shows all 3 containers `Up (healthy)` via `nexamind.service` + `restart: unless-stopped`.

## Step 27 — Monitor Azure Free Trial Credits & Costs
- **What it does:** Inspects active resources and budget alerts so there are zero surprise charges.
- **Where to run:** Windows PowerShell + Azure Portal (*Cost Management + Billing -> Budgets*).
- **Exact command:**
  ```powershell
  .\infrastructure\scripts\05-manage-sandbox-cost.ps1 -Action status -ResourceGroup $ResourceGroup
  az consumption budget list --resource-group $ResourceGroup --output table
  ```

## Step 28 — Stop / Deallocate the VM When Idle
- **What it does:** Stops and deallocates the VM so compute hours stop accumulating (preserving OS disk and `/opt/nexamind` state).
- **Where to run:** Windows PowerShell.
- **Exact command:**
  ```powershell
  .\infrastructure\scripts\05-manage-sandbox-cost.ps1 -Action stop -ResourceGroup $ResourceGroup
  ```
- **Important reminder:** A Standard Static Public IPv4 (~`$0.005/hr` = ~`$3.65/mo`) continues billing while allocated even when the VM is stopped.

## Step 29 — Delete All Sandbox Resources (Zero Residual Billing)
- **What it does:** Permanently deletes `rg-nexamind-dev` and every single resource inside it (VM, Disk, Static Public IP, SQL Server, Storage, Log Analytics, Budget).
- **Where to run:** Windows PowerShell.
- **Exact command:**
  ```powershell
  .\infrastructure\scripts\05-manage-sandbox-cost.ps1 -Action teardown -ResourceGroup $ResourceGroup
  az logout
  ```
- **Expected output:** Confirms deletion started; verify zero leftover resources with `az group exists --name rg-nexamind-dev` returning `false`.
