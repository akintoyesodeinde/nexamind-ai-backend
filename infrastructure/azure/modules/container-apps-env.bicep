targetScope = 'resourceGroup'

@description('Container Apps Managed Environment name.')
param environmentName string

@description('Backend Laravel API Container App name.')
param apiAppName string = 'ca-nexamind-api'

@description('Frontend React/Nginx SPA Container App name.')
param webAppName string = 'ca-nexamind-web'

@description('Scheduled Laravel Queue Worker Container Apps Job name.')
param workerJobName string = 'caj-nexamind-worker'

@description('Azure region for deployment.')
param location string = resourceGroup().location

@description('Resource ID of the User-Assigned Managed Identity.')
param managedIdentityId string

@description('Name of the existing Log Analytics Workspace in this resource group.')
param logAnalyticsWorkspaceName string

@description('Name of the existing Storage Account in this resource group.')
param storageAccountName string

@description('Private blob container name for documents.')
param storageContainerName string = 'nexamind-documents'

@description('Azure Container Registry login server (e.g., acrnexaminddev.azurecr.io).')
param acrLoginServer string

@description('Container image reference for the backend API and worker job.')
param backendImage string

@description('Container image reference for the frontend Nginx SPA.')
param frontendImage string

@description('Azure SQL logical server FQDN.')
param sqlServerFqdn string

@description('Azure SQL Database name.')
param sqlDatabaseName string

@description('Azure SQL admin login.')
param sqlAdminLogin string

@description('Azure SQL admin password.')
@secure()
param sqlAdminPassword string

@description('Laravel APP_KEY secret (base64:...).')
@secure()
param appKey string

@description('Default AI provider key (mock, openai, gemini).')
@allowed(['mock', 'openai', 'gemini'])
param aiDefaultProvider string = 'mock'

@description('Optional OpenAI API key (leave empty when using mock provider).')
@secure()
param openAiApiKey string = ''

@description('Optional Gemini API key (leave empty when using mock provider).')
@secure()
param geminiApiKey string = ''

@description('Cron expression for scale-to-zero queue worker job (UTC).')
param workerCronExpression string = '*/5 * * * *'

@description('Resource tags.')
param tags object = {}

resource logAnalytics 'Microsoft.OperationalInsights/workspaces@2023-09-01' existing = {
  name: logAnalyticsWorkspaceName
}

resource storageAccount 'Microsoft.Storage/storageAccounts@2023-05-01' existing = {
  name: storageAccountName
}

var storageConnectionString = 'DefaultEndpointsProtocol=https;AccountName=${storageAccount.name};AccountKey=${storageAccount.listKeys().keys[0].value};EndpointSuffix=${environment().suffixes.storage}'

resource managedEnv 'Microsoft.App/managedEnvironments@2024-03-01' = {
  name: environmentName
  location: location
  tags: tags
  properties: {
    appLogsConfiguration: {
      destination: 'log-analytics'
      logAnalyticsConfiguration: {
        customerId: logAnalytics.properties.customerId
        sharedKey: logAnalytics.listKeys().primarySharedKey
      }
    }
    workloadProfiles: [
      {
        name: 'Consumption'
        workloadProfileType: 'Consumption'
      }
    ]
  }
}

var backendSecrets = [
  {
    name: 'app-key'
    value: appKey
  }
  {
    name: 'db-password'
    value: sqlAdminPassword
  }
  {
    name: 'azure-storage-conn'
    value: storageConnectionString
  }
  {
    name: 'openai-api-key'
    value: empty(openAiApiKey) ? 'unused-mock-mode' : openAiApiKey
  }
  {
    name: 'gemini-api-key'
    value: empty(geminiApiKey) ? 'unused-mock-mode' : geminiApiKey
  }
]

var backendSharedEnv = [
  { name: 'APP_NAME', value: 'NexaMind AI' }
  { name: 'APP_ENV', value: 'production' }
  { name: 'APP_DEBUG', value: 'false' }
  { name: 'APP_KEY', secretRef: 'app-key' }
  { name: 'APP_TIMEZONE', value: 'UTC' }
  { name: 'LOG_CHANNEL', value: 'stderr' }
  { name: 'LOG_LEVEL', value: 'info' }
  { name: 'DB_CONNECTION', value: 'sqlsrv' }
  { name: 'DB_HOST', value: sqlServerFqdn }
  { name: 'DB_PORT', value: '1433' }
  { name: 'DB_DATABASE', value: sqlDatabaseName }
  { name: 'DB_USERNAME', value: sqlAdminLogin }
  { name: 'DB_PASSWORD', secretRef: 'db-password' }
  { name: 'DB_ENCRYPT', value: 'yes' }
  { name: 'DB_TRUST_SERVER_CERTIFICATE', value: 'false' }
  { name: 'QUEUE_CONNECTION', value: 'database' }
  { name: 'CACHE_STORE', value: 'database' }
  { name: 'SESSION_DRIVER', value: 'database' }
  { name: 'SESSION_SECURE_COOKIE', value: 'true' }
  { name: 'SESSION_SAME_SITE', value: 'none' }
  { name: 'AZURE_STORAGE_CONNECTION_STRING', secretRef: 'azure-storage-conn' }
  { name: 'AZURE_STORAGE_CONTAINER', value: storageContainerName }
  { name: 'AI_DEFAULT_PROVIDER', value: aiDefaultProvider }
  { name: 'AI_EMBEDDING_DIMENSIONS', value: '1536' }
  { name: 'OPENAI_API_KEY', secretRef: 'openai-api-key' }
  { name: 'GEMINI_API_KEY', secretRef: 'gemini-api-key' }
]

