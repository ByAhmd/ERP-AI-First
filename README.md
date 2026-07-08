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

### Completed Phases

- [x] **Phase 1 — Identity, Security, RBAC & Audit**
  - Full Authentication (Argon2id, JWT, Redis refresh tokens)
  - Security Hardening (Helmet, Throttler, Global Exception Filter)
  - Tenant context enforcement (Interceptors, Guards, Decorators)
  - RBAC (Roles & Permissions with DB-driven dynamic guards)
  - Audit Logging (Interceptor, `@Auditable()` decorator, paginated querying)
  - Swagger/OpenAPI documentation

- [x] **Phase 2 — Core Accounting Engine**
  - Fiscal Years & Accounting Periods Lifecycle (Open/Close/Reopen)
  - Hierarchical Chart of Accounts (COA) with SME template seeding
  - Journal Entries (Immutable Ledger, Auto-numbering, Reversals, `decimal.js` validation)
  - General Ledger & Trial Balance generation
  - Multi-Currency support (Configurable base currency, Exchange Rate tables)

- [x] **Phase 3 — Business Documents**
  - Contacts (Customer, Supplier, Employee registry with VAT/CR numbers)
  - Gapless Document Numbering Sequences (atomic, concurrency-safe)
  - Sales & Purchase Invoices (Draft → Approve → auto Journal Entry)
  - Credit Notes & Debit Notes (unified Invoice model with type enum)
  - Payments & Receipts (allocation against invoices, auto Journal Entry)
  - Sub-ledger architecture (contactId on JournalEntryLine)

- [x] **Phase 4 — Saudi Compliance (KSA)**
  - VAT Engine (Return calculation, tax codes)
  - ZATCA Phase 2 Data Prep (PIH chaining, UUID generation)
  - Zakat Calculation Framework (Trial balance based provision estimation)
  - Withholding Tax (WHT deduction in payments)
  - Dual Date System (Hijri/Gregorian API conversion)
  - GOSI & WPS (Employee profiles and payroll journal entry generation)

- [x] **Phase 5 — Extended Accounting Modules**
  - Bank Reconciliation (Statement upload, automatic matching)
  - Fixed Assets (Capitalization, straight-line depreciation schedules)
  - Inventory Valuation (Weighted Average Cost tracking, Stock movements)
  - Multi-Warehouse Control (Transfers)
  - Consolidation Engine (Parent-Child translation & Trial Balance rollup)
  - Payroll Integration (Gross-to-net calculators, automated journal posting)

- [x] **Phase 6 — Frontend Web Application**
  - Next.js Web App Scaffolding (Monorepo integration)
  - Custom Vanilla CSS Design System (Sleek dark mode, Glassmorphism, Micro-animations)
  - API Integration Layer (Next.js rewrites to NestJS)
  - Authentication Flow (Login UI + HTTP-Only Cookie implementation)
  - Dashboard & Ledger Views (Financial summary charts layout)

### Next Phase

- [ ] **Phase 7 — AI Engine & Advanced Features** — AI Document Understanding, Natural Language Queries, and Real-time Notifications.

### Not Yet Implemented (External API Integrations)

- Frontend UI
- AI logic
- **Live B2G Integration:** Actual transmission of UBL 2.1 XML to ZATCA Fatoora API, real cryptographic signing with live certificates, and complex Base64 TLV QR code generation.
- **Live Government Filing:** Automated transmission of calculated returns directly to government portals (ZATCA, Mudad/WPS, GOSI). (Note: The internal calculation and ledger posting for all of these *is* completed).
