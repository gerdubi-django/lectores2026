-- Create department access mapping table for auth users.
IF NOT EXISTS (SELECT 1 FROM sys.objects WHERE object_id = OBJECT_ID(N'[dbo].[AuthUserDepartments]') AND type in (N'U'))
BEGIN
    CREATE TABLE [dbo].[AuthUserDepartments] (
        [AuthUserDepartmentId] INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        [AuthUserId] INT NOT NULL,
        [Deptid] INT NOT NULL,
        [CreatedAt] DATETIME NOT NULL DEFAULT GETDATE()
    );
END

-- Prevent duplicate assignments per user/department.
IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = 'UX_AuthUserDepartments_AuthUserId_Deptid'
      AND object_id = OBJECT_ID(N'[dbo].[AuthUserDepartments]')
)
BEGIN
    CREATE UNIQUE INDEX [UX_AuthUserDepartments_AuthUserId_Deptid]
    ON [dbo].[AuthUserDepartments] ([AuthUserId], [Deptid]);
END
