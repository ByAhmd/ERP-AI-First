<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The payroll module's state, and its proof.
 *
 * The shape mirrors the fixed-asset slice deliberately:
 *
 * - `employees` and `salary_components` are the registers. A component
 *   carries its own account — the type-carries-the-accounts pattern — and
 *   the keyed system accounts are only form defaults.
 *
 * - `payslips` is THE subledger, the depreciation_charges analog: one row
 *   per employee per period of record, stamped with the figures actually
 *   posted, inserted BEFORE the run's entry posts so the unique
 *   (employee, period) index kills a concurrent duplicate before any money
 *   moves. Runs are unique per employee-period, not per period — Qoyod's
 *   own rule, which permits a supplementary run for missed employees.
 *
 * - `employee_advances` stores NO balance column. Remaining = amount −
 *   settlements − recoveries, derived always; `payslip_advance_recoveries`
 *   records exactly which advance each recovery consumed, oldest first.
 *
 * - `employee_deductions` are consumed by reference: `payslip_id` set when
 *   a run takes the deduction, cleared when that run reverses — never
 *   double-counted, never silently skipped.
 *
 * - `payroll_settings` holds the GOSI rates per company — regulation, not
 *   arithmetic, so stored rather than hard-coded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 40);
            $table->string('first_name');
            $table->string('last_name');
            $table->string('first_name_en')->nullable();
            $table->string('last_name_en')->nullable();
            $table->string('gender', 20)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();

            // WPS-ready even though the export ships later.
            $table->string('national_id', 40)->nullable();
            $table->string('iban', 40)->nullable();
            $table->string('nationality_status', 20);

            // Every payroll line for this employee carries it.
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();

            $table->string('department')->nullable();
            $table->string('job_title')->nullable();
            $table->string('education_level')->nullable();
            $table->date('joined_on');

            $table->string('cost_type', 20);

            // The run-eligibility window.
            $table->date('first_salary_date');
            $table->date('last_salary_date')->nullable();

            $table->string('salary_cycle', 20)->default('monthly');
            $table->decimal('base_salary', 19, 4);

            $table->boolean('gosi_enrolled')->default(false);
            // Null means computed: base + housing-flagged allowances,
            // capped at the ceiling.
            $table->decimal('gosi_wage', 19, 4)->nullable();

            $table->string('status', 20);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'reference'], 'employees_company_reference_unique');
            $table->index(['company_id', 'status'], 'employees_company_status_idx');
            $table->index(['company_id', 'branch_id'], 'employees_company_branch_idx');
        });

        Schema::create('salary_components', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('kind', 20);
            $table->string('calculation', 20);

            $table->foreignUlid('account_id')
                ->constrained('chart_of_accounts')->restrictOnDelete();

            // The housing flag: Qoyod's GOSI wage base is basic + housing.
            $table->boolean('counts_toward_gosi')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'name'], 'salary_components_company_name_unique');
        });

        Schema::create('employee_salary_components', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('salary_component_id')->constrained()->restrictOnDelete();

            // A fixed amount or a percent, per the component's calculation.
            $table->decimal('amount', 19, 4);

            $table->timestamps();

            $table->unique(['employee_id', 'salary_component_id'], 'employee_salary_components_unique');
        });

        Schema::create('employee_bonuses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 40);
            $table->foreignUlid('employee_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->decimal('amount', 19, 4);
            $table->date('bonus_date');
            $table->string('notes')->nullable();

            $table->string('status', 20)->default(DocumentStatus::Draft->value);
            $table->foreignUlid('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();
            $table->foreignUlid('reversal_journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();

            $table->timestamp('approved_at')->nullable();
            $table->foreignUlid('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'reference'], 'employee_bonuses_company_reference_unique');
            $table->index(['company_id', 'employee_id'], 'employee_bonuses_employee_idx');
        });

        Schema::create('employee_advances', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 40);
            $table->foreignUlid('employee_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->decimal('amount', 19, 4);
            $table->date('advance_date');
            $table->foreignUlid('payment_account_id')->nullable()
                ->constrained('chart_of_accounts')->restrictOnDelete();
            $table->string('notes')->nullable();

            $table->string('status', 20)->default(DocumentStatus::Draft->value);
            $table->foreignUlid('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();
            $table->foreignUlid('reversal_journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();

            $table->timestamp('approved_at')->nullable();
            $table->foreignUlid('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'reference'], 'employee_advances_company_reference_unique');
            $table->index(['company_id', 'employee_id'], 'employee_advances_employee_idx');
        });

        Schema::create('employee_advance_settlements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('employee_advance_id')->constrained()->restrictOnDelete();

            $table->decimal('amount', 19, 4);
            $table->date('settled_on');
            $table->foreignUlid('payment_account_id')
                ->constrained('chart_of_accounts')->restrictOnDelete();
            // Stamped a moment after the row, inside the same transaction:
            // the settlement is its own entry's source.
            $table->foreignUlid('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });

        Schema::create('employee_deductions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 40);
            $table->foreignUlid('employee_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->decimal('amount', 19, 4);
            $table->date('deduction_date');
            $table->string('description')->nullable();

            $table->string('status', 20)->default(DocumentStatus::Draft->value);

            // Set when a run consumes the deduction, cleared when that run
            // reverses. The consumption gate.
            $table->foreignUlid('payslip_id')->nullable();

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'reference'], 'employee_deductions_company_reference_unique');
            $table->index(['company_id', 'employee_id'], 'employee_deductions_employee_idx');
        });

        Schema::create('payroll_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 40);

            // The period of record, chosen from open periods — payroll
            // month and accounting period are one axis by construction.
            $table->foreignUlid('accounting_period_id')
                ->constrained('accounting_periods')->restrictOnDelete();
            // Stamped = period end at approval: the accrual is dated the
            // last day of the month, Qoyod's rule.
            $table->date('run_date')->nullable();

            $table->string('status', 20)->default(DocumentStatus::Draft->value);
            $table->foreignUlid('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();
            $table->foreignUlid('reversal_journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();

            $table->decimal('gross_total', 19, 4)->default(0);
            $table->decimal('allowances_total', 19, 4)->default(0);
            $table->decimal('deductions_total', 19, 4)->default(0);
            $table->decimal('advance_recovery_total', 19, 4)->default(0);
            $table->decimal('gosi_employee_total', 19, 4)->default(0);
            $table->decimal('gosi_employer_total', 19, 4)->default(0);
            $table->decimal('net_total', 19, 4)->default(0);
            $table->unsignedInteger('employees_count')->default(0);

            $table->string('notes')->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->foreignUlid('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'reference'], 'payroll_runs_company_reference_unique');
            $table->index(['company_id', 'status'], 'payroll_runs_company_status_idx');
        });

        Schema::create('payroll_run_exclusions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('employee_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id'], 'payroll_run_exclusions_unique');
        });

        Schema::create('payslips', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('payroll_run_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('employee_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('accounting_period_id')
                ->constrained('accounting_periods')->restrictOnDelete();

            // Snapshot from the employee at approval.
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->string('cost_type', 20);

            $table->decimal('base_salary', 19, 4);
            $table->decimal('allowances_total', 19, 4)->default(0);
            // Display only: bonuses accrue 2140 at their own approval.
            $table->decimal('bonuses_display_total', 19, 4)->default(0);
            $table->decimal('deductions_total', 19, 4)->default(0);
            $table->decimal('advance_recovery', 19, 4)->default(0);
            $table->decimal('gosi_wage', 19, 4)->default(0);
            $table->decimal('gosi_employee', 19, 4)->default(0);
            $table->decimal('gosi_employer', 19, 4)->default(0);
            $table->decimal('gross', 19, 4);
            $table->decimal('net', 19, 4);

            $table->foreignUlid('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();

            $table->timestamps();

            // THE run-twice anchor: one slip per employee per period of
            // record, enforced by the database before any money moves.
            $table->unique(['employee_id', 'accounting_period_id'], 'payslips_employee_period_unique');
            $table->index(['company_id', 'employee_id', 'id'], 'payslips_employee_idx');
        });

        Schema::create('payslip_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('payslip_id')->constrained()->cascadeOnDelete();

            $table->string('kind', 30);
            $table->foreignUlid('salary_component_id')->nullable()
                ->constrained()->restrictOnDelete();
            $table->string('source_type')->nullable();
            $table->ulid('source_id')->nullable();
            $table->string('label');
            $table->decimal('amount', 19, 4);

            $table->timestamps();

            $table->index(['company_id', 'payslip_id'], 'payslip_lines_payslip_idx');
        });

        Schema::create('payslip_advance_recoveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('payslip_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('employee_advance_id')->constrained()->restrictOnDelete();

            $table->decimal('amount', 19, 4);

            $table->timestamps();
        });

        Schema::create('employee_payment_vouchers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 40);
            $table->foreignUlid('employee_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 19, 4);
            $table->date('payment_date');
            $table->foreignUlid('payment_account_id')->nullable()
                ->constrained('chart_of_accounts')->restrictOnDelete();
            $table->string('notes')->nullable();

            $table->string('status', 20)->default(DocumentStatus::Draft->value);
            $table->foreignUlid('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();
            $table->foreignUlid('reversal_journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();

            $table->timestamp('approved_at')->nullable();
            $table->foreignUlid('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'reference'], 'employee_payment_vouchers_reference_unique');
            $table->index(['company_id', 'employee_id'], 'employee_payment_vouchers_employee_idx');
        });

        Schema::create('employee_payment_allocations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('employee_payment_voucher_id')->constrained()->cascadeOnDelete();

            // Exactly one of the two targets — app-guarded.
            $table->foreignUlid('payslip_id')->nullable()
                ->constrained()->restrictOnDelete();
            $table->foreignUlid('employee_bonus_id')->nullable()
                ->constrained()->restrictOnDelete();

            $table->decimal('amount', 19, 4);

            $table->timestamps();
        });

        Schema::create('payroll_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            // Regulation, not arithmetic — stored so a rate change never
            // needs a deployment.
            $table->decimal('saudi_employee_rate', 8, 4)->default(9.75);
            $table->decimal('saudi_employer_rate', 8, 4)->default(11.75);
            $table->decimal('non_saudi_employer_rate', 8, 4)->default(2);
            $table->decimal('gosi_wage_ceiling', 19, 4)->default(45000);

            $table->timestamps();

            $table->unique('company_id', 'payroll_settings_company_unique');
        });

        // The consumption gate points at payslips, created above it.
        Schema::table('employee_deductions', function (Blueprint $table): void {
            $table->foreign('payslip_id', 'employee_deductions_payslip_fk')
                ->references('id')->on('payslips')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employee_deductions', function (Blueprint $table): void {
            $table->dropForeign('employee_deductions_payslip_fk');
        });

        Schema::dropIfExists('payroll_settings');
        Schema::dropIfExists('employee_payment_allocations');
        Schema::dropIfExists('employee_payment_vouchers');
        Schema::dropIfExists('payslip_advance_recoveries');
        Schema::dropIfExists('payslip_lines');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_run_exclusions');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('employee_deductions');
        Schema::dropIfExists('employee_advance_settlements');
        Schema::dropIfExists('employee_advances');
        Schema::dropIfExists('employee_bonuses');
        Schema::dropIfExists('employee_salary_components');
        Schema::dropIfExists('salary_components');
        Schema::dropIfExists('employees');
    }
};
