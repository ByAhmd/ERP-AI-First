<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AuditsCompany;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * One value a dimension can take: a specific project, department or cost centre.
 *
 * Values nest, so a sub-project rolls up into its programme and a report can be
 * run at either level.
 */
#[Fillable([
    'company_id', 'dimension_id', 'parent_id', 'code', 'name', 'name_en',
    'is_active',
])]
class DimensionValue extends Model implements AuditableContract
{
    use AuditsCompany;
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Dimension, $this>
     */
    public function dimension(): BelongsTo
    {
        return $this->belongsTo(Dimension::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function displayName(): string
    {
        $name = app()->getLocale() === 'en' && filled($this->name_en)
            ? $this->name_en
            : $this->name;

        return "{$this->code} — {$name}";
    }
}
