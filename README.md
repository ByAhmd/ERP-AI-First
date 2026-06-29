# ERP AI

ERP AI is a Saudi-first AI-assisted ERP and accounting platform. This repository contains the initial backend and database foundation only. It does not implement the full ERP, AI features, ZATCA, tax filing, payroll, or production compliance workflows yet.

## Stack

- Monorepo with npm workspaces
- TypeScript
- NestJS backend API
- PostgreSQL
- Prisma ORM
- Zod and class-validator validation structure
- Docker Compose for local PostgreSQL
- ESLint and Prettier
- dotenv environment variables
- Jest tests

## Folder Structure

```text
apps/api                 NestJS API
apps/api/prisma          Prisma schema
apps/api/test            API tests
packages/shared          Shared constants, types, validation
docs                     Architecture, backend, database, security, KSA compliance notes
```

## Install

```bash
npm install
```

## Configure Environment

```bash
cp .env.example .env
```

Adjust `DATABASE_URL`, `PORT`, `API_PREFIX`, and `CORS_ORIGIN` as needed.

## Run PostgreSQL

```bash
docker compose up -d postgres
```

## Prisma

```bash
npm run prisma:generate
npm run prisma:migrate -- --name init
npm run prisma:studio
```

## Run API

```bash
npm run dev
```

Health check:

```bash
curl http://localhost:3000/api/v1/health
```

## Tests and Quality

```bash
npm run test
npm run lint
npm run format
npm run build
```

## Branch Workflow

No one should work directly on `main`.

Use short-lived feature branches:

```bash
git checkout -b feature/module-name
```

Open a pull request into `main` after tests pass and another team member reviews the change.

## Contribution Rules

- Keep controllers thin and call services only.
- Keep Prisma access inside services through `PrismaService`.
- Keep tenant boundaries explicit.
- Add or update tests for business logic.
- Add audit logs for sensitive operations.
- Do not add real Saudi compliance calculations without specialist review.
- Do not commit secrets.

## Pull Request Rules

- Explain the business reason for the change.
- List database migrations.
- Include test evidence.
- Note compliance or security impact.
- Request review before merging.

## Commit Messages

Use clear, imperative commit messages:

```text
Add health endpoint
Create tenant foundation
Add journal balance validation
```

## Current Scope

Implemented now:

- Backend NestJS foundation
- Prisma schema foundation
- Local PostgreSQL Docker Compose
- Health endpoint
- Tenant, user, role, permission, audit, accounting, and Saudi compliance module structure
- Draft journal entry balance validation
- Initial tests

Not implemented now:

- Frontend
- Full authentication
- AI logic
- Full accounting engine
- Real ZATCA XML, QR codes, cryptographic stamps, or Fatoora integration
- VAT, Zakat, Withholding Tax, GOSI, or WPS filing logic

## Next Recommended Task

Implement authentication and tenant membership enforcement next, including password hashing, login/session strategy, tenant guard behavior, role/permission checks, and audit logging for all access-control changes.
