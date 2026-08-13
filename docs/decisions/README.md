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

**Superseded by ADR-011.** The application ran in Linux containers locally.

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

## ADR-011 — Herd and DBngin, superseding containers

**Decided.** Local development runs on Laravel Herd and DBngin. Docker is
removed. Supersedes [ADR-006](#adr-006--containerised-development).

ADR-006 accepted containers to keep Horizon runnable locally, and judged that
declaring Horizon for production while running bare workers in development would
leave the two diverging on the component most likely to fail silently. That
reasoning was sound and is now outweighed.

What changed is that the same developer maintains a second Laravel application,
StockFlow, on Herd and DBngin. Two toolchains on one machine cost more than the
divergence they were avoiding: two ways to run a migration, two places a port
can collide, and a container that has to be running before a test can be read.
Aligning both projects removes that.

The cost is accepted rather than solved. `laravel/horizon` requires `ext-pcntl`
and `ext-posix`, which no Windows PHP build provides, so `composer.json`
declares both satisfied under `config.platform` to let the dependency resolve.
The `horizon` command cannot run on a development machine. Locally the queue is
`database`, worked by `queue:listen`; production runs on Linux, where Horizon is
still the intended worker. Redis stays configured and unused — cache and
sessions are files.

Tests remain on MySQL, on a separate `erp_ai_testing` database. ADR-002's
reasoning is untouched: an accounting ledger tested on a different engine than
it runs on is not tested.
