# Architecture

ERP AI starts as a TypeScript monorepo with a NestJS API, PostgreSQL, Prisma, and a shared package for cross-application constants and validation.

The backend follows a module-first structure. Controllers accept requests, services hold business logic, and database access is isolated through `PrismaService`.

Future modules should keep tenant boundaries explicit, add audit logging for sensitive operations, and avoid deleting legally significant accounting records such as posted journal entries.
