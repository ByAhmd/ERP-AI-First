# Architecture Decisions

Decisions that constrain future work, with the reasoning that produced them.
Reversing one is a decision in itself and belongs in this file.

---

## ADR-001 — Rebuild rather than repair

**Decided.** Greenfield Laravel application in `platform/`; the NestJS codebase
remains as read-only reference and is deleted at parity.

Three defects in the predecessor were architectural rather than incidental:
trusted tenant headers, VAT that never reached the ledger, and non-gapless
document numbering. Correcting them in place would have touched the tenancy
model, the posting engine and the numbering system — most of the application —
without producing a cleaner result.

## ADR-002 — No data migration

**Decided.** The new database starts empty; migrations and seeders only.

The predecessor's data was development-only, and its ledger balances are wrong
wherever VAT was involved. Migrating them would import known-bad figures and
require correcting entries to fix.

## ADR-003 — Single database multi-tenancy

**Decided.** `company_id` on every tenant-owned table, enforced by a global scope
that fails closed, with Filament tenancy for panel company selection.

Database-per-company gives stronger physical isolation but makes the
consolidation module — which reads across companies in a group — substantially
harder, and turns every migration into an N-company operation. MySQL has no true
row-level security, so scope enforcement in the ORM plus verified membership is
the strongest practical control.

`Tenant` is renamed `Company` throughout.

## ADR-004 — Filament panel *and* a versioned REST API

**Decided.** Filament is the primary interface; `/api/v1` is built from the
foundation, not retro-fitted.

Initially the API was to be retired. Qoyod parity reversed that: mobile
applications, Zapier, Salla, Zid, WooCommerce, Shopify and seven payment gateways
all require an HTTP API. Retro-fitting one across forty-five models would have
cost far more than designing for it.

## ADR-005 — ULID primary keys

**Decided.** ULIDs everywhere. See [database](../database/README.md).

## ADR-006 — Containerised development

**Decided.** The application runs in Linux containers locally.

Horizon requires `ext-pcntl`, which Windows cannot provide in any PHP build. The
alternative — declaring Horizon for production and running bare queue workers
locally — would have left dev and production diverging on the component most
likely to fail silently.

## ADR-007 — Third-party packages

**Decided.** `spatie/laravel-permission` (teams), `owen-it/laravel-auditing`,
`bezhansalleh/filament-shield`, `brick/money`, `laravel/sanctum`,
`laravel/horizon`.

**Rejected:** `alkoumi/laravel-hijri-date` — no release since November 2020 and
no declared dependencies. Replaced with PHP's `intl` extension, which has no
abandonment risk and is authoritative for Umm al-Qura.

`brick/money` is pre-1.0 (0.14.0) and accepted knowingly: it is actively
released and is the de-facto PHP money library. Hand-rolling ledger arithmetic
was judged the greater risk.

Package selection requires explicit approval. Maintenance status is checked
before adoption, not assumed.

## ADR-008 — Documented denormalisation exceptions

**Decided.** The no-denormalisation rule is relaxed for four specific cases, each
with a reconciliation path. The list in
[database](../database/README.md#denormalisation-exceptions) is exhaustive;
additions require the same approval.

An offline-capable POS and real-time multi-branch stock are not achievable under
a strict reading of the rule.

## ADR-009 — Qoyod feature parity precedes original features

**Decided.** The platform must match Qoyod before differentiating features are
built.

Parity scope, verified against Qoyod's published feature matrix and product
pages:

**Sales** — customers, invoices, receipts, credit notes, quotes, custom fields
**Purchases** — suppliers, invoices, simple invoices, debit notes, supplier
receipts, purchase orders
**Inventory** — products, services, stored products, expense items, unit
conversion, stock counts, transfers, bundled products, raw materials,
manufacturing orders, opening balances
**Accounting** — chart of accounts, manual and guided entries, dimensions,
budgets, recurring transactions, opening balances for accounts, customers,
suppliers and fixed assets
**Projects** — projects, tasks
**Fixed assets** — assets, depreciation, transfer, expenses, disposal,
improvements
**POS** — offline mode with sync, barcode including weight barcodes, split
payments, store credit, instalments, PIN user switching, per-employee tracking,
session reports
**Payroll** — per-employee, GOSI
**Integrations** — ZATCA phase 1 and 2, Salla, Zid, WooCommerce, Shopify,
Zapier, public API, payment gateways, WhatsApp
**Platform** — mobile applications, multi-location, RBAC, document designer,
attachments, Arabic and English

## ADR-010 — Two QA gates withdrawn

**Decided.** "UI matches prototype" and "form matches catalogue" are removed from
the quality checklist.

Neither artefact existed, making the gates unverifiable. Filament conventions
plus the Arabic/RTL requirements govern interface work instead.
