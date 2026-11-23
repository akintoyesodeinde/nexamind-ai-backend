targetScope = 'resourceGroup'

@description('Globally unique Azure SQL logical server name.')
param sqlServerName string

@description('Azure SQL Database name.')
param databaseName string = 'nexamind'

@description('Azure region for deployment.')
param location string = resourceGroup().location

@description('SQL Server administrator login username (used for initial bootstrap only; runtime app uses least-privilege nexamind_app).')
param administratorLogin string = 'nexamindadmin'

@description('SQL Server administrator password (supplied securely at deployment time).')
@secure()
param administratorLoginPassword string

@description('Static Public IP address of the NexaMind Linux VM allowed through the Azure SQL firewall (TCP 1433 is NEVER opened to 0.0.0.0/0).')
param vmPublicIpAddress string

@description('Optional trusted laptop IPv4 address for initial least-privilege DB user bootstrap (leave empty after setup).')
param adminBootstrapIpAddress string = ''

@description('Enable Azure SQL Database Free Offer limits with AutoPause on exhaustion.')
param useFreeLimit bool = true

@description('Serverless auto-pause delay in minutes (60 = 1 hour).')
@minValue(60)
param autoPauseDelayMinutes int = 60

@description('Resource tags.')
param tags object = {}

resource sqlServer 'Microsoft.Sql/servers@2023-08-01' = {
  name: sqlServerName
  location: location
  tags: tags
  properties: {
    administratorLogin: administratorLogin
    administratorLoginPassword: administratorLoginPassword
    version: '12.0'
    minimalTlsVersion: '1.2'
    publicNetworkAccess: 'Enabled'
  }
}

// Strictly permit ONLY the NexaMind Linux VM static IP (no public internet 0.0.0.0/0 exposure)
resource allowVmStaticIp 'Microsoft.Sql/servers/firewallRules@2023-08-01' = {
  parent: sqlServer
  name: 'Allow-NexaMind-VM-Static-IP'
  properties: {
    startIpAddress: vmPublicIpAddress
    endIpAddress: vmPublicIpAddress
  }
}

resource allowAdminBootstrapIp 'Microsoft.Sql/servers/firewallRules@2023-08-01' = if (!empty(adminBootstrapIpAddress)) {
  parent: sqlServer
  name: 'Allow-Admin-Bootstrap-IP'
  properties: {
    startIpAddress: adminBootstrapIpAddress
    endIpAddress: adminBootstrapIpAddress
  }
}

// Azure SQL Database Free Offer: 100,000 vCore-seconds/mo + 32 GB data + 32 GB backup + native VECTOR GA
resource sqlDatabase 'Microsoft.Sql/servers/databases@2023-08-01' = {
  parent: sqlServer
  name: databaseName
  location: location
  tags: tags
  sku: {
    name: 'GP_S_Gen5'
    tier: 'GeneralPurpose'
    family: 'Gen5'
    capacity: 2
  }
  properties: {
    collation: 'SQL_Latin1_General_CP1_CI_AS'
    maxSizeBytes: 34359738368
    minCapacity: json('0.5')
    autoPauseDelay: autoPauseDelayMinutes
    useFreeLimit: useFreeLimit
    freeLimitExhaustionBehavior: useFreeLimit ? 'AutoPause' : 'BillForUsage'
    zoneRedundant: false
    requestedBackupStorageRedundancy: 'Local'
  }
}

output sqlServerId string = sqlServer.id
output sqlServerFqdn string = sqlServer.properties.fullyQualifiedDomainName
output databaseName string = sqlDatabase.name
