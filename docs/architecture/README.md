# Architecture

Laravel 13, Filament 5, MySQL 8.4, Redis. The Filament panel is the primary user
interface; a versioned REST API serves the POS client, mobile applications and
third-party integrations.

## Layering

```
Filament resource / API controller   transport only — read request, delegate, shape response
        ↓
Service                              business rules, transaction boundaries
        ↓
Model + Policy                       persistence, authorisation, invariants
        ↓
Observer / Event / Job               side effects, asynchronous work
```

Rules that follow from this:

- **No business logic in controllers or Filament resources.** They translate
  between transport and services. A resource that computes a total is a defect.
- **Transaction boundaries live in services**, never in controllers or observers.
  An observer that opens its own transaction breaks the caller's atomicity.
- **Anything that can fail independently and need retrying is a job**, not
  inline work: depreciation runs, payroll posting, consolidation, ZATCA
  submission.
- **Prefer composition over inheritance.** Shared model behaviour is a trait in
  `app/Models/Concerns`, not a base class. `User` extends `Authenticatable` and
  could never share a base model anyway.

## Where things live

| Path | Contains |
|---|---|
| `app/Models` | Eloquent models |
| `app/Models/Concerns` | Reusable model behaviour (`BelongsToCompany`, `AuditsCompany`) |
| `app/Models/Scopes` | Global scopes (`CompanyScope`) |
| `app/Support/Tenancy` | Company context and its exceptions |
| `app/Support/Calendar` | Hijri (Umm al-Qura) conversion |
| `app/Casts` | Attribute casts (`MoneyCast`) |
| `app/Enums` | Domain enumerations, Filament-aware |
| `app/Http/Middleware` | Company resolution, locale |
| `app/Http/Controllers/Api/V1` | API transport |
| `app/Filament` | Panel resources, pages, widgets |
| `routes/api/v1.php` | Versioned API surface |
| `docker/` | PHP image and nginx configuration |

## API versioning

Registered as an explicit route group in `bootstrap/app.php` rather than a single
`api.php`. Introducing v2 adds a group; it cannot alter v1 for the integrations
already depending on it.

## Localisation

Arabic is the default locale, English the fallback. Filament derives text
direction from the translation catalogue, so setting the locale is what switches
the entire panel between RTL and LTR — there is no separate direction setting to
keep in sync.

Timestamps are stored in UTC (`config('app.timezone')`) and presented in the
company's timezone. `config('erp.timezone')` is the presentation default. Mixing
the two in storage causes transactions to land in the wrong accounting period at
month boundaries.

Hijri dates use PHP's `intl` extension with the `islamic-umalqura` calendar —
the official Saudi civil calendar, maintained by ICU. No third-party Hijri
package is used; the common ones approximate the calendar arithmetically and
drift from the published Umm al-Qura tables.

## Development environment

The application runs in Linux containers, including locally. This is not
convenience: Laravel Horizon requires `ext-pcntl`, which Windows cannot provide
in any PHP build. Running the same OS locally and in production also removes
path-separator and filesystem-case differences, which on a codebase this size
surface as production-only defects.

See [backend](../backend/README.md) for commands.
