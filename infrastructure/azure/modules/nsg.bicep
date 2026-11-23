targetScope = 'resourceGroup'

@description('Name of the Network Security Group.')
param nsgName string

@description('Azure region for deployment.')
param location string = resourceGroup().location

@description('Trusted CIDR block allowed to connect via SSH (TCP 22), e.g., YOUR_LAPTOP_PUBLIC_IP/32. Never use 0.0.0.0/0 in production.')
param adminAllowedCidr string

@description('Resource tags.')
param tags object = {}

resource nsg 'Microsoft.Network/networkSecurityGroups@2023-11-01' = {
  name: nsgName
  location: location
  tags: tags
  properties: {
    securityRules: [
      {
        name: 'Allow-HTTPS-Inbound'
        properties: {
          priority: 100
          direction: 'Inbound'
          access: 'Allow'
          protocol: 'Tcp'
          sourceAddressPrefix: '*'
          sourcePortRange: '*'
          destinationAddressPrefix: '*'
          destinationPortRange: '443'
          description: 'Allow public HTTPS web and API traffic to Nginx reverse proxy'
        }
      }
      {
        name: 'Allow-HTTP-Redirect-ACME'
        properties: {
          priority: 110
          direction: 'Inbound'
          access: 'Allow'
          protocol: 'Tcp'
          sourceAddressPrefix: '*'
          sourcePortRange: '*'
          destinationAddressPrefix: '*'
          destinationPortRange: '80'
          description: 'Allow HTTP redirect to HTTPS and ACME certificate verification'
        }
      }
      {
        name: 'Allow-SSH-Admin-Only'
        properties: {
          priority: 120
          direction: 'Inbound'
          access: 'Allow'
          protocol: 'Tcp'
          sourceAddressPrefix: adminAllowedCidr
          sourcePortRange: '*'
          destinationAddressPrefix: '*'
          destinationPortRange: '22'
          description: 'Allow SSH administrative access strictly from trusted laptop CIDR'
        }
      }
      {
        name: 'Deny-SQL-Port-Inbound'
        properties: {
          priority: 200
          direction: 'Inbound'
          access: 'Deny'
          protocol: 'Tcp'
          sourceAddressPrefix: '*'
          sourcePortRange: '*'
          destinationAddressPrefix: '*'
          destinationPortRange: '1433'
          description: 'Explicitly deny any inbound SQL Server port 1433 traffic'
        }
      }
    ]
  }
}

output nsgId string = nsg.id
output nsgName string = nsg.name
