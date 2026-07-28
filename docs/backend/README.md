# Backend

The application lives in `platform/`. Laravel 13 on PHP 8.4.

## Running it

Docker Desktop is the only prerequisite. The app runs in Linux containers even
locally — see [architecture](../architecture/README.md) for why.

```bash
docker compose up -d
```

| Service | URL |
|---|---|
| Application | http://localhost:8080 |
| Filament panel | http://localhost:8080/admin |
| Horizon | http://localhost:8080/horizon |

MySQL is published on host port **3307** and Redis on **6380**, because 3306 and
6379 are occupied by an unrelated project on the current development machine.

## Commands

Every `artisan` and `composer` command runs inside the container. Running them
from the host fails: `.env` resolves `mysql` and `redis` as container hostnames.

```bash
docker compose exec app php artisan migrate
```

```bash
docker compose exec app composer require vendor/package
```

## First run

```bash
docker compose exec app php artisan migrate:fresh --seed
```

`FirstRunSeeder` creates one company, one administrator, and a company-scoped
`super_admin` role. It reads `SEED_ADMIN_EMAIL` and `SEED_ADMIN_PASSWORD` from
the environment and **skips entirely if either is unset** — it will not create
an account with a default password. It is idempotent and safe to re-run; an
existing administrator's password is never reset.

## Permissions

After adding a Filament resource, regenerate permissions and policies:

```bash
docker compose exec app php artisan shield:generate --all --panel=admin --option=policies_and_permissions
```

Run this only when at least one company exists, or it writes global roles — see
[security](../security/README.md).

## Testing

```bash
docker compose exec app php artisan test
```

Tests run against a real MySQL database (`erp_testing`), not SQLite. An
accounting ledger tested on a different engine than it runs on is not tested.

Two conventions worth keeping:

- **Use `get()`, not only `getJson()`, for API auth tests.** `getJson()` sends
  `Accept: application/json`, which masks failures in the non-JSON path.
  Integrations frequently omit that header.
- **Assert properties, not fixtures, for calendar conversion.** ICU is the
  authority on Umm al-Qura and revises its tables; hard-coded date pairs test
  this codebase's assumptions rather than the conversion.

## Strict mode

Models run strict outside production: lazy loading, discarded attributes, and
access to unselected attributes all throw. This surfaces N+1 queries on ledger
listings during development rather than as production slowness.

A consequence worth knowing: `Model::create()` does not hydrate columns the
database defaults. Models that are read immediately after creation declare those
defaults in `$attributes` so they exist on the in-memory instance too.
