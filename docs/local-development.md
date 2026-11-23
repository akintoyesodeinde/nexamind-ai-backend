# Local Development Guide — NexaMind AI Backend

This workstation profile guide supports both full local Docker development and reduced-memory setups (e.g., 8 GB host RAM / 3.76 GB Docker Linux VM).

## Prerequisites
- Docker Desktop (Linux container engine enabled)
- Git
- Node.js 22+ (for running `nexamind-ai-frontend` natively on host)
- Host PHP is **not required** — PHP 8.4, Composer, Microsoft ODBC Driver 18, and `pdo_sqlsrv` run inside the `backend` container.

## Profile 1: Reduced Local Profile (`minimal` — Recommended for 8 GB RAM)
Runs:
- `sqlserver`: SQL Server 2025 Developer capped at `MSSQL_MEMORY_LIMIT_MB=2048`
- `azurite`: Azure Blob Storage emulator (blob-only, ~40 MB RAM)
- `backend`: Nginx + PHP-FPM 8.4 (`pm = ondemand`) on `http://127.0.0.1:8000`

Queue jobs are executed on demand rather than keeping a permanent polling worker running:
```bash
docker compose --profile minimal up -d --build
docker compose exec backend php artisan queue:work database --stop-when-empty
```

## Profile 2: Azure SQL Development Profile (`cloud-db` — Lowest Local RAM)
Skips the local SQL Server container (~2 GB RAM saved) and connects directly to your Azure SQL Database Free Tier sandbox:
1. Update `.env` with your Azure SQL host, database, credentials, `DB_ENCRYPT=yes`, and `DB_TRUST_SERVER_CERTIFICATE=false`.
2. Start only `backend` and `azurite`:
```bash
docker compose --profile cloud-db up -d --build
```

## Profile 3: Full Containerized Stack (`full`)
Includes the dedicated background `worker` container:
```bash
docker compose --profile full up -d --build
```

## Verifying SQL Server & Vector Capabilities
```bash
docker compose exec backend php artisan nexamind:probe-sqlserver
```

## Running Tests & Static Analysis
Automated tests run against the dedicated `nexamind_test` database so local development data is never destroyed:
```bash
docker compose exec backend ./vendor/bin/pint --test
docker compose exec backend ./vendor/bin/phpstan analyse
docker compose exec backend php artisan test
```
