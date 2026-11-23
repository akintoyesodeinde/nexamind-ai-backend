targetScope = 'resourceGroup'

@description('Globally unique Azure SQL logical server name.')
param sqlServerName string

@description('Azure SQL Database name.')
param databaseName string = 'nexamind'

@description('Azure region for deployment.')
param location string = resourceGroup().location

@description('SQL Server administrator login username.')
param administratorLogin string = 'nexamindadmin'

@description('SQL Server administrator password (supplied securely at deploy time, never committed).')
@secure()
param administratorLoginPassword string

@description('Enable Azure SQL Free Offer limits with automatic pause on exhaustion.')
param useFreeLimit bool = true

@description('Auto-pause delay in minutes for serverless compute (60 = 1 hour).')
@minValue(60)
param autoPauseDelayMinutes int = 60

@description('Allow Azure services (Container Apps) to reach the logical SQL Server firewall.')
param allowAzureServicesFirewall bool = true

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

resource allowAzureServices 'Microsoft.Sql/servers/firewallRules@2023-08-01' = if (allowAzureServicesFirewall) {
  parent: sqlServer
  name: 'AllowAllWindowsAzureIps'
  properties: {
    startIpAddress: '0.0.0.0'
    endIpAddress: '0.0.0.0'
  }
}

// Azure SQL Serverless Gen5 configured for Azure SQL Free Offer (32 GB max, native VECTOR GA)
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
    maxSizeBytes: 34359738368 // 32 GB Free Offer cap
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
