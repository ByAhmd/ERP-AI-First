# Documentation

Binding reference for the ERP platform. Where this documentation and the code
disagree, the disagreement is a defect — raise it rather than working around it.

## Status

Laravel 13 · Filament 5 · MySQL 8 · PHP 8.4, at the repository root.

The NestJS, Prisma, PostgreSQL and Next.js codebase that preceded it has been
deleted. It lived in `apps/` and `packages/` and is recoverable from git history
up to commit `f2ee79f`; nothing in the current tree depends on it.

## Contents

| Document | Covers |
|---|---|
| [Architecture](architecture/README.md) | Layering, module boundaries, where logic belongs |
| [Database](database/README.md) | Schema conventions, integrity rules, denormalisation exceptions |
| [Security](security/README.md) | Multi-tenancy, authorisation, the isolation model |
| [Backend](backend/README.md) | Application structure, local development, testing |
| [KSA compliance](ksa-compliance/README.md) | ZATCA, VAT, Zakat, WHT, GOSI/WPS, Hijri dates |
| [Decisions](decisions/README.md) | Architecture decision record |

## Product mandate

The platform must reach feature parity with Qoyod before original features are
added. Parity scope is recorded in [decisions](decisions/README.md).

## Why the rebuild

The predecessor system had three defects that were architectural rather than
incidental, and are the reason a rewrite was chosen over repair:

1. **Tenant isolation was advisory.** A client-supplied `x-tenant-id` header was
   trusted without verifying membership, and filtering was applied per service,
   so any route that forgot it was readable across tenants.
2. **VAT never reached the ledger.** Invoice posting credited revenue with the
   tax-inclusive line total and wrote no tax line, so revenue was overstated and
   the VAT return — computed from the invoice table rather than the ledger —
   could never reconcile to the trial balance.
3. **Document numbering was not gapless.** Journal entry numbers derived from
   `COUNT(*) + 1`, and reversals shared the counter, so every reversal created a
   permanent gap and a collision risk.

Each has a corresponding structural control in the new platform. See
[Security](security/README.md) and [Database](database/README.md).
