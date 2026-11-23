<#
.SYNOPSIS
    Stage 4 — Configure Dedicated Microsoft Entra ID Workload Identity & GitHub Actions OIDC
    for both independent NexaMind AI repositories (zero client secrets, least-privilege RBAC).
.DESCRIPTION
    Run from your local Windows PowerShell after completing 'az login' with MFA.
    Grants ONLY 'Virtual Machine Contributor' scoped strictly to the target Resource Group
    so GitHub Actions can execute 'az vm run-command invoke' without inbound SSH or Subscription Owner access.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$ResourceGroup = "rg-nexamind-dev",

    [string]$Location = "eastus2",
    [string]$AppDisplayName = "github-nexamind-deploy-oidc",
    [string]$GitHubOwner = "akintoyesodeinde",
    [string]$BackendRepo = "nexamind-ai-backend",
    [string]$FrontendRepo = "nexamind-ai-frontend",
    [string]$GitHubEnvironment = "azure-dev"
)

$ErrorActionPreference = "Stop"

$subId = az account show --query id -o tsv
$tenantId = az account show --query tenantId -o tsv

Write-Host "Target Subscription : $subId" -ForegroundColor Cyan
Write-Host "Target Entra Tenant : $tenantId" -ForegroundColor Cyan
Write-Host "Target ResourceGrp  : $ResourceGroup ($Location)" -ForegroundColor Cyan
Write-Host "GitHub Repos        : $GitHubOwner/$BackendRepo & $GitHubOwner/$FrontendRepo (env: $GitHubEnvironment)" -ForegroundColor Cyan

$confirm = Read-Host "`nType PROCEED to create/update Entra OIDC workload identity and least-privilege RBAC"
if ($confirm -ne "PROCEED") {
    Write-Host "Aborted — no identity or RBAC changes made." -ForegroundColor Yellow
    exit 0
}

# 1. Ensure Resource Group exists (RG itself is $0)
az group create --name $ResourceGroup --location $Location --output none

# 2. Create or reuse dedicated App Registration
$appId = az ad app list --display-name $AppDisplayName --query "[0].appId" -o tsv
if (-not $appId) {
    $appId = az ad app create --display-name $AppDisplayName --query appId -o tsv
    Write-Host "[CREATED] Entra App Registration: $AppDisplayName ($appId)" -ForegroundColor Green
} else {
    Write-Host "[REUSED] Entra App Registration: $AppDisplayName ($appId)" -ForegroundColor Yellow
}

# 3. Ensure Service Principal exists
$spObjectId = az ad sp list --filter "appId eq '$appId'" --query "[0].id" -o tsv
if (-not $spObjectId) {
    $spObjectId = az ad sp create --id $appId --query id -o tsv
    Write-Host "[CREATED] Service Principal: $spObjectId" -ForegroundColor Green
    Start-Sleep -Seconds 10
}

# 4. Grant least-privilege RBAC on Resource Group ONLY (Virtual Machine Contributor -> allows az vm run-command invoke)
$rgScope = "/subscriptions/$subId/resourceGroups/$ResourceGroup"
az role assignment create `
    --assignee-object-id $spObjectId `
    --assignee-principal-type ServicePrincipal `
    --role "Virtual Machine Contributor" `
    --scope $rgScope `
    --output none

Write-Host "[OK] Assigned 'Virtual Machine Contributor' scoped strictly to $rgScope" -ForegroundColor Green

# 5. Add GitHub Actions OIDC Federated Identity Credentials for BOTH independent repos
$federations = @(
    @{
        name    = "nexamind-backend-$GitHubEnvironment"
        subject = "repo:${GitHubOwner}/${BackendRepo}:environment:${GitHubEnvironment}"
    },
    @{
        name    = "nexamind-frontend-$GitHubEnvironment"
        subject = "repo:${GitHubOwner}/${FrontendRepo}:environment:${GitHubEnvironment}"
    }
)

foreach ($fed in $federations) {
    $existing = az ad app federated-credential list --id $appId --query "[?name=='$($fed.name)'].name" -o tsv
    if (-not $existing) {
        $payload = @{
            name        = $fed.name
            issuer      = "https://token.actions.githubusercontent.com"
            subject     = $fed.subject
            description = "GitHub Actions OIDC deployment trust for $($fed.subject)"
            audiences   = @("api://AzureADTokenExchange")
        } | ConvertTo-Json -Compress

        $tmpFile = New-TemporaryFile
        Set-Content -Path $tmpFile -Value $payload -Encoding UTF8
        az ad app federated-credential create --id $appId --parameters "@$($tmpFile.FullName)" --output none
        Remove-Item $tmpFile -Force
        Write-Host "[CREATED] Federated Credential: $($fed.name) -> $($fed.subject)" -ForegroundColor Green
    } else {
        Write-Host "[EXISTS] Federated Credential: $($fed.name)" -ForegroundColor Yellow
    }
}

Write-Host "`n=== Copy these NON-SECRET Environment Variables into GitHub Environment '$GitHubEnvironment' (BOTH repos) ===" -ForegroundColor Cyan
Write-Host "AZURE_CLIENT_ID       = $appId"
Write-Host "AZURE_TENANT_ID       = $tenantId"
Write-Host "AZURE_SUBSCRIPTION_ID = $subId"
Write-Host "AZURE_RESOURCE_GROUP  = $ResourceGroup"
Write-Host "AZURE_VM_NAME         = vm-nexamind-dev"
Write-Host "`nNO Azure client secret was created or required." -ForegroundColor Green
