targetScope = 'resourceGroup'

@description('Environment short name (e.g., dev, staging).')
@allowed(['dev', 'staging'])
param environmentName string = 'dev'

@description('Azure region for all sandbox resources.')
param location string = resourceGroup().location

@description('Unique resource suffix (alphanumeric lowercase) to guarantee global uniqueness for ACR, SQL, and Storage.')
@minLength(4)
@maxLength(12)
param uniqueSuffix string = take(uniqueString(resourceGroup().id), 8)

@description('Azure SQL administrator login username.')
param sqlAdminLogin string = 'nexamindadmin'

@description('Azure SQL administrator password (supplied securely at deployment time).')
@secure()
param sqlAdminPassword string

@description('Laravel APP_KEY secret (supplied securely at deployment time).')
@secure()
param appKey string

@description('Enable Azure SQL Free Offer limits with automatic pause on exhaustion.')
param sqlUseFreeLimit bool = true

@description('Backend API container image tag in ACR (e.g., sha-abc1234 or latest).')
param backendImageTag string = 'latest'

@description('Frontend SPA container image tag in ACR (e.g., sha-def5678 or latest).')
param frontendImageTag string = 'latest'

@description('Default AI provider key.')
@allowed(['mock', 'openai', 'gemini'])
param aiDefaultProvider string = 'mock'

@description('Optional OpenAI API key.')
@secure()
param openAiApiKey string = ''

@description('Optional Gemini API key.')
@secure()
param geminiApiKey string = ''

@description('Enable Azure Consumption Budget alerts (50%, 80%, 100%).')
param enableBudgetAlert bool = true

@description('Monthly sandbox budget ceiling (USD).')
@minValue(5)
param monthlyBudgetAmount int = 25

@description('Budget start date (first day of a month, YYYY-MM-01).')
param budgetStartDate string = '2026-11-01'

@description('Budget end date (YYYY-MM-DD).')
param budgetEndDate string = '2027-11-01'

@description('Email addresses for cost threshold alerts.')
param budgetContactEmails string[] = []

var commonTags = {
  application: 'NexaMindAI'
  environment: environmentName
  managedBy: 'Bicep'
  architecture: 'OptionA-ModularMonolith'
}

var identityName = 'id-nexamind-${environmentName}'
var logAnalyticsName = 'log-nexamind-${environmentName}-${uniqueSuffix}'
var acrName = toLower('acrnexamind${environmentName}${uniqueSuffix}')
var storageAccountName = toLower('stnexamind${environmentName}${uniqueSuffix}')
var sqlServerName = toLower('sql-nexamind-${environmentName}-${uniqueSuffix}')
var containerAppsEnvName = 'cae-nexamind-${environmentName}'

resource managedIdentity 'Microsoft.ManagedIdentity/userAssignedIdentities@2023-01-31' = {
  name: identityName
  location: location
  tags: commonTags
}

module logAnalytics 'modules/log-analytics.bicep' = {
  name: 'deploy-log-analytics'
  params: {
    workspaceName: logAnalyticsName
    location: location
    retentionInDays: 30
    dailyQuotaGb: 1
    tags: commonTags
  }
}

module acr 'modules/acr.bicep' = {
  name: 'deploy-acr'
  params: {
    registryName: acrName
    location: location
    managedIdentityPrincipalId: managedIdentity.properties.principalId
    tags: commonTags
  }
}

module storage 'modules/storage.bicep' = {
  name: 'deploy-storage'
  params: {
    storageAccountName: storageAccountName
    location: location
    documentsContainerName: 'nexamind-documents'
    managedIdentityPrincipalId: managedIdentity.properties.principalId
    tags: commonTags
  }
}

module sqlDatabase 'modules/sql-database.bicep' = {
  name: 'deploy-sql-database'
  params: {
    sqlServerName: sqlServerName
    databaseName: 'nexamind'
    location: location
    administratorLogin: sqlAdminLogin
    administratorLoginPassword: sqlAdminPassword
    useFreeLimit: sqlUseFreeLimit
    autoPauseDelayMinutes: 60
    tags: commonTags
  }
}

module containerApps 'modules/container-apps-env.bicep' = {
  name: 'deploy-container-apps'
  params: {
    environmentName: containerAppsEnvName
    apiAppName: 'ca-nexamind-api'
    webAppName: 'ca-nexamind-web'
    workerJobName: 'caj-nexamind-worker'
    location: location
    managedIdentityId: managedIdentity.id
    logAnalyticsWorkspaceName: logAnalytics.outputs.workspaceName
    storageAccountName: storage.outputs.storageAccountName
    storageContainerName: storage.outputs.containerName
    acrLoginServer: acr.outputs.loginServer
    backendImage: '${acr.outputs.loginServer}/nexamind-ai-backend:${backendImageTag}'
    frontendImage: '${acr.outputs.loginServer}/nexamind-ai-frontend:${frontendImageTag}'
    sqlServerFqdn: sqlDatabase.outputs.sqlServerFqdn
    sqlDatabaseName: sqlDatabase.outputs.databaseName
    sqlAdminLogin: sqlAdminLogin
    sqlAdminPassword: sqlAdminPassword
    appKey: appKey
    aiDefaultProvider: aiDefaultProvider
    openAiApiKey: openAiApiKey
    geminiApiKey: geminiApiKey
    workerCronExpression: '*/5 * * * *'
    tags: commonTags
  }
}

module budget 'modules/budget.bicep' = if (enableBudgetAlert && !empty(budgetContactEmails)) {
  name: 'deploy-sandbox-budget'
  params: {
    budgetName: 'budget-nexamind-${environmentName}'
    monthlyAmount: monthlyBudgetAmount
    startDate: budgetStartDate
    endDate: budgetEndDate
    contactEmails: budgetContactEmails
  }
}

output managedIdentityClientId string = managedIdentity.properties.clientId
output acrLoginServer string = acr.outputs.loginServer
output acrName string = acr.outputs.registryName
output storageAccountName string = storage.outputs.storageAccountName
output sqlServerFqdn string = sqlDatabase.outputs.sqlServerFqdn
output apiAppName string = containerApps.outputs.apiAppName
output apiUrl string = containerApps.outputs.apiUrl
output webAppName string = containerApps.outputs.webAppName
output webUrl string = containerApps.outputs.webUrl
output workerJobName string = containerApps.outputs.workerJobName
