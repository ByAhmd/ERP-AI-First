# ERP Platform

Saudi-first ERP and accounting platform. Laravel 13 + Filament 5 + MySQL 8.4.

> The legacy NestJS/Prisma/Next.js application under `../apps` and `../packages` is
> retained as read-only reference during the rebuild and will be removed at parity.
> Do not add to it.

## Requirements

Docker Desktop only. The application runs entirely in Linux containers so that the
development environment matches production — notably `ext-pcntl`, which Laravel
Horizon requires and which Windows cannot provide in any PHP build.

A host PHP 8.4 exists at `C:\Users\ahmed\php84` but is **not** used to run the app.

## Start

```bash
docker compose up -d
```

| Service | URL |
|---|---|
| Application | http://localhost:8080 |
| Filament panel | http://localhost:8080/admin |
| Horizon (queues) | http://localhost:8080/horizon |

MySQL is published on host port **3307** and Redis on **6380** — 3306 and 6379 are
in use by an unrelated project on this machine.

## Running commands

All `artisan` and `composer` commands run inside the container. Running them from
Windows will fail, because `.env` resolves `mysql` and `redis` as container
hostnames.

```bash
docker compose exec app php artisan migrate
```

```bash
docker compose exec app composer require vendor/package
```

## Services

| Container | Role |
|---|---|
| `erp-app` | PHP 8.4 FPM |
| `erp-nginx` | Web server |
| `erp-horizon` | Queue supervisor |
| `erp-scheduler` | `schedule:work` |
| `erp-mysql` | MySQL 8.4, `utf8mb4_0900_ai_ci` |
| `erp-redis` | Cache, queue, session |

## Conventions

Locale defaults to Arabic with English fallback; the panel is RTL. Dates are stored
in UTC and presented in `Asia/Riyadh`. Hijri dates use PHP's `intl` extension with
the `islamic-umalqura` calendar — the official Saudi calendar — rather than a
third-party package.

MySQL runs at `READ-COMMITTED` isolation. This is deliberate: `REPEATABLE-READ`
takes gap locks on `SELECT ... FOR UPDATE`, which would serialise the gapless
document-numbering allocator under concurrency.
