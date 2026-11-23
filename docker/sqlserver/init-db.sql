IF DB_ID(N'nexamind') IS NULL
BEGIN
    CREATE DATABASE [nexamind];
END
GO

IF DB_ID(N'nexamind_test') IS NULL
BEGIN
    CREATE DATABASE [nexamind_test];
END
GO
