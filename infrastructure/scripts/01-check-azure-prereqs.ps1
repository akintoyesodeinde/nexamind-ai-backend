<#
.SYNOPSIS
    Stage 3 Prerequisite Check — Local Windows PowerShell check for Azure CLI, Bicep,
    MFA-authenticated subscription, RBAC permissions, SSH key, and regional VM SKU availability.
    Makes ZERO changes to your Azure subscription.
#>
[CmdletBinding()]
param(
    [string]$Location = "eastus2",
    [string]$SshKeyPath = "$HOME\.ssh\nexamind_azure"
)

$ErrorActionPreference = "Stop"

Write-Host "`n=== 1. Azure CLI & Bicep Installation ===" -ForegroundColor Cyan
if (-not (Get-Command az -ErrorAction SilentlyContinue)) {
    Write-Error "Azure CLI ('az') is not installed. Run: winget install --exact --id Microsoft.AzureCLI, then reopen PowerShell."
}
az version
az bicep version

Write-Host "`n=== 2. Signed-In Azure Account & Subscription ===" -ForegroundColor Cyan
$account = az account show --output json | ConvertFrom-Json
if (-not $account) {
    Write-Error "Not signed in to Azure. Run: az login (and complete Microsoft Entra MFA in your browser)."
}
az account show --query "{SubscriptionName:name, SubscriptionId:id, State:state, TenantId:tenantId, User:user.name}" --output table

Write-Host "`n=== 3. Assigned Azure RBAC Roles ===" -ForegroundColor Cyan
$ErrorActionPreference = "Continue"
$signedInId = az ad signed-in-user show --query id -o tsv 2>$null
if ($signedInId) {
    az role assignment list --assignee $signedInId --include-inherited --all --query "[].{Role:roleDefinitionName, Scope:scope}" --output table
} else {
    Write-Warning "Could not read Entra signed-in user ID (directory read may be restricted). Checking subscription-level assignments..."
    az role assignment list --all --query "[].{Role:roleDefinitionName, Principal:principalName, Scope:scope}" --output table
}
$ErrorActionPreference = "Stop"

Write-Host "`n=== 4. Local SSH Key Check ===" -ForegroundColor Cyan
if (Test-Path "$SshKeyPath.pub") {
    Write-Host "[OK] SSH public key found: $SshKeyPath.pub" -ForegroundColor Green
} else {
    Write-Warning "SSH key not found at $SshKeyPath.pub. Generate it with:`n  ssh-keygen -t ed25519 -f `"$SshKeyPath`""
}

Write-Host "`n=== 5. Eligible Low-Cost Burstable VM Sizes in '$Location' ===" -ForegroundColor Cyan
az vm list-skus --location $Location --resource-type virtualMachines --output json |
    ConvertFrom-Json |
    Where-Object { $_.name -in @("Standard_B1s", "Standard_B2ats_v2", "Standard_B2pts_v2", "Standard_B1ms") } |
    ForEach-Object {
        $restricted = ($_.restrictions | Measure-Object).Count -gt 0
        [PSCustomObject]@{
            VMSize       = $_.name
            Location     = $Location
            Restricted   = $restricted
            Restrictions = if ($restricted) { ($_.restrictions | ForEach-Object { $_.reasonCode }) -join ", " } else { "Available" }
        }
    } | Format-Table -AutoSize

Write-Host "`nPrerequisite check finished with ZERO Azure changes." -ForegroundColor Green
