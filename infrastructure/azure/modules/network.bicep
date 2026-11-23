targetScope = 'resourceGroup'

@description('Virtual Network name.')
param vnetName string

@description('Application subnet name.')
param subnetName string = 'snet-nexamind-app'

@description('Standard Static Public IP resource name.')
param publicIpName string

@description('DNS label prefix for the VM Public IP (produces <label>.<region>.cloudapp.azure.com).')
param dnsLabelPrefix string

@description('Network Security Group resource ID to attach to the subnet.')
param nsgId string

@description('Azure region for deployment.')
param location string = resourceGroup().location

@description('Resource tags.')
param tags object = {}

resource vnet 'Microsoft.Network/virtualNetworks@2023-11-01' = {
  name: vnetName
  location: location
  tags: tags
  properties: {
    addressSpace: {
      addressPrefixes: [
        '10.20.0.0/16'
      ]
    }
    subnets: [
      {
        name: subnetName
        properties: {
          addressPrefix: '10.20.1.0/24'
          networkSecurityGroup: {
            id: nsgId
          }
        }
      }
    ]
  }
}

// Note: Standard Static Public IPv4 costs ~$0.005/hr (~$3.65/mo) even when the VM is stopped.
resource publicIp 'Microsoft.Network/publicIPAddresses@2023-11-01' = {
  name: publicIpName
  location: location
  tags: tags
  sku: {
    name: 'Standard'
    tier: 'Regional'
  }
  properties: {
    publicIPAllocationMethod: 'Static'
    dnsSettings: {
      domainNameLabel: dnsLabelPrefix
    }
  }
}

output vnetId string = vnet.id
output subnetId string = vnet.properties.subnets[0].id
output publicIpId string = publicIp.id
output publicIpAddress string = publicIp.properties.ipAddress
output fqdn string = publicIp.properties.dnsSettings.fqdn
