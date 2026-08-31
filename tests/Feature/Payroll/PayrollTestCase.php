<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Enums\JournalEntryStatus;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalaryComponent;
use App\Models\PayrollRun;
use App\Models\SalaryComponent;
use App\Services\Payroll\PayrollRunEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Shared scaffolding for the payroll slice's tests: a posting-ready
 * company and the vocabulary the invariants speak — employees,
 * components, runs and balances.
 */
abstract class PayrollTestCase extends TestCase
{
    use CreatesDomainFixtures;
    use RefreshDatabase;

    protected Company $company;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeCompany('شركة الرواتب النموذجية');

        $this->makeChartOfAccounts($this->company);
        $this->makeFiscalYear($this->company, 2026);

        $this->branch = Branch::query()->where('is_default', true)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeEmployee(array $overrides = []): Employee
    {
        static $sequence = 0;
        $sequence++;

        return Employee::create([
            'reference' => 'EMP-'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
            'first_name' => 'موظف',
            'last_name' => (string) $sequence,
            'nationality_status' => $overrides['nationality_status'] ?? 'saudi',
            'branch_id' => $overrides['branch_id'] ?? $this->branch->getKey(),
            'joined_on' => '2026-01-01',
            'cost_type' => $overrides['cost_type'] ?? 'indirect',
            'first_salary_date' => $overrides['first_salary_date'] ?? '2026-01-01',
            'last_salary_date' => $overrides['last_salary_date'] ?? null,
            'base_salary' => $overrides['base_salary'] ?? '10000',
            'gosi_enrolled' => $overrides['gosi_enrolled'] ?? false,
            'gosi_wage' => $overrides['gosi_wage'] ?? null,
            'status' => $overrides['status'] ?? 'active',
        ]);
    }

    protected function makeComponent(
        string $name,
        string $kind = 'allowance',
        string $calculation = 'fixed',
        bool $gosi = false,
        ?string $accountCode = null,
    ): SalaryComponent {
        $account = $accountCode !== null
            ? $this->accountByCode($accountCode)
            : ($kind === 'allowance' ? $this->accountByCode('5200') : $this->accountByCode('4320'));

        return SalaryComponent::create([
            'name' => $name,
            'kind' => $kind,
            'calculation' => $calculation,
            'account_id' => $account->getKey(),
            'counts_toward_gosi' => $gosi,
        ]);
    }

    protected function assign(Employee $employee, SalaryComponent $component, string $amount): void
    {
        EmployeeSalaryComponent::create([
            'employee_id' => $employee->getKey(),
            'salary_component_id' => $component->getKey(),
            'amount' => $amount,
        ]);
    }

    protected function makeRun(string $periodDate): PayrollRun
    {
        return PayrollRun::create([
            'reference' => app(PayrollRunEngine::class)->nextReference(),
            'accounting_period_id' => $this->periodContaining($periodDate)->getKey(),
        ]);
    }

    protected function approveRun(string $periodDate): PayrollRun
    {
        return app(PayrollRunEngine::class)->approve($this->makeRun($periodDate));
    }

    protected function periodContaining(string $date): AccountingPeriod
    {
        return AccountingPeriod::query()
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->firstOrFail();
    }

    protected function accountByCode(string $code): Account
    {
        return Account::query()->where('code', $code)->firstOrFail();
    }

    protected function accountByKey(string $systemKey): Account
    {
        return Account::query()->where('system_key', $systemKey)->firstOrFail();
    }

    /**
     * Posted debit-minus-credit balance of an account, at scale 4.
     */
    protected function balanceOf(Account $account): string
    {
        $row = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $this->company->getKey())
            ->where('e.status', JournalEntryStatus::Posted->value)
            ->where('l.account_id', $account->getKey())
            ->selectRaw('COALESCE(SUM(l.debit), 0) as d, COALESCE(SUM(l.credit), 0) as c')
            ->first();

        return bcsub((string) $row->d, (string) $row->c, 4);
    }
}
