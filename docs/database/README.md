# Database

The initial database foundation uses PostgreSQL with Prisma.

Core models include tenants, users, tenant memberships, roles, permissions, audit logs, branches, cost centers, accounting periods, chart of accounts, journal entries, and journal entry lines.

Financial amounts use Decimal fields. Business tables are tenant-aware where applicable.
