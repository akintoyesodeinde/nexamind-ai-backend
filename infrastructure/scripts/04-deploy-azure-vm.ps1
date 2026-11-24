<#
.SYNOPSIS
    Stage 5 — Deploy Approved NexaMind AI Single Linux VM + Azure SQL Free Offer + Storage Bicep Stack.
    Requires explicit interactive confirmation after running what-if.
#>
[CmdletBinding()]
param(
    [string]$ResourceGroup = "rg-nexamind-dev",
    [string]$Location = "eastus2",
    [string]$VmSize = "Standard_B1s",
    [string]$SshPubKeyPath = "$HOME\.ssh\nexamind_azure.pub",
    [string]$AlertEmail = "",
    [switch]$AllowLaptopSqlBootstrap
)

$ErrorActionPreference = "Stop"

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$azureDir = Resolve-Path (Join-Path $scriptRoot "..\azure")

if (-not (Test-Path $SshPubKeyPath)) {
    Write-Error "SSH public key not found at $SshPubKeyPath."
}

$myIp = (Invoke-RestMethod -Uri "https://api.ipify.org?format=text").Trim()
$adminCidr = "$myIp/32"
$bootstrapIp = if ($AllowLaptopSqlBootstrap) { $myIp } else { "" }

if ([string]::IsNullOrWhiteSpace($AlertEmail)) {
    $AlertEmail = Read-Host "Enter email address for Azure budget alerts"
}

$sqlPassSecure = Read-Host "Enter strong Azure SQL admin password (12+ chars, upper/lower/number/symbol, masked)" -AsSecureString
$bstr = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($sqlPassSecure)
$sqlPassPlain = [System.Runtime.InteropServices.Marshal]::PtrToStringAuto($bstr)
[System.Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)

$env:NEXAMIND_SSH_PUBLIC_KEY = (Get-Content $SshPubKeyPath -Raw).Trim()
$env:NEXAMIND_ADMIN_CIDR = $adminCidr
$env:NEXAMIND_SQL_ADMIN_PASSWORD = $sqlPassPlain
$env:NEXAMIND_ALERT_EMAIL = $AlertEmail

try {
    Write-Host "`n=== Step 0: Checking Azure Resource Providers & Region ===" -ForegroundColor Cyan
    foreach ($ns in @("Microsoft.Compute", "Microsoft.Sql", "Microsoft.Storage", "Microsoft.Network", "Microsoft.OperationalInsights", "Microsoft.Consumption")) {
        $state = (az provider show --namespace $ns --query registrationState -o tsv 2>$null).Trim()
        if ($state -ne "Registered") {
            Write-Host "Registering $ns (currently: $state)..." -ForegroundColor Yellow
            az provider register --namespace $ns --wait --output none
            if ($LASTEXITCODE -ne 0) { throw "Failed to register Azure provider $ns." }
        }
    }

    $existingLoc = (az group show --name $ResourceGroup --query location -o tsv 2>$null)
    if ($LASTEXITCODE -eq 0 -and $existingLoc -and ($existingLoc.Trim().ToLower() -ne $Location.ToLower())) {
        throw "Resource Group '$ResourceGroup' already exists in '$($existingLoc.Trim())', not '$Location'. Delete the old Resource Group first (and purge its Log Analytics workspace if reusing '$ResourceGroup'), or pass a different -ResourceGroup."
    }

    az group create --name $ResourceGroup --location $Location --output none
    if ($LASTEXITCODE -ne 0) { throw "Failed to create Resource Group '$ResourceGroup' in '$Location'." }

    Write-Host "`n=== Step 1: Previewing Changes (what-if) ===" -ForegroundColor Cyan
    az deployment group what-if `
        --resource-group $ResourceGroup `
        --template-file (Join-Path $azureDir "main.bicep") `
        --parameters (Join-Path $azureDir "parameters\dev.bicepparam") `
        --parameters location=$Location vmSize=$VmSize sqlAdminBootstrapIp=$bootstrapIp
    if ($LASTEXITCODE -ne 0) { throw "Azure what-if failed. Aborting before deployment." }

    Write-Host "`nREMINDER: A Standard Static Public IPv4 costs ~$3.65/mo even when the VM is stopped (covered by $200 trial credit)." -ForegroundColor Yellow
    $confirm = Read-Host "Review the what-if output above. Type DEPLOY to provision these resources in '$ResourceGroup' ($Location, $VmSize)"
    if ($confirm -ne "DEPLOY") {
        Write-Host "Deployment cancelled. Zero resources were created." -ForegroundColor Yellow
        exit 0
    }

    $deployName = "nexamind-vm-$(Get-Date -Format 'yyyyMMdd-HHmm')"
    Write-Host "`n=== Step 2: Deploying Bicep Template ($deployName) ===" -ForegroundColor Cyan
    $rawJson = az deployment group create `
        --resource-group $ResourceGroup `
        --name $deployName `
        --template-file (Join-Path $azureDir "main.bicep") `
        --parameters (Join-Path $azureDir "parameters\dev.bicepparam") `
        --parameters location=$Location vmSize=$VmSize sqlAdminBootstrapIp=$bootstrapIp `
        --query properties.outputs `
        --output json

    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace(($rawJson -join ""))) {
        Write-Host "`n[ERROR] Deployment '$deployName' failed. Inspecting failed ARM operations:" -ForegroundColor Red
        az deployment operation group list `
            --resource-group $ResourceGroup `
            --name $deployName `
            --query "[?properties.provisioningState=='Failed'].{target:properties.targetResource.resourceName, type:properties.targetResource.resourceType, message:properties.statusMessage}" `
            --output json
        exit 1
    }

    $result = ($rawJson -join "`n") | ConvertFrom-Json

    Write-Host "`n=== DEPLOYMENT OUTPUTS ===" -ForegroundColor Green
    Write-Host "VM Name           : $($result.vmName.value)"
    Write-Host "VM Public IP      : $($result.vmPublicIp.value)"
    Write-Host "VM FQDN           : $($result.vmFqdn.value)"
    Write-Host "SQL Server FQDN   : $($result.sqlServerFqdn.value)"
    Write-Host "SQL Database      : $($result.sqlDatabaseName.value)"
    Write-Host "Storage Account   : $($result.storageAccountName.value)"
    Write-Host "`nNext: SSH into your VM and verify cloud-init finished cleanly:" -ForegroundColor Cyan
    Write-Host "  ssh -i `"$HOME\.ssh\nexamind_azure`" nexamindops@$($result.vmPublicIp.value)"
    Write-Host "  sudo cloud-init status --wait && cat /var/log/nexamind-bootstrap.status"
} finally {
    Remove-Item Env:NEXAMIND_SQL_ADMIN_PASSWORD -ErrorAction SilentlyContinue
    Remove-Item Env:NEXAMIND_SSH_PUBLIC_KEY -ErrorAction SilentlyContinue
}
