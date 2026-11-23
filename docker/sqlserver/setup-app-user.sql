-- NexaMind AI — Least-Privilege Azure SQL Application User Bootstrap
-- Run ONCE against the target Azure SQL Database (`nexamind`) as the SQL admin.
-- NEVER use the server administrator account (`nexamindadmin`) for ordinary Laravel runtime queries!
-- Replace <STRONG_APP_PASSWORD> before executing (do NOT commit a real password).

IF NOT EXISTS (SELECT 1 FROM sys.database_principals WHERE name = N'nexamind_app')
BEGIN
    CREATE USER [nexamind_app] WITH PASSWORD = N'<STRONG_APP_PASSWORD>';
END
GO

-- Runtime read/write privileges only (no db_owner / server-level admin privileges)
ALTER ROLE [db_datareader] ADD MEMBER [nexamind_app];
ALTER ROLE [db_datawriter] ADD MEMBER [nexamind_app];
-- Allow schema migrations during controlled CI/CD deployment runs
ALTER ROLE [db_ddladmin] ADD MEMBER [nexamind_app];
GO

-- Verify assigned database roles
SELECT dp.name AS principal_name, r.name AS role_name
FROM sys.database_role_members drm
JOIN sys.database_principals dp ON drm.member_principal_id = dp.principal_id
JOIN sys.database_principals r ON drm.role_principal_id = r.principal_id
WHERE dp.name = N'nexamind_app';
GO
