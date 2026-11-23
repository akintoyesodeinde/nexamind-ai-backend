targetScope = 'resourceGroup'

@description('Name of the resource-group Consumption budget.')
param budgetName string = 'budget-nexamind-dev'

@description('Monthly sandbox budget ceiling in billing currency (e.g., 25 USD).')
@minValue(5)
param monthlyAmount int = 25

@description('Budget start date (first day of a month, YYYY-MM-01).')
param startDate string

@description('Budget end date (YYYY-MM-DD).')
param endDate string

@description('Alert recipient email addresses.')
param contactEmails string[]

resource rgBudget 'Microsoft.Consumption/budgets@2023-11-01' = {
  name: budgetName
  properties: {
    category: 'Cost'
    amount: monthlyAmount
    timeGrain: 'Monthly'
    timePeriod: {
      startDate: startDate
      endDate: endDate
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

output budgetId string = rgBudget.id
output budgetName string = rgBudget.name
