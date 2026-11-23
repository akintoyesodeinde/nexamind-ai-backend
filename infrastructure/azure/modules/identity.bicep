targetScope = 'resourceGroup'

@description('User-Assigned Managed Identity name for the NexaMind AI Linux VM.')
param identityName string

@description('Azure region for deployment.')
param location string = resourceGroup().location

@description('Resource tags.')
param tags object = {}

resource managedIdentity 'Microsoft.ManagedIdentity/userAssignedIdentities@2023-01-31' = {
  name: identityName
  location: location
  tags: tags
}

output identityId string = managedIdentity.id
output principalId string = managedIdentity.properties.principalId
output clientId string = managedIdentity.properties.clientId
output identityName string = managedIdentity.name
