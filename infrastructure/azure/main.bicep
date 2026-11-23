targetScope = 'resourceGroup'

@description('Environment short name (dev or staging).')
@allowed(['dev', 'staging'])
param environmentName string = 'dev'

@description('Azure region for all sandbox resources.')
param location string = resourceGroup().location

@description('Short unique alphanumeric suffix for globally unique DNS, SQL, and Storage names.')
@minLength(4)
@maxLength(10)
param uniqueSuffix string = take(uniqueString(resourceGroup().id), 6)

@description('Eligible low-cost burstable Linux VM size (e.g., Standard_B1s, Standard_B2ats_v2, or Standard_B1ms).')
param vmSize string = 'Standard_B1s'

@description('Linux administrator username for SSH key login.')
param vmAdminUsername string = 'nexamindops'

@description('SSH public key (contents of $HOME/.ssh/nexamind_azure.pub). Private key stays on your local laptop.')
param vmSshPublicKey string

@description('Trusted CIDR allowed to SSH (TCP 22) into the VM — e.g., YOUR_LAPTOP_PUBLIC_IP/32.')
param adminAllowedCidr string

@description('Provision Azure SQL Database Free Offer (recommended so SQL Server runs outside the 1 GiB VM).')
param deploySql bool = true

@description('Azure SQL administrator login username (initial setup only; runtime app uses least-privilege nexamind_app).')
param sqlAdminLogin string = 'nexamindadmin'

@description('Azure SQL administrator password (supplied securely at deployment time).')
@secure()
param sqlAdminPassword string = ''

@description('Optional laptop IPv4 address temporarily permitted to create the least-privilege nexamind_app SQL user.')
param sqlAdminBootstrapIp string = ''

@description('Enable Azure SQL Database Free Offer limits with AutoPause on exhaustion.')
param sqlUseFreeLimit bool = true

@description('Provision private Azure Blob Storage account for organizational document uploads.')
param deployStorage bool = true

@description('OPTIONAL: Provision Flexible-Orchestration VM Scale Set (keep false for lowest-cost single-VM Free Trial sandbox).')
param deployVmss bool = false

@description('Enable Azure Consumption Budget alerts at 50%, 80%, and 100%.')
param enableBudgetAlert bool = true

@description('Monthly sandbox budget ceiling in USD.')
@minValue(5)
param monthlyBudgetAmount int = 25

@description('Budget start date (first day of a month, YYYY-MM-01).')
param budgetStartDate string = '2026-11-01'

@description('Budget end date (YYYY-MM-DD).')
param budgetEndDate string = '2027-11-01'

@description('Email addresses for cost alerts (e.g., administrator notification email).')
param budgetContactEmails string[] = []

var commonTags = {
  application: 'NexaMindAI'
  environment: environmentName
  managedBy: 'Bicep'
  architecture: 'SingleVM-DockerCompose-AzureSQLFree'
}

var identityName = 'id-nexamind-${environmentName}'
var nsgName = 'nsg-nexamind-${environmentName}'
var vnetName = 'vnet-nexamind-${environmentName}'
var publicIpName = 'pip-nexamind-${environmentName}'
var dnsLabelPrefix = toLower('nexamind-${environmentName}-${uniqueSuffix}')
var vmName = 'vm-nexamind-${environmentName}'
var nicName = 'nic-nexamind-${environmentName}'
var sqlServerName = toLower('sql-nexamind-${environmentName}-${uniqueSuffix}')
var storageAccountName = toLower('stnexamind${environmentName}${uniqueSuffix}')
var logAnalyticsName = toLower('log-nexamind-${environmentName}-${uniqueSuffix}')
var vmssName = 'vmss-nexamind-${environmentName}'

module identity 'modules/identity.bicep' = {
  name: 'deploy-identity'
  params: {
    identityName: identityName
    location: location
    tags: commonTags
  }
}

module nsg 'modules/nsg.bicep' = {
  name: 'deploy-nsg'
  params: {
    nsgName: nsgName
    location: location
    adminAllowedCidr: adminAllowedCidr
    tags: commonTags
  }
}

module network 'modules/network.bicep' = {
  name: 'deploy-network'
  params: {
    vnetName: vnetName
    subnetName: 'snet-nexamind-app'
    publicIpName: publicIpName
    dnsLabelPrefix: dnsLabelPrefix
    nsgId: nsg.outputs.nsgId
    location: location
    tags: commonTags
  }
}

module vm 'modules/vm.bicep' = {
  name: 'deploy-vm'
  params: {
    vmName: vmName
    nicName: nicName
    location: location
    vmSize: vmSize
    adminUsername: vmAdminUsername
    sshPublicKey: vmSshPublicKey
    subnetId: network.outputs.subnetId
    publicIpId: network.outputs.publicIpId
    managedIdentityId: identity.outputs.identityId
    osDiskSizeGb: 64
    tags: commonTags
  }
}

module sql 'modules/sql.bicep' = if (deploySql) {
  name: 'deploy-sql'
  params: {
    sqlServerName: sqlServerName
    databaseName: 'nexamind'
    location: location
    administratorLogin: sqlAdminLogin
    administratorLoginPassword: sqlAdminPassword
    vmPublicIpAddress: network.outputs.publicIpAddress
    adminBootstrapIpAddress: sqlAdminBootstrapIp
    useFreeLimit: sqlUseFreeLimit
    autoPauseDelayMinutes: 60
    tags: commonTags
  }
}

module storage 'modules/storage.bicep' = if (deployStorage) {
  name: 'deploy-storage'
  params: {
    storageAccountName: storageAccountName
    location: location
    documentsContainerName: 'nexamind-documents'
    managedIdentityPrincipalId: identity.outputs.principalId
    tags: commonTags
  }
}

module vmss 'modules/vmss.bicep' = if (deployVmss) {
  name: 'deploy-vmss-optional'
  params: {
    vmssName: vmssName
    location: location
    vmSize: 'Standard_B1ms'
    instanceCount: 1
    adminUsername: vmAdminUsername
    sshPublicKey: vmSshPublicKey
    subnetId: network.outputs.subnetId
    nsgId: nsg.outputs.nsgId
    managedIdentityId: identity.outputs.identityId
    tags: commonTags
  }
}

module monitoring 'modules/monitoring.bicep' = {
  name: 'deploy-monitoring'
  params: {
    workspaceName: logAnalyticsName
    location: location
    retentionInDays: 30
    dailyQuotaGb: 1
    enableBudgetAlert: enableBudgetAlert
    budgetName: 'budget-nexamind-${environmentName}'
    monthlyBudgetAmount: monthlyBudgetAmount
    budgetStartDate: budgetStartDate
    budgetEndDate: budgetEndDate
    contactEmails: budgetContactEmails
    tags: commonTags
  }
}

output vmName string = vm.outputs.vmName
output vmPublicIp string = network.outputs.publicIpAddress
output vmFqdn string = network.outputs.fqdn
output vmManagedIdentityClientId string = identity.outputs.clientId
output sqlServerFqdn string = deploySql ? sql.outputs.sqlServerFqdn : ''
output sqlDatabaseName string = deploySql ? sql.outputs.databaseName : ''
output storageAccountName string = deployStorage ? storage.outputs.storageAccountName : ''
output logAnalyticsWorkspaceName string = monitoring.outputs.workspaceName
