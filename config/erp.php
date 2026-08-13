<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Base Currency
    |--------------------------------------------------------------------------
    |
    | Fallback currency for rows that carry no explicit currency column. Each
    | company overrides this via its own base_currency, which is authoritative
    | for that company's ledger.
    |
    */

    'base_currency' => env('ERP_BASE_CURRENCY', 'SAR'),

    /*
    |--------------------------------------------------------------------------
    | Monetary Scale
    |--------------------------------------------------------------------------
    |
    | Decimal places used for storage and intermediate arithmetic. Presentation
    | and posting round to the currency's natural scale; this value exists so
    | that unit prices and exchange conversions do not lose precision in transit.
    |
    */

    'money_scale' => 4,

    /*
    |--------------------------------------------------------------------------
    | Calendar
    |--------------------------------------------------------------------------
    |
    | Umm al-Qura is the official civil Hijri calendar of Saudi Arabia and is
    | provided by PHP's intl extension through ICU. No third-party package is
    | used, so the calendar stays correct as ICU is updated.
    |
    */

    'hijri_calendar' => 'islamic-umalqura',

    'timezone' => env('ERP_TIMEZONE', 'Asia/Riyadh'),

    /*
    |--------------------------------------------------------------------------
    | Invitations
    |--------------------------------------------------------------------------
    |
    | An invitation grants access to a company's complete financial history, so
    | it is time-limited. Tokens are stored hashed and are single-use.
    |
    */

    'invitations' => [
        'expires_after_days' => (int) env('ERP_INVITATION_EXPIRY_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | First-Run Seed
    |--------------------------------------------------------------------------
    |
    | Used once to bring a fresh installation to a usable state. Read through
    | config rather than env() at the call site so that seeding continues to
    | work when the configuration is cached.
    |
    | No default password is provided deliberately: an unset value skips the
    | seed rather than creating an account with a known credential.
    |
    */

    'seed' => [
        'admin_email' => env('SEED_ADMIN_EMAIL'),
        'admin_password' => env('SEED_ADMIN_PASSWORD'),
        'admin_name' => env('SEED_ADMIN_NAME', 'Platform Administrator'),
        'company_name' => env('SEED_COMPANY_NAME', 'الشركة الأولى'),
        'company_name_en' => env('SEED_COMPANY_NAME_EN', 'First Company'),
    ],

];
