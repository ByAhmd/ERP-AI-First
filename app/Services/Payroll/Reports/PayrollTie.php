<?php

declare(strict_types=1);

namespace App\Services\Payroll\Reports;

use App\Enums\DocumentStatus;
use App\Enums\JournalEntryStatus;
use App\Enums\SystemAccount;
use App\Models\Account;
use App\Services\Accounting\AccountRegistry;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * The payroll tie — the payable and the advances against their subledgers.
 *
 * 2140: GL credit balance must equal payslip nets plus approved bonuses
 * minus voucher allocations. 1180: GL debit balance must equal approved
 * advances minus settlements minus recoveries. 2150 is reported with its
 * difference labelled as settled-outside-payroll — GOSI settlement is a
 * manual entry in this slice, so the row detects rather than accuses.
 */
final class PayrollTie
{
    private const SCALE = 4;

    public function __construct(
        private readonly CompanyContext $context,
        private readonly AccountRegistry $registry,
    ) {}

    /**
     * @return array{rows: list<array<string, mixed>>, balanced: bool}
     */
    public function build(): array
    {
        $companyId = $this->context->idOrFail();

        $rows = [];
        $balanced = true;

        // --- 2140: net pay owed --------------------------------------------
        $payable = $this->registry->get(SystemAccount::SalariesPayable);

        $nets = (string) (DB::table('payslips')
            ->where('company_id', $companyId)
            ->sum('net') ?: '0');

        $bonuses = (string) (DB::table('employee_bonuses')
            ->where('company_id', $companyId)
            ->where('status', DocumentStatus::Approved->value)
            ->sum('amount') ?: '0');

        $allocations = (string) (DB::table('employee_payment_allocations as a')
            ->join('employee_payment_vouchers as v', 'v.id', '=', 'a.employee_payment_voucher_id')
            ->where('a.company_id', $companyId)
            ->where('v.company_id', $companyId)
            ->where('v.status', DocumentStatus::Approved->value)
            ->sum('a.amount') ?: '0');

        $expected = bcsub(
            bcadd($this->scale($nets), $this->scale($bonuses), self::SCALE),
            $this->scale($allocations),
            self::SCALE,
        );

        $rows[] = $this->row('salaries_payable', $payable,
            $this->glBalance($payable, creditNormal: true), $expected, $balanced);

        // --- 1180: advances outstanding ------------------------------------
        $advances = $this->registry->get(SystemAccount::EmployeeAdvances);

        $issued = (string) (DB::table('employee_advances')
            ->where('company_id', $companyId)
            ->where('status', DocumentStatus::Approved->value)
            ->sum('amount') ?: '0');

        $settled = (string) (DB::table('employee_advance_settlements')
            ->where('company_id', $companyId)
            ->sum('amount') ?: '0');

        $recovered = (string) (DB::table('payslip_advance_recoveries')
            ->where('company_id', $companyId)
            ->sum('amount') ?: '0');

        $expected = bcsub(
            bcsub($this->scale($issued), $this->scale($settled), self::SCALE),
            $this->scale($recovered),
            self::SCALE,
        );

        $rows[] = $this->row('employee_advances', $advances,
            $this->glBalance($advances, creditNormal: false), $expected, $balanced);

        // --- 2150: GOSI, detection only ------------------------------------
        $gosi = $this->registry->get(SystemAccount::GosiPayable);

        $accrued = bcadd(
            $this->scale((string) (DB::table('payslips')->where('company_id', $companyId)->sum('gosi_employee') ?: '0')),
            $this->scale((string) (DB::table('payslips')->where('company_id', $companyId)->sum('gosi_employer') ?: '0')),
            self::SCALE,
        );

        $glGosi = $this->glBalance($gosi, creditNormal: true);

        $rows[] = [
            'role' => 'gosi_payable',
            'account' => $gosi,
            'gl_balance' => $glGosi,
            'register_total' => $accrued,
            'difference' => bcsub($glGosi, $accrued, self::SCALE),
            'informational' => true,
        ];

        return ['rows' => $rows, 'balanced' => $balanced];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $role, Account $account, string $gl, string $expected, bool &$balanced): array
    {
        $difference = bcsub($gl, $expected, self::SCALE);

        if (bccomp($difference, '0', self::SCALE) !== 0) {
            $balanced = false;
        }

        return [
            'role' => $role,
            'account' => $account,
            'gl_balance' => $gl,
            'register_total' => $expected,
            'difference' => $difference,
            'informational' => false,
        ];
    }

    private function glBalance(Account $account, bool $creditNormal): string
    {
        $row = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $this->context->idOrFail())
            ->where('e.company_id', $this->context->idOrFail())
            ->where('e.status', JournalEntryStatus::Posted->value)
            ->where('l.account_id', $account->getKey())
            ->selectRaw('COALESCE(SUM(l.debit), 0) as d, COALESCE(SUM(l.credit), 0) as c')
            ->first();

        $debit = (string) ($row->d ?? '0');
        $credit = (string) ($row->c ?? '0');

        return $creditNormal
            ? bcsub($credit, $debit, self::SCALE)
            : bcsub($debit, $credit, self::SCALE);
    }

    private function scale(string $amount): string
    {
        return bcadd($amount === '' ? '0' : $amount, '0', self::SCALE);
    }
}
