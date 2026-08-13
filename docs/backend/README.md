# Backend

Laravel 13 on PHP 8.4, at the repository root.

## Running it

[Laravel Herd](https://herd.laravel.com) serves the site and provides PHP;
[DBngin](https://dbngin.com) provides MySQL on its default port. There is no
Docker.

```bash
herd link erp-ai
```

| Service | URL |
|---|---|
| Application | http://erp-ai.test |
| Filament panel | http://erp-ai.test/admin |

Databases are `erp_ai` for development and `erp_ai_testing` for the suite, both
owned by a MySQL user of the same name.

## Commands

`artisan` and `composer` run on the host, against Herd's PHP.

```bash
php artisan migrate
```

If a different PHP is earlier on your `PATH` — an XAMPP install, for instance —
`vendor/composer/platform_check.php` will refuse to load, because the
dependencies were resolved for 8.4. Call Herd's binary directly rather than
working around it:

```bash
~/.config/herd/bin/php84/php.exe artisan migrate
```

## Horizon is not runnable locally

`laravel/horizon` requires `ext-pcntl` and `ext-posix`, and no Windows PHP build
provides either. Composer would therefore refuse to install at all, so
`composer.json` declares both as satisfied under `config.platform`. That
override exists solely to let the dependency resolve; it does not make the
extensions appear.

The practical consequence is that the `horizon` command cannot run on this
machine. Locally the queue is `database` and is worked with:

```bash
php artisan queue:listen
```

Horizon remains a dependency because production runs on Linux, where it is the
intended worker.

## First run

```bash
php artisan migrate:fresh --seed
```

`FirstRunSeeder` creates one company, one administrator, and a company-scoped
`super_admin` role. It reads `SEED_ADMIN_EMAIL` and `SEED_ADMIN_PASSWORD` from
the environment and **skips entirely if either is unset** — it will not create
an account with a default password. It is idempotent and safe to re-run; an
existing administrator's password is never reset.

## Permissions

After adding a Filament resource, regenerate permissions and policies:

```bash
php artisan shield:generate --all --panel=admin --option=policies_and_permissions
```

Run this only when at least one company exists, or it writes global roles — see
[security](../security/README.md).

## Testing

```bash
php artisan test
```

Tests run against a real MySQL database (`erp_ai_testing`), not SQLite. An
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
