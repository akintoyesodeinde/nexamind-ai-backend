using './main.bicep'

param environmentName = 'dev'
param sqlAdminLogin = 'nexamindadmin'
param sqlAdminPassword = readEnvironmentVariable('NEXAMIND_SQL_ADMIN_PASSWORD')
param appKey = readEnvironmentVariable('NEXAMIND_APP_KEY')
param sqlUseFreeLimit = true
param backendImageTag = 'latest'
param frontendImageTag = 'latest'
param aiDefaultProvider = 'mock'
param enableBudgetAlert = true
param monthlyBudgetAmount = 25
param budgetStartDate = '2026-11-01'
param budgetEndDate = '2027-11-01'
param budgetContactEmails = [
  'ops@example.com'
]
