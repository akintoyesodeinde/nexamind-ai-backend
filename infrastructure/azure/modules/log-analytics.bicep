targetScope = 'resourceGroup'

@description('Name of the Log Analytics Workspace.')
param workspaceName string

@description('Azure region for deployment.')
param location string = resourceGroup().location

@description('Log retention period in days (Free Trial friendly default: 30).')
@minValue(30)
@maxValue(730)
param retentionInDays int = 30

@description('Daily ingestion quota cap in GB to control sandbox spend.')
@minValue(1)
param dailyQuotaGb int = 1

@description('Resource tags.')
param tags object = {}

resource logAnalytics 'Microsoft.OperationalInsights/workspaces@2023-09-01' = {
  name: workspaceName
  location: location
  tags: tags
  properties: {
    sku: {
      name: 'PerGB2018'
    }
    retentionInDays: retentionInDays
    workspaceCapping: {
      dailyQuotaGb: dailyQuotaGb
    }
    publicNetworkAccessForIngestion: 'Enabled'
    publicNetworkAccessForQuery: 'Enabled'
  }
}

output workspaceId string = logAnalytics.id
output workspaceCustomerId string = logAnalytics.properties.customerId
output workspaceName string = logAnalytics.name
