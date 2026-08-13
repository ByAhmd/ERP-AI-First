<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContactStatus;
use App\Enums\ContactType;
use App\Models\Concerns\AuditsCompany;
use App\Models\Concerns\BelongsToCompany;
use App\Observers\ContactObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A customer or a supplier.
 *
 * Audited, because the details printed on a tax invoice — name, VAT number,
 * national address — are what ZATCA checks the invoice against. Changing them
 * afterwards must leave a trail showing what they were when the invoice was
 * raised.
 *
 * @property ContactType $type
 * @property ContactStatus $status
 * @property bool $is_pos
 * @property bool $is_government_entity
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'type', 'code', 'contact_name', 'organization_name',
    'primary_contact_number', 'secondary_contact_number',
    'primary_email', 'secondary_email', 'website', 'tax_number',
    'status', 'currency_id', 'is_pos', 'is_government_entity',
    'billing_address', 'billing_city', 'billing_state', 'billing_zip',
    'billing_building_number', 'billing_country',
    'shipping_address', 'shipping_city', 'shipping_state', 'shipping_zip',
    'shipping_country',
    'bank_name', 'bank_account_name', 'bank_country', 'bank_currency',
    'bank_iban', 'bank_account_number', 'bank_swift_code', 'bank_address',
])]
class Contact extends Model implements AuditableContract
{
    use AuditsCompany;
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'customer',
        'status' => 'active',
        'is_pos' => false,
        'is_government_entity' => false,
    ];

    protected function casts(): array
    {
        return [
            'type' => ContactType::class,
            'status' => ContactStatus::class,
            'is_pos' => 'boolean',
            'is_government_entity' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCustomers(Builder $query): Builder
    {
        return $query->where('type', ContactType::Customer);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSuppliers(Builder $query): Builder
    {
        return $query->where('type', ContactType::Supplier);
    }

    /**
     * Contacts a new document may name.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->where('status', ContactStatus::Active);
    }

    /**
     * How the contact reads in a list or on a document.
     *
     * The organisation is what appears on the invoice when there is one; the
     * contact name is the person. Showing both, in that order, matches how a
     * clerk searches — by company first, then by who they spoke to.
     */
    public function displayName(): string
    {
        return filled($this->organization_name)
            ? $this->organization_name.' — '.$this->contact_name
            : $this->contact_name;
    }

    public function isTaxRegistered(): bool
    {
        return filled($this->tax_number);
    }

    /**
     * Whether the billing address carries everything a Saudi tax invoice needs.
     *
     * ZATCA expects the national address on a standard tax invoice, and the
     * building number is the part most often left blank — it is not asked for
     * anywhere else in the form. Reported rather than enforced: a company must
     * be able to record a customer before it knows their full address.
     *
     * @see ContactObserver
     */
    public function hasCompleteNationalAddress(): bool
    {
        foreach (['billing_building_number', 'billing_address', 'billing_city', 'billing_zip'] as $part) {
            if (blank($this->{$part})) {
                return false;
            }
        }

        return true;
    }
}
