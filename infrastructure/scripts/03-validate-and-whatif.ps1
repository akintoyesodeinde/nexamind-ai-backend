<#
.SYNOPSIS
    Stage 3/5 Dry Run — Lint, compile, and run Azure 'what-if' against your Resource Group.
    Creates ZERO billable resources.
#>
[CmdletBinding()]
param(
    [string]$ResourceGroup = "rg-nexamind-dev",
    [string]$Location = "eastus2",
    [string]$VmSize = "Standard_B1s",
    [string]$SshPubKeyPath = "$HOME\.ssh\nexamind_azure.pub",
    [string]$AlertEmail = ""
)

$ErrorActionPreference = "Stop"

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$azureDir = Resolve-Path (Join-Path $scriptRoot "..\azure")

if (-not (Test-Path $SshPubKeyPath)) {
    Write-Error "SSH public key not found at $SshPubKeyPath. Run: ssh-keygen -t ed25519 -f `"$HOME\.ssh\nexamind_azure`""
}

Write-Host "=== 1. Detecting your laptop public IPv4 for SSH NSG lockdown ===" -ForegroundColor Cyan
$myIp = (Invoke-RestMethod -Uri "https://api.ipify.org?format=text").Trim()
$adminCidr = "$myIp/32"
Write-Host "Trusted Admin SSH CIDR: $adminCidr" -ForegroundColor Green

if ([string]::IsNullOrWhiteSpace($AlertEmail)) {
    $AlertEmail = Read-Host "Enter email address for Azure budget alerts (50%, 80%, 100%)"
}

$sqlPassSecure = Read-Host "Enter a temporary/target Azure SQL admin password for what-if (masked)" -AsSecureString
$bstr = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($sqlPassSecure)
$sqlPassPlain = [System.Runtime.InteropServices.Marshal]::PtrToStringAuto($bstr)
[System.Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)

$env:NEXAMIND_SSH_PUBLIC_KEY = (Get-Content $SshPubKeyPath -Raw).Trim()
$env:NEXAMIND_ADMIN_CIDR = $adminCidr
$env:NEXAMIND_SQL_ADMIN_PASSWORD = $sqlPassPlain
$env:NEXAMIND_ALERT_EMAIL = $AlertEmail

try {
    Write-Host "`n=== 2. Linting & Building Azure Bicep Templates ===" -ForegroundColor Cyan
    Get-ChildItem (Join-Path $azureDir "modules\*.bicep") | ForEach-Object {
        az bicep lint --file $_.FullName
    }
    az bicep lint --file (Join-Path $azureDir "main.bicep")
    az bicep build --file (Join-Path $azureDir "main.bicep") --stdout | Out-Null
    Write-Host "[OK] All Bicep templates compiled with 0 errors." -ForegroundColor Green

    Write-Host "`n=== 3. Ensuring Resource Group '$ResourceGroup' Exists ($0) ===" -ForegroundColor Cyan
    az group create --name $ResourceGroup --location $Location --output none

    Write-Host "`n=== 4. Running Azure Deployment WHAT-IF (Preview Only — Zero Resources Created) ===" -ForegroundColor Cyan
    az deployment group what-if `
        --resource-group $ResourceGroup `
        --template-file (Join-Path $azureDir "main.bicep") `
        --parameters (Join-Path $azureDir "parameters\dev.bicepparam") `
        --parameters vmSize=$VmSize
} finally {
    Remove-Item Env:NEXAMIND_SQL_ADMIN_PASSWORD -ErrorAction SilentlyContinue
    Remove-Item Env:NEXAMIND_SSH_PUBLIC_KEY -ErrorAction SilentlyContinue
}
