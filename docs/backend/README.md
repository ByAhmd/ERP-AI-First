# Backend

The API lives in `apps/api` and uses NestJS.

Current foundations:

- Health endpoint at `GET /api/v1/health`
- Tenant creation and listing placeholders
- Users, auth, roles, permissions, and audit log module surfaces
- Chart of accounts placeholder creation and listing
- Draft journal entry creation with balance validation
- Saudi compliance placeholder modules

Business logic belongs in services. Controllers should not call Prisma directly.
