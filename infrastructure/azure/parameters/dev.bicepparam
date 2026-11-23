using '../main.bicep'

param environmentName = 'dev'
param vmSize = 'Standard_B1s'
param vmAdminUsername = 'nexamindops'
param vmSshPublicKey = readEnvironmentVariable('NEXAMIND_SSH_PUBLIC_KEY')
param adminAllowedCidr = readEnvironmentVariable('NEXAMIND_ADMIN_CIDR')
param deploySql = true
param sqlAdminLogin = 'nexamindadmin'
param sqlAdminPassword = readEnvironmentVariable('NEXAMIND_SQL_ADMIN_PASSWORD')
param sqlAdminBootstrapIp = ''
param sqlUseFreeLimit = true
param deployStorage = true
param deployVmss = false
param enableBudgetAlert = true
param monthlyBudgetAmount = 25
param budgetStartDate = '2026-11-01'
param budgetEndDate = '2027-11-01'
param budgetContactEmails = [
  readEnvironmentVariable('NEXAMIND_ALERT_EMAIL')
]
