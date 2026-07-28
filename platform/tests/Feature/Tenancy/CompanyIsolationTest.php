<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Company;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\Exceptions\CompanyContextMissing;
use App\Support\Tenancy\Exceptions\CompanyMismatch;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guarantees provided by {@see BelongsToCompany}.
 *
 * These are regression tests for the defect that motivated the rebuild: in the
 * predecessor system tenant filtering was applied per-service and could be
 * omitted, and a missing tenant context widened access instead of removing it.
 */
final class CompanyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Company $acme;

    private Company $globex;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('ledger_probes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->timestamps();
        });

        $context = app(CompanyContext::class);

        // Companies themselves are unscoped, but creating them establishes no
        // context, so the fixtures are built without one deliberately.
        $this->acme = Company::create(['name' => 'Acme Trading', 'vat_registration_number' => '300000000000003']);
        $this->globex = Company::create(['name' => 'Globex Industrial', 'vat_registration_number' => '300000000000011']);

        $context->forget();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ledger_probes');

        parent::tearDown();
    }

    #[Test]
    public function it_returns_no_rows_when_no_company_context_is_set(): void
    {
        $this->seedRowFor($this->acme, 'acme row');
        $this->seedRowFor($this->globex, 'globex row');

        app(CompanyContext::class)->forget();

        // Fails closed. The predecessor would have returned every tenant's rows.
        $this->assertSame(0, LedgerProbe::count());
    }

    #[Test]
    public function it_only_returns_rows_belonging_to_the_current_company(): void
    {
        $this->seedRowFor($this->acme, 'acme row');
        $this->seedRowFor($this->globex, 'globex row');

        app(CompanyContext::class)->set($this->acme);

        $rows = LedgerProbe::all();

        $this->assertCount(1, $rows);
        $this->assertSame('acme row', $rows->first()->label);
    }

    #[Test]
    public function it_cannot_reach_another_companys_row_by_primary_key(): void
    {
        $globexRow = $this->seedRowFor($this->globex, 'globex row');

        app(CompanyContext::class)->set($this->acme);

        // Direct key access is the exact shape of the old header-injection attack.
        $this->assertNull(LedgerProbe::find($globexRow->getKey()));
    }

    #[Test]
    public function it_assigns_the_current_company_on_create(): void
    {
        app(CompanyContext::class)->set($this->acme);

        $row = LedgerProbe::create(['label' => 'implicitly owned']);

        $this->assertSame($this->acme->getKey(), $row->company_id);
    }

    #[Test]
    public function it_refuses_to_create_without_a_company_context(): void
    {
        app(CompanyContext::class)->forget();

        $this->expectException(CompanyContextMissing::class);

        LedgerProbe::create(['label' => 'orphan']);
    }

    #[Test]
    public function it_refuses_to_move_a_row_between_companies(): void
    {
        app(CompanyContext::class)->set($this->acme);
        $row = LedgerProbe::create(['label' => 'acme row']);

        $row->company_id = $this->globex->getKey();

        $this->expectException(CompanyMismatch::class);

        $row->save();
    }

    #[Test]
    public function it_allows_explicit_cross_company_reads_only_through_without_scoping(): void
    {
        $this->seedRowFor($this->acme, 'acme row');
        $this->seedRowFor($this->globex, 'globex row');

        app(CompanyContext::class)->set($this->acme);

        $all = app(CompanyContext::class)->withoutScoping(
            static fn (): int => LedgerProbe::count(),
        );

        $this->assertSame(2, $all);

        // Scoping is restored afterwards.
        $this->assertSame(1, LedgerProbe::count());
    }

    #[Test]
    public function for_company_restores_the_previous_context(): void
    {
        $this->seedRowFor($this->globex, 'globex row');

        $context = app(CompanyContext::class);
        $context->set($this->acme);

        $seen = $context->forCompany($this->globex, static fn (): int => LedgerProbe::count());

        $this->assertSame(1, $seen);
        $this->assertSame($this->acme->getKey(), $context->id());
    }

    private function seedRowFor(Company $company, string $label): LedgerProbe
    {
        return app(CompanyContext::class)->forCompany(
            $company,
            static fn (): LedgerProbe => LedgerProbe::create(['label' => $label]),
        );
    }
}

/**
 * Stand-in for a tenant-owned model. Phase 1 replaces it with the real ones;
 * the guarantees under test belong to the trait, not to any particular table.
 */
final class LedgerProbe extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $table = 'ledger_probes';

    protected $fillable = ['label', 'company_id'];
}
