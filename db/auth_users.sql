-- Create authentication table for system access.
IF NOT EXISTS (SELECT 1 FROM sys.objects WHERE object_id = OBJECT_ID(N'[dbo].[AuthUsers]') AND type in (N'U'))
BEGIN
    CREATE TABLE [dbo].[AuthUsers] (
        [AuthUserId] INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        [Username] NVARCHAR(80) NOT NULL UNIQUE,
        [PasswordHash] NVARCHAR(255) NOT NULL,
        [Role] NVARCHAR(20) NOT NULL DEFAULT 'user',
        [IsActive] BIT NOT NULL DEFAULT 1,
        [CreatedAt] DATETIME NOT NULL DEFAULT GETDATE(),
        [UpdatedAt] DATETIME NOT NULL DEFAULT GETDATE()
    );
END

-- Seed the default administrator account if missing.
IF NOT EXISTS (SELECT 1 FROM [dbo].[AuthUsers] WHERE [Username] = 'admin')
BEGIN
    INSERT INTO [dbo].[AuthUsers] ([Username], [PasswordHash], [Role], [IsActive])
    VALUES ('admin', '$2y$12$MNjVoftkh63m/aKPgb7mR.dEKGl8bgMlNmU6i0lcrH1nmHRYRHqpi', 'admin', 1);
END
