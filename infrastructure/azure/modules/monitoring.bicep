targetScope = 'resourceGroup'

@description('Log Analytics Workspace name.')
param workspaceName string

@description('Azure region for deployment.')
param location string = resourceGroup().location

@description('Log retention days (30-day minimum, stays within 5 GB/mo free grant).')
@minValue(30)
@maxValue(730)
param retentionInDays int = 30

@description('Daily ingestion quota cap in GB.')
@minValue(1)
param dailyQuotaGb int = 1

@description('Enable Azure Consumption Budget alerts at 50%, 80%, and 100%.')
param enableBudgetAlert bool = true

@description('Budget resource name.')
param budgetName string = 'budget-nexamind-dev'

@description('Monthly sandbox budget ceiling (USD).')
@minValue(5)
param monthlyBudgetAmount int = 25

@description('Budget start date (first day of a month, YYYY-MM-01).')
param budgetStartDate string

@description('Budget end date (YYYY-MM-DD).')
param budgetEndDate string

@description('Alert email recipients.')
param contactEmails string[] = []

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

resource rgBudget 'Microsoft.Consumption/budgets@2023-11-01' = if (enableBudgetAlert && !empty(contactEmails)) {
  name: budgetName
  properties: {
    category: 'Cost'
    amount: monthlyBudgetAmount
    timeGrain: 'Monthly'
    timePeriod: {
      startDate: budgetStartDate
      endDate: budgetEndDate
    }
    notifications: {
      Actual_50_Percent: {
        enabled: true
        operator: 'GreaterThanOrEqualTo'
        threshold: 50
        thresholdType: 'Actual'
        contactEmails: contactEmails
      }
      Actual_80_Percent: {
        enabled: true
        operator: 'GreaterThanOrEqualTo'
        threshold: 80
        thresholdType: 'Actual'
        contactEmails: contactEmails
      }
      Actual_100_Percent: {
        enabled: true
        operator: 'GreaterThanOrEqualTo'
        threshold: 100
        thresholdType: 'Actual'
        contactEmails: contactEmails
      }
      Forecasted_100_Percent: {
        enabled: true
        operator: 'GreaterThanOrEqualTo'
        threshold: 100
        thresholdType: 'Forecasted'
        contactEmails: contactEmails
      }
    }
  }
}

output workspaceId string = logAnalytics.id
output workspaceName string = logAnalytics.name
