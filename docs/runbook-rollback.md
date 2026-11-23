# NexaMind AI — Deployment, Rollback & Disaster Recovery Runbook

Every production image pushed to Docker Hub is tagged with an immutable Git commit tag (`sha-<short>`) and optional SemVer release tag (`vX.Y.Z`), and every successful deployment records its verified tag in `/opt/nexamind/releases/` on the Azure VM.

---

## 1. Automated Rollback via GitHub Actions (Microsoft Entra OIDC + VM Run Command)

### Backend Rollback (`nexamind-ai-backend`)
1. Open **Actions -> Rollback Backend on Azure VM (Docker Hub + Entra OIDC)** (`rollback.yml`).
2. Click **Run workflow**:
   - `environment`: `azure-dev`
   - `target_image_tag`: exact prior verified Docker Hub tag (e.g., `sha-dfe517d`), or leave blank to automatically roll back to `/opt/nexamind/releases/backend.previous`.
3. Approve the protected `azure-dev` environment gate.
4. GitHub authenticates to Azure via Entra OIDC, executes `az vm run-command invoke` to update `BACKEND_IMAGE_TAG` in `/opt/nexamind/.env.deploy`, pulls and restarts `nexamind-backend` + `nexamind-proxy`, and verifies `https://127.0.0.1/api/v1/health/ready` returns HTTP `200`.

### Frontend Rollback (`nexamind-ai-frontend`)
1. Open **Actions -> Rollback Frontend on Azure VM (Docker Hub + Entra OIDC)** (`rollback.yml`).
2. Click **Run workflow** (leave `target_image_tag` blank to roll back to `/opt/nexamind/releases/frontend.previous`), approve `azure-dev`, and wait for automated `/healthz` + SPA verification.

---

## 2. Manual SSH Emergency Rollback (From Local Windows PowerShell)

```powershell
$PublicIP = az vm show --resource-group rg-nexamind-dev --name vm-nexamind-dev --show-details --query publicIps -o tsv
ssh -i "$HOME\.ssh\nexamind_azure" "nexamindops@$PublicIP"
```

On the Ubuntu VM:
```bash
cd /opt/nexamind
cat releases/backend.previous
# Update BACKEND_IMAGE_TAG (or FRONTEND_IMAGE_TAG) in /opt/nexamind/.env.deploy
sudo sed -i 's/^BACKEND_IMAGE_TAG=.*/BACKEND_IMAGE_TAG=<PREVIOUS_TAG>/' /opt/nexamind/.env.deploy
sudo docker compose --env-file /opt/nexamind/.env.deploy -f /opt/nexamind/compose.prod.yaml pull backend
sudo docker compose --env-file /opt/nexamind/.env.deploy -f /opt/nexamind/compose.prod.yaml up -d --no-deps backend
sudo /usr/local/bin/nexamind-healthcheck.sh
```

---

## 3. Database Migration & Backup Recovery Policy
- **Expand-and-Contract Migrations:** Never drop/rename columns in the same release that changes application reads, guaranteeing container rollbacks stay schema-compatible.
- **Azure SQL Free Offer Automated Backups:** Includes 32 GB automated point-in-time restore (PITR) backup storage; restore to a new database via Azure CLI `az sql db restore` if logical corruption ever occurs.
- **VM Disaster Recovery:** Because the VM is stateless (code comes from Docker Hub, database lives on Azure SQL, documents live in Azure Blob Storage), re-running `04-deploy-azure-vm.ps1` + `deploy.yml` rebuilds a clean server from scratch in minutes.
