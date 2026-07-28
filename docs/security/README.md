# Security

## Multi-tenancy

One database. Every tenant-owned table carries `company_id`. `Company` is the
tenancy root and is itself unscoped — access to it is governed by membership.

### The isolation model

Three enforcement points, none of which depend on a developer remembering to add
a filter:

1. **`CompanyScope` fails closed.** With no company context set, tenant-owned
   queries match *no rows* rather than falling through unfiltered. A missing
   context can never widen access.
2. **`BelongsToCompany` assigns and freezes ownership.** `company_id` is set from
   the context on create, and any attempt to change it afterwards throws.
   Ownership is immutable: moving a posted document between companies would
   corrupt two ledgers at once.
3. **Membership is verified before the context is set.** Filament calls
   `User::canAccessTenant()` on every panel request; the API's
   `ResolveApiCompany` middleware checks `company_user` before honouring
   `X-Company-Id`.

### What this fixes

The predecessor read `x-tenant-id` from the request and assigned it to the
authenticated user without checking membership. Filtering then depended on each
service adding a `where`, and membership was only checked inside a permissions
guard that ran solely for routes declaring required permissions — so eleven
controllers, including `tenants`, `inventory` and `consolidation`, were readable
across tenants by any authenticated caller.

The header still exists in the new API, but it is a *request*, not an
instruction: it selects among companies the caller already belongs to. An
identifier the caller has no membership for returns 404 — deliberately identical
to a non-existent company, so company identifiers cannot be probed.

### Escaping the scope

`CompanyContext::withoutScoping()` is the only way to query across companies. It
is an explicit, greppable call reserved for platform administration,
group consolidation and migrations. Ambient bypass flags are not provided.

### Queue isolation

Queue workers are long-lived and process jobs for many companies in sequence.
The context is cleared before and after every job, so a job that sets one can
never leak it into the next. Jobs establish their own context from their payload.

## Authorisation

Roles and permissions are **per company**, via spatie/laravel-permission's teams
feature keyed on `company_id`. The same person is commonly an accountant in one
company and read-only in another; a global role would be wrong.

A role with a null `company_id` is **global** in this configuration — it matches
in every company. `shield:generate` creates roles before any company exists and
therefore produces exactly such a role. `FirstRunSeeder` removes unassigned
global roles for this reason, and reports rather than silently revokes any that
are actually held.

Policies generated outside Laravel's discovery convention are bound explicitly
via `FilamentShield::enforcePolicies()`. An unregistered policy is not consulted,
which means the resource authorises everyone — it fails open, so registration is
verified rather than assumed.

## Authentication

- Panel: session authentication.
- API: Sanctum bearer tokens.
- Passwords hashed with bcrypt via Laravel's `hashed` cast.
- Tokens are never placed in `localStorage`. The predecessor's frontend stored
  both access and refresh tokens there, making them exfiltrable by any XSS.

Laravel's default guest redirect targets `route('login')`, which this
application does not define. It is overridden in `bootstrap/app.php` so that API
paths return 401 instead of a 500 from the missing route — clients that omit
`Accept: application/json` are common among integrations.

## Practices

- Never trust request input for identity, ownership, or authorisation.
- Validate foreign keys against the current company, not globally.
- Never log credentials, tokens, or personal data. The predecessor logged user
  and tenant identifiers on every permission check.
- Secrets come from the environment and are never committed. No seeder contains
  a default password; an unset credential skips seeding rather than creating a
  known account.
