# ERP Platform

Saudi-first ERP and accounting platform. Laravel 13 + Filament 5 + MySQL 8+ on
PHP 8.4.

## Requirements

[Laravel Herd](https://herd.laravel.com) for PHP and the web server,
[DBngin](https://dbngin.com) for MySQL, and Node 20.12 or later for building
assets. No Docker.

## Start

```bash
herd link erp-ai
```

```bash
composer install && npm install && npm run build
```

```bash
php artisan migrate --seed
```

| Service | URL |
|---|---|
| Application | http://erp-ai.test |
| Filament panel | http://erp-ai.test/admin |

Databases are `erp_ai` for development and `erp_ai_testing` for the suite, both
owned by a MySQL user of the same name.

## Running commands

`artisan` and `composer` run on the host against Herd's PHP. If another PHP is
earlier on your `PATH`, call Herd's directly:

```bash
~/.config/herd/bin/php84/php.exe artisan migrate
```

Composer scripts cover the common paths: `composer check` runs the linter, the
static analyser and the suite in that order.

## Queues

The queue is `database` locally, worked by `php artisan queue:listen`.

Horizon is a dependency because production runs on Linux, but it cannot run on a
Windows development machine: it requires `ext-pcntl` and `ext-posix`, which no
Windows PHP build provides. `composer.json` declares both satisfied under
`config.platform` so the dependency resolves at all. See
[ADR-011](docs/decisions/README.md).

## Conventions

Locale defaults to Arabic with English fallback; the panel is RTL. Dates are stored
in UTC and presented in `Asia/Riyadh`. Hijri dates use PHP's `intl` extension with
the `islamic-umalqura` calendar — the official Saudi calendar — rather than a
third-party package.

MySQL should run at `READ-COMMITTED` isolation. This is deliberate:
`REPEATABLE-READ` takes gap locks on `SELECT ... FOR UPDATE`, which would
serialise the gapless document-numbering allocator under concurrency.
