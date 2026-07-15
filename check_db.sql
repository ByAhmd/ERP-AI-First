SELECT COUNT(*) FROM "TenantUser";
SELECT u.email, tu."userId", tu."tenantId", tu.status FROM "TenantUser" tu JOIN "User" u ON tu."userId" = u.id;
SELECT id, name FROM "Tenant";