// 1. Laravel Modular Monolith REST API Container App (Scale-to-Zero Consumption)
resource apiApp 'Microsoft.App/containerApps@2024-03-01' = {
  name: apiAppName
  location: location
  tags: tags
  identity: {
    type: 'UserAssigned'
    userAssignedIdentities: {
      '${managedIdentityId}': {}
    }
  }
  properties: {
    managedEnvironmentId: managedEnv.id
    workloadProfileName: 'Consumption'
    configuration: {
      activeRevisionsMode: 'Multiple'
      ingress: {
        external: true
        targetPort: 8080
        transport: 'http'
        allowInsecure: false
      }
      registries: [
        {
          server: acrLoginServer
          identity: managedIdentityId
        }
      ]
      secrets: backendSecrets
    }
    template: {
      containers: [
        {
          name: 'nexamind-api'
          image: backendImage
          env: backendSharedEnv
          resources: {
            cpu: json('0.5')
            memory: '1Gi'
          }
          probes: [
            {
              type: 'Liveness'
              httpGet: {
                path: '/api/v1/health/live'
                port: 8080
              }
              initialDelaySeconds: 15
              periodSeconds: 30
            }
            {
              type: 'Readiness'
              httpGet: {
                path: '/api/v1/health/ready'
                port: 8080
              }
              initialDelaySeconds: 10
              periodSeconds: 15
            }
          ]
        }
      ]
      scale: {
        minReplicas: 0
        maxReplicas: 2
        rules: [
          {
            name: 'http-scaling'
            http: {
              metadata: {
                concurrentRequests: '30'
              }
            }
          }
        ]
      }
    }
  }
}

// 2. React + Nginx SPA Frontend Container App (Scale-to-Zero Consumption)
resource webApp 'Microsoft.App/containerApps@2024-03-01' = {
  name: webAppName
  location: location
  tags: tags
  identity: {
    type: 'UserAssigned'
    userAssignedIdentities: {
      '${managedIdentityId}': {}
    }
  }
  properties: {
    managedEnvironmentId: managedEnv.id
    workloadProfileName: 'Consumption'
    configuration: {
      activeRevisionsMode: 'Multiple'
      ingress: {
        external: true
        targetPort: 8080
        transport: 'http'
        allowInsecure: false
      }
      registries: [
        {
          server: acrLoginServer
          identity: managedIdentityId
        }
      ]
    }
    template: {
      containers: [
        {
          name: 'nexamind-web'
          image: frontendImage
          resources: {
            cpu: json('0.25')
            memory: '0.5Gi'
          }
          probes: [
            {
              type: 'Liveness'
              httpGet: {
                path: '/healthz'
                port: 8080
              }
              initialDelaySeconds: 5
              periodSeconds: 30
            }
            {
              type: 'Readiness'
              httpGet: {
                path: '/healthz'
                port: 8080
              }
              initialDelaySeconds: 3
              periodSeconds: 15
            }
          ]
        }
      ]
      scale: {
        minReplicas: 0
        maxReplicas: 2
        rules: [
          {
            name: 'http-scaling'
            http: {
              metadata: {
                concurrentRequests: '50'
              }
            }
          }
        ]
      }
    }
  }
}

// 3. Scheduled Scale-to-Zero Queue Worker Job (never defeats scale-to-zero)
resource workerJob 'Microsoft.App/jobs@2024-03-01' = {
  name: workerJobName
  location: location
  tags: tags
  identity: {
    type: 'UserAssigned'
    userAssignedIdentities: {
      '${managedIdentityId}': {}
    }
  }
  properties: {
    environmentId: managedEnv.id
    workloadProfileName: 'Consumption'
    configuration: {
      triggerType: 'Schedule'
      replicaTimeout: 600
      replicaRetryLimit: 2
      scheduleTriggerConfig: {
        cronExpression: workerCronExpression
        parallelism: 1
        replicaCompletionCount: 1
      }
      registries: [
        {
          server: acrLoginServer
          identity: managedIdentityId
        }
      ]
      secrets: backendSecrets
    }
    template: {
      containers: [
        {
          name: 'nexamind-worker'
          image: backendImage
          command: [
            'php'
            'artisan'
            'queue:work'
            'database'
            '--stop-when-empty'
            '--tries=3'
            '--max-time=540'
          ]
          env: backendSharedEnv
          resources: {
            cpu: json('0.25')
            memory: '0.5Gi'
          }
        }
      ]
    }
  }
}

output managedEnvironmentId string = managedEnv.id
output apiAppName string = apiApp.name
output apiUrl string = 'https://${apiApp.properties.configuration.ingress.fqdn}'
output webAppName string = webApp.name
output webUrl string = 'https://${webApp.properties.configuration.ingress.fqdn}'
output workerJobName string = workerJob.name
