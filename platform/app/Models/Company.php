<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CompanyStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A tenant. Owns every business record in the platform.
 *
 * Note that Company itself is deliberately *not* scoped by
 * {@see BelongsToCompany} — it is the root of the ownership
 * graph, and access to it is governed by company membership instead.
 */
class Company extends Model implements AuditableContract
{
    use Auditable;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'name_en',
        'commercial_registration_no',
        'vat_registration_number',
        'group_vat_number',
        'building_number',
        'street_name',
        'district',
        'city',
        'postal_code',
        'additional_number',
        'country_code',
        'email',
        'phone',
        'logo_path',
        'base_currency',
        'timezone',
        'fiscal_year_start_month',
        'fiscal_year_start_day',
        'uses_hijri_fiscal_year',
        'status',
        'settings',
    ];

    /**
     * Mirrors the schema defaults.
     *
     * A freshly created model carries no value for columns the database
     * defaults, and the fiscal fields are read immediately after creation to
     * build the calendar. Left unset, date construction silently falls back to
     * today's month and day and the fiscal year starts on the wrong date.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'base_currency' => 'SAR',
        'country_code' => 'SA',
        'timezone' => 'Asia/Riyadh',
        'fiscal_year_start_month' => 1,
        'fiscal_year_start_day' => 1,
        'uses_hijri_fiscal_year' => false,
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
            'settings' => 'array',
            'uses_hijri_fiscal_year' => 'boolean',
            'fiscal_year_start_month' => 'integer',
            'fiscal_year_start_day' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_user')
            ->using(CompanyUser::class)
            ->withPivot(['id', 'status', 'invited_at', 'joined_at', 'invited_by_id'])
            ->withTimestamps();
    }

    /**
     * Memberships as records, for lifecycle work.
     *
     * `users()` answers "who belongs here"; this answers "what is the state of
     * each membership", which is what invitation and suspension operate on.
     *
     * @return HasMany<CompanyUser, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(CompanyUser::class);
    }

    /**
     * The name shown in the panel's company switcher and on documents.
     */
    public function displayName(): string
    {
        return app()->getLocale() === 'en'
            ? ($this->name_en ?? $this->name)
            : $this->name;
    }

    public function isActive(): bool
    {
        return $this->status->allowsTransactions();
    }

    /**
     * Whether this company is registered for VAT and must issue tax invoices.
     */
    public function isVatRegistered(): bool
    {
        return filled($this->vat_registration_number);
    }
}
