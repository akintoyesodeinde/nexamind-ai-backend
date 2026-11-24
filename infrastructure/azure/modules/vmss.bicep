targetScope = 'resourceGroup'

// OPTIONAL FUTURE SCALING MODULE — Flexible-Orchestration Azure VM Scale Set (VMSS)
// NOT deployed during the initial low-cost Free Trial sandbox (main.bicep sets deployVmss = false).
// Enabling a VMSS with multiple instances and a Standard Load Balancer incurs additional compute and networking charges.

@description('Name of the Flexible-Orchestration VM Scale Set.')
param vmssName string

@description('Azure region for deployment.')
param location string = resourceGroup().location

@description('VM SKU size for scale-set instances.')
param vmSize string = 'Standard_B1ms'

@description('Initial VM instance count.')
@minValue(1)
@maxValue(5)
param instanceCount int = 1

@description('Linux administrator username.')
param adminUsername string = 'nexamindops'

@description('SSH public key for passwordless login.')
param sshPublicKey string

@description('Subnet resource ID.')
param subnetId string

@description('Network Security Group resource ID.')
param nsgId string

@description('User-Assigned Managed Identity resource ID.')
param managedIdentityId string

@description('Resource tags.')
param tags object = {}

var cloudInitScript = replace(loadTextContent('../cloud-init/nexamind-init.yaml'), '\r\n', '\n')

resource vmss 'Microsoft.Compute/virtualMachineScaleSets@2024-07-01' = {
  name: vmssName
  location: location
  tags: tags
  sku: {
    name: vmSize
    tier: 'Standard'
    capacity: instanceCount
  }
  identity: {
    type: 'UserAssigned'
    userAssignedIdentities: {
      '${managedIdentityId}': {}
    }
  }
  properties: {
    orchestrationMode: 'Flexible'
    platformFaultDomainCount: 1
    singlePlacementGroup: false
    virtualMachineProfile: {
      osProfile: {
        computerNamePrefix: take(replace(vmssName, '-', ''), 9)
        adminUsername: adminUsername
        customData: base64(cloudInitScript)
        linuxConfiguration: {
          disablePasswordAuthentication: true
          ssh: {
            publicKeys: [
              {
                path: '/home/${adminUsername}/.ssh/authorized_keys'
                keyData: sshPublicKey
              }
            ]
          }
        }
      }
      storageProfile: {
        imageReference: {
          publisher: 'Canonical'
          offer: 'ubuntu-24_04-lts'
          sku: 'server'
          version: 'latest'
        }
        osDisk: {
          createOption: 'FromImage'
          diskSizeGB: 64
          managedDisk: {
            storageAccountType: 'Standard_LRS'
          }
        }
      }
      networkProfile: {
        networkApiVersion: '2023-11-01'
        networkInterfaceConfigurations: [
          {
            name: '${vmssName}-nic'
            properties: {
              primary: true
              networkSecurityGroup: {
                id: nsgId
              }
              ipConfigurations: [
                {
                  name: 'ipconfig1'
                  properties: {
                    primary: true
                    subnet: {
                      id: subnetId
                    }
                  }
                }
              ]
            }
          }
        ]
      }
    }
  }
}

output vmssId string = vmss.id
output vmssName string = vmss.name
