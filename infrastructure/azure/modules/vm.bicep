targetScope = 'resourceGroup'

@description('Name of the Linux Virtual Machine.')
param vmName string

@description('Name of the Network Interface attached to the VM.')
param nicName string

@description('Azure region for deployment.')
param location string = resourceGroup().location

@description('Eligible low-cost burstable VM size (verify regional availability before deploying: e.g., Standard_B1s, Standard_B2ats_v2, or Standard_B1ms).')
param vmSize string = 'Standard_B1s'

@description('Linux administrator username.')
param adminUsername string = 'nexamindops'

@description('SSH public key (ed25519) for passwordless login. Private key stays on your local laptop.')
param sshPublicKey string

@description('Subnet resource ID.')
param subnetId string

@description('Public IP resource ID.')
param publicIpId string

@description('User-Assigned Managed Identity resource ID.')
param managedIdentityId string

@description('OS disk size in GiB (64 GiB fits within Azure 12-month free managed disk allowance).')
@minValue(30)
@maxValue(128)
param osDiskSizeGb int = 64

@description('Resource tags.')
param tags object = {}

var cloudInitScript = loadTextContent('../cloud-init/nexamind-init.yaml')

resource nic 'Microsoft.Network/networkInterfaces@2023-11-01' = {
  name: nicName
  location: location
  tags: tags
  properties: {
    ipConfigurations: [
      {
        name: 'ipconfig1'
        properties: {
          privateIPAllocationMethod: 'Dynamic'
          subnet: {
            id: subnetId
          }
          publicIPAddress: {
            id: publicIpId
          }
        }
      }
    ]
  }
}

resource linuxVm 'Microsoft.Compute/virtualMachines@2024-07-01' = {
  name: vmName
  location: location
  tags: tags
  identity: {
    type: 'UserAssigned'
    userAssignedIdentities: {
      '${managedIdentityId}': {}
    }
  }
  properties: {
    hardwareProfile: {
      vmSize: vmSize
    }
    osProfile: {
      computerName: vmName
      adminUsername: adminUsername
      customData: base64(cloudInitScript)
      linuxConfiguration: {
        disablePasswordAuthentication: true
        patchSettings: {
          patchMode: 'AutomaticByPlatform'
        }
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
        name: '${vmName}-osdisk'
        createOption: 'FromImage'
        diskSizeGB: osDiskSizeGb
        deleteOption: 'Delete'
        managedDisk: {
          storageAccountType: 'Standard_LRS'
        }
      }
    }
    networkProfile: {
      networkInterfaces: [
        {
          id: nic.id
          properties: {
            deleteOption: 'Delete'
          }
        }
      ]
    }
    diagnosticsProfile: {
      bootDiagnostics: {
        enabled: true
      }
    }
  }
}

output vmId string = linuxVm.id
output vmName string = linuxVm.name
output nicId string = nic.id
output privateIpAddress string = nic.properties.ipConfigurations[0].properties.privateIPAddress
