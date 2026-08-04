<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\AccountType;
use App\Enums\DimensionScope;
use App\Models\Account;
use App\Models\Company;
use App\Models\Dimension;
use App\Models\DimensionValue;
use App\Services\Accounting\Data\JournalLineData;
use App\Services\Accounting\Exceptions\DimensionRuleViolation;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\Reports\ReportFilters;
use App\Services\Accounting\Reports\TrialBalance;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * User-defined accounting dimensions.
 *
 * Follows Qoyod's design: a company defines its own dimensions, each holding
 * values, and tags ledger lines with them. Two rules are distinctive and are
 * covered here — at most two *general* dimensions, and a dimension already in
 * use cannot change scope.
 */
final class DimensionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private JournalPoster $poster;

    private Account $cash;

    private Account $expense;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Acme Trading']);
        app(CompanyContext::class)->set($this->company);

        app(FiscalCalendar::class)->createYear($this->company, 2026);
        $this->poster = app(JournalPoster::class);

        $this->cash = Account::create(['code' => '1110', 'name' => 'Cash', 'type' => AccountType::Asset]);
        $this->expense = Account::create(['code' => '5300', 'name' => 'Rent', 'type' => AccountType::Expense]);
    }

    #[Test]
    public function a_dimension_holds_values(): void
    {
        $project = $this->dimension('PROJ', 'Project');

        $this->value($project, 'RIYADH', 'Riyadh Tower');
        $this->value($project, 'JEDDAH', 'Jeddah Mall');

        $this->assertCount(2, $project->refresh()->values);
    }

    #[Test]
    public function at_most_two_general_dimensions_are_allowed(): void
    {
        // Qoyod's rule. Every document carries every general dimension, so an
        // unbounded number would mean unbounded mandatory tags per line.
        $this->dimension('CC', 'Cost Centre', DimensionScope::General);
        $this->dimension('PROJ', 'Project', DimensionScope::General);

        $this->expectException(DimensionRuleViolation::class);

        $this->dimension('DEPT', 'Department', DimensionScope::General);
    }

    #[Test]
    public function a_third_dimension_may_still_be_specific(): void
    {
        $this->dimension('CC', 'Cost Centre', DimensionScope::General);
        $this->dimension('PROJ', 'Project', DimensionScope::General);

        $specific = $this->dimension('DEPT', 'Department', DimensionScope::Specific);

        $this->assertSame(DimensionScope::Specific, $specific->scope);
        $this->assertSame(3, Dimension::query()->count());
    }

    #[Test]
    public function promoting_to_general_respects_the_limit(): void
    {
        $this->dimension('CC', 'Cost Centre', DimensionScope::General);
        $this->dimension('PROJ', 'Project', DimensionScope::General);
        $dept = $this->dimension('DEPT', 'Department', DimensionScope::Specific);

        $dept->scope = DimensionScope::General;

        $this->expectException(DimensionRuleViolation::class);

        $dept->save();
    }

    #[Test]
    public function the_limit_frees_up_when_a_general_dimension_is_demoted(): void
    {
        $cc = $this->dimension('CC', 'Cost Centre', DimensionScope::General);
        $this->dimension('PROJ', 'Project', DimensionScope::General);

        $cc->update(['scope' => DimensionScope::Specific]);

        $dept = $this->dimension('DEPT', 'Department', DimensionScope::General);

        $this->assertSame(DimensionScope::General, $dept->scope);
    }

    #[Test]
    public function a_ledger_line_can_be_tagged_with_a_dimension_value(): void
    {
        $project = $this->dimension('PROJ', 'Project');
        $riyadh = $this->value($project, 'RIYADH', 'Riyadh Tower');

        $entry = $this->postTagged([$project->getKey() => $riyadh->getKey()]);

        $line = $entry->lines->firstWhere('account_id', $this->expense->getKey());

        $this->assertCount(1, $line->dimensionAssignments);
        $this->assertSame($riyadh->getKey(), $line->dimensionAssignments->first()->dimension_value_id);
    }

    #[Test]
    public function a_line_can_carry_several_dimensions_at_once(): void
    {
        $project = $this->dimension('PROJ', 'Project');
        $dept = $this->dimension('DEPT', 'Department');
        $riyadh = $this->value($project, 'RIYADH', 'Riyadh Tower');
        $ops = $this->value($dept, 'OPS', 'Operations');

        $entry = $this->postTagged([
            $project->getKey() => $riyadh->getKey(),
            $dept->getKey() => $ops->getKey(),
        ]);

        $line = $entry->lines->firstWhere('account_id', $this->expense->getKey());

        $this->assertCount(2, $line->dimensionAssignments);
    }

    #[Test]
    public function a_value_from_the_wrong_dimension_is_refused(): void
    {
        $project = $this->dimension('PROJ', 'Project');
        $dept = $this->dimension('DEPT', 'Department');
        $ops = $this->value($dept, 'OPS', 'Operations');

        // Filing a department value under the project dimension would make
        // reports attribute the amount to something never chosen.
        $this->expectException(DimensionRuleViolation::class);

        $this->postTagged([$project->getKey() => $ops->getKey()]);
    }

    #[Test]
    public function an_inactive_value_cannot_be_used(): void
    {
        $project = $this->dimension('PROJ', 'Project');
        $old = $this->value($project, 'OLD', 'Closed Project');
        $old->update(['is_active' => false]);

        $this->expectException(DimensionRuleViolation::class);

        $this->postTagged([$project->getKey() => $old->getKey()]);
    }

    #[Test]
    public function a_required_dimension_must_be_supplied(): void
    {
        $project = $this->dimension('PROJ', 'Project');
        $project->update(['is_required' => true]);

        $this->expectException(DimensionRuleViolation::class);

        $this->postTagged([]);
    }

    #[Test]
    public function a_dimension_in_use_cannot_change_scope(): void
    {
        $project = $this->dimension('PROJ', 'Project', DimensionScope::Specific);
        $riyadh = $this->value($project, 'RIYADH', 'Riyadh Tower');

        $this->postTagged([$project->getKey() => $riyadh->getKey()]);

        $project->refresh()->scope = DimensionScope::General;

        // Promoting it would imply it had been recorded on documents that never
        // carried it, restating every report sliced by it.
        $this->expectException(DimensionRuleViolation::class);

        $project->save();
    }

    #[Test]
    public function a_dimension_in_use_cannot_be_deleted(): void
    {
        $project = $this->dimension('PROJ', 'Project');
        $riyadh = $this->value($project, 'RIYADH', 'Riyadh Tower');

        $this->postTagged([$project->getKey() => $riyadh->getKey()]);

        $this->expectException(DimensionRuleViolation::class);

        $project->refresh()->delete();
    }

    #[Test]
    public function a_reversal_carries_the_original_tags_across(): void
    {
        $project = $this->dimension('PROJ', 'Project');
        $riyadh = $this->value($project, 'RIYADH', 'Riyadh Tower');

        $entry = $this->postTagged([$project->getKey() => $riyadh->getKey()]);
        $reversal = $this->poster->reverse($entry);

        $line = $reversal->lines->firstWhere('account_id', $this->expense->getKey());

        // Without the tags, the reversal would not cancel the original in a
        // dimension-filtered report — the project would keep the cost forever.
        $this->assertCount(1, $line->dimensionAssignments);
        $this->assertSame($riyadh->getKey(), $line->dimensionAssignments->first()->dimension_value_id);
    }

    #[Test]
    public function the_trial_balance_can_be_filtered_to_one_dimension_value(): void
    {
        $project = $this->dimension('PROJ', 'Project');
        $riyadh = $this->value($project, 'RIYADH', 'Riyadh Tower');
        $jeddah = $this->value($project, 'JEDDAH', 'Jeddah Mall');

        $this->postTagged([$project->getKey() => $riyadh->getKey()], '700');
        $this->postTagged([$project->getKey() => $jeddah->getKey()], '300');

        $report = app(TrialBalance::class);

        $riyadhRows = $report->build(
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-12-31'),
            new ReportFilters(dimensionValueId: $riyadh->getKey()),
        );

        $rentRow = $riyadhRows->firstWhere('code', '5300');

        // A per-project income statement is the point of dimensions.
        $this->assertSame('700.0000', $rentRow->closingDebit);
    }

    /**
     * @param  array<string, string>  $tags
     */
    private function postTagged(array $tags, string $amount = '100')
    {
        return $this->poster->post(
            date: CarbonImmutable::parse('2026-03-15'),
            lines: [
                new JournalLineData($this->expense->getKey(), debit: $amount, dimensions: $tags),
                new JournalLineData($this->cash->getKey(), credit: $amount, dimensions: $tags),
            ],
        );
    }

    private function dimension(string $code, string $name, DimensionScope $scope = DimensionScope::Specific): Dimension
    {
        return Dimension::create([
            'code' => $code,
            'name' => $name,
            'scope' => $scope,
        ]);
    }

    private function value(Dimension $dimension, string $code, string $name): DimensionValue
    {
        return DimensionValue::create([
            'dimension_id' => $dimension->getKey(),
            'code' => $code,
            'name' => $name,
        ]);
    }
}
