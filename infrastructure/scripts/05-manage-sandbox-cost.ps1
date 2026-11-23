<#
.SYNOPSIS
    Sandbox Cost Control Utility — Check status, deallocate/start VM, or completely tear down rg-nexamind-dev.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet("status", "stop", "start", "teardown")]
    [string]$Action,

    [string]$ResourceGroup = "rg-nexamind-dev",
    [string]$VmName = "vm-nexamind-dev"
)

$ErrorActionPreference = "Stop"

switch ($Action) {
    "status" {
        az vm get-instance-view --resource-group $ResourceGroup --name $VmName --query "{Name:name, PowerState:instanceView.statuses[1].displayStatus}" --output table
        az resource list --resource-group $ResourceGroup --query "[].{Name:name, Type:type, Location:location}" --output table
    }
    "stop" {
        Write-Host "Deallocating VM '$VmName' to halt compute charges..." -ForegroundColor Cyan
        az vm deallocate --resource-group $ResourceGroup --name $VmName
        Write-Host "[OK] VM deallocated." -ForegroundColor Green
        Write-Warning "Note: The Standard Static Public IP (~$0.005/hr = ~$3.65/mo) remains billed until deleted via '-Action teardown'."
    }
    "start" {
        Write-Host "Starting VM '$VmName'..." -ForegroundColor Cyan
        az vm start --resource-group $ResourceGroup --name $VmName
        $ip = az vm show --resource-group $ResourceGroup --name $VmName --show-details --query publicIps -o tsv
        Write-Host "[OK] VM running at https://$ip" -ForegroundColor Green
    }
    "teardown" {
        Write-Warning "THIS WILL PERMANENTLY DELETE RESOURCE GROUP '$ResourceGroup' AND ALL DATA INSIDE IT (VM, SQL, Storage, Public IP)."
        $confirm = Read-Host "Type DELETE-$ResourceGroup to confirm complete deletion"
        if ($confirm -ne "DELETE-$ResourceGroup") {
            Write-Host "Aborted." -ForegroundColor Yellow
            exit 0
        }
        az group delete --name $ResourceGroup --yes --no-wait
        Write-Host "Deletion started asynchronously. Verify completion with: az group exists --name $ResourceGroup" -ForegroundColor Green
    }
}
