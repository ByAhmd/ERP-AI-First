<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DimensionScope;
use App\Models\Concerns\AuditsCompany;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A user-defined analytical dimension.
 *
 * Cost centre, project, department, campaign — whatever the company needs to
 * slice its figures by. Values live underneath, and ledger lines are tagged with
 * one value per dimension.
 *
 * @property DimensionScope $scope
 * @property bool $is_required
 * @property bool $is_active
 * @property int $sort_order
 * @property ?string $company_id
 */
class Dimension extends Model implements AuditableContract
{
    use AuditsCompany;
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'name_en',
        'scope',
        'is_required',
        'is_active',
        'sort_order',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'scope' => 'specific',
        'is_required' => false,
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'scope' => DimensionScope::class,
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<DimensionValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(DimensionValue::class)->orderBy('code');
    }

    /**
     * @return HasMany<JournalEntryLineDimension, $this>
     */
    public function lineAssignments(): HasMany
    {
        return $this->hasMany(JournalEntryLineDimension::class);
    }

    public function isGeneral(): bool
    {
        return $this->scope->isGeneral();
    }

    /**
     * Whether this dimension applies to a line being entered.
     *
     * General dimensions apply everywhere; specific ones are opted into by the
     * document. Inactive dimensions apply nowhere.
     */
    public function appliesUniversally(): bool
    {
        return $this->is_active && $this->isGeneral();
    }

    /**
     * Whether the dimension is in use and so cannot be restructured.
     */
    public function hasLedgerUsage(): bool
    {
        return $this->lineAssignments()->exists();
    }

    public function displayName(): string
    {
        return app()->getLocale() === 'en' && filled($this->name_en)
            ? $this->name_en
            : $this->name;
    }
}
