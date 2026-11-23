targetScope = 'resourceGroup'

@description('Globally unique Azure Container Registry name (5-50 lowercase alphanumeric characters).')
@minLength(5)
@maxLength(50)
param registryName string

@description('Azure region for deployment.')
param location string = resourceGroup().location

@description('Principal ID of the User-Assigned Managed Identity granted AcrPull.')
param managedIdentityPrincipalId string

@description('Resource tags.')
param tags object = {}

// Built-in Azure RBAC role: AcrPull
var acrPullRoleDefinitionId = subscriptionResourceId(
  'Microsoft.Authorization/roleDefinitions',
  '7f951dda-4ed3-4680-a7ca-43fe172d538d'
)

resource acr 'Microsoft.ContainerRegistry/registries@2023-07-01' = {
  name: registryName
  location: location
  tags: tags
  sku: {
    name: 'Basic'
  }
  properties: {
    adminUserEnabled: false
    publicNetworkAccess: 'Enabled'
    anonymousPullEnabled: false
  }
}

resource acrPullAssignment 'Microsoft.Authorization/roleAssignments@2022-04-01' = {
  name: guid(acr.id, managedIdentityPrincipalId, acrPullRoleDefinitionId)
  scope: acr
  properties: {
    roleDefinitionId: acrPullRoleDefinitionId
    principalId: managedIdentityPrincipalId
    principalType: 'ServicePrincipal'
  }
}

output registryId string = acr.id
output registryName string = acr.name
output loginServer string = acr.properties.loginServer
