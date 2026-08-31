<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AuditsCompany;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * The company's GOSI rates and wage ceiling — regulation, not arithmetic,
 * so stored per company rather than hard-coded. One row, lazily created
 * with the seeded Saudi defaults.
 *
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'saudi_employee_rate', 'saudi_employer_rate',
    'non_saudi_employer_rate', 'gosi_wage_ceiling',
])]
class PayrollSetting extends Model implements AuditableContract
{
    use AuditsCompany;
    use BelongsToCompany;
    use HasUlids;

    /**
     * The current company's settings, created with defaults on first read.
     *
     * The defaults are stated here as well as in the schema: firstOrCreate
     * never reads database defaults back, and a rates row that comes back
     * null would compute a zero ceiling — clamping every GOSI wage to
     * nothing without a sound.
     */
    public static function current(): self
    {
        return self::query()->firstOrCreate([], [
            'saudi_employee_rate' => '9.75',
            'saudi_employer_rate' => '11.75',
            'non_saudi_employer_rate' => '2',
            'gosi_wage_ceiling' => '45000',
        ]);
    }
}
