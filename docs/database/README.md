# Database

MySQL 8.4, InnoDB, `utf8mb4` / `utf8mb4_0900_ai_ci`, `READ-COMMITTED` isolation.

## Isolation level

`READ-COMMITTED`, not MySQL's `REPEATABLE-READ` default. `REPEATABLE-READ` takes
gap locks on `SELECT ... FOR UPDATE`, which is the mechanism the gapless document
numbering allocator depends on; under load those gap locks serialise unrelated
document creation and deadlock.

## Keys

**ULIDs**, not auto-increment integers and not UUIDv4.

Auto-increment keys leak business volume and are enumerable in URLs. UUIDv4 is
random, so as a clustered primary key it scatters inserts across the B-tree and
fragments every secondary index — measurably so at ledger volumes. ULIDs are
unguessable *and* lexicographically sortable, so inserts stay append-ordered.

Packages that assume integer keys must be adapted, not accommodated. Three
published migrations were rewritten for this: `personal_access_tokens`,
`audits`, and the spatie permission tables all shipped `morphs()` /
`unsignedBigInteger` defaults.

## Money

`DECIMAL(19,4)` via the `money()` schema macro, cast through `MoneyCast` to a
`Brick\Money\Money` value object.

Scale is **4, not 2**. Unit prices, exchange conversions and per-line tax all
produce fractions below the minor unit; rounding them at storage time is how
ledgers drift from their source documents. Rounding to the currency's natural
scale happens deliberately at posting, not implicitly on every write.

Floating point is never used for money anywhere in this system.

## Integrity rules

- Every foreign key is declared. Loose string references are not acceptable —
  the predecessor's `elimination_entries` and `period_exchange_rates` carried
  `tenant_id`, `period_id` and `journal_entry_id` as unconstrained strings.
- Every tenant-owned table carries `company_id` with a foreign key, and uses the
  `BelongsToCompany` trait. See [security](../security/README.md).
- Unique constraints express real business rules: document numbers are unique
  per company, not globally.
- Soft deletes apply to **master data only** — companies, contacts, items.
  Posted ledger records are never deleted or soft-deleted; correction is by
  reversal, because a deleted posting destroys the audit trail that the record
  exists to provide.
- Amounts are never stored where they can be derived, except as listed below.

## Denormalisation exceptions

The default rule is that calculated values are not stored. Each exception below
is deliberate, has a stated reason, and has a defined path back to truth. **This
list is exhaustive — adding to it requires the same explicit approval.**

| # | Stored value | Why | Reconciliation |
|---|---|---|---|
| 1 | `audits.company_id` | Reading, retaining or purging one company's history would otherwise require a union across every audited table | Derivable from the audited row; stamped by `AuditsCompany` |
| 2 | Inventory running balances per item/warehouse | Real-time multi-branch stock and POS availability cannot re-aggregate the full movement history per lookup | Rebuild job recomputes from `inventory_transactions`; discrepancy is reported, not silently corrected |
| 3 | Document header totals (`sub_total`, `tax_total`, `total`) | Every list view and aged report reads them; recomputing per row makes listings unusable at volume | Recalculated on line change within the same transaction; a verification job re-derives and reports drift |
| 4 | POS offline local replica | An offline-capable POS is impossible without client-side data | Server remains authoritative; sync reconciles on reconnect and conflicts are surfaced, never auto-merged |

Each of 2–4 belongs to the phase that introduces it and is not implemented ahead
of that phase.

## Migrations

Migrations are the only mechanism for schema change. The predecessor system
mutated its schema through ad-hoc scripts (`fix_schema.js`, `fix_schema.py`),
leaving four migrations describing a forty-five model schema — the migration
history no longer described the database. That must not recur.

Amending an unreleased migration is preferred over layering a corrective one
while the schema has not shipped.
