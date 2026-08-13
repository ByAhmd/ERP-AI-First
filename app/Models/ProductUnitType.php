<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a quantity is counted in.
 *
 * A table rather than an enum because Qoyod's is one, and because the list is
 * genuinely the company's: a caterer measures in trays and a fabricator in
 * metres, and neither should have to wait for a release to say so.
 *
 * @property bool $is_active
 * @property ?string $company_id
 */
#[Fillable(['company_id', 'name', 'name_en', 'is_active'])]
class ProductUnitType extends Model
{
    use BelongsToCompany;
    use HasUlids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'unit_type_id');
    }

    public function displayName(): string
    {
        return app()->getLocale() === 'en' && filled($this->name_en)
            ? $this->name_en
            : $this->name;
    }
}
