<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\CompanyMembershipStatus;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Company resolution for token-authenticated API callers.
 *
 * The predecessor system accepted an `x-tenant-id` header and applied it without
 * checking membership, so any authenticated caller could read any tenant's data.
 * These tests exist to make that regression impossible.
 */
final class CompanyResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Company $acme;

    private Company $globex;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Company::create(['name' => 'Acme Trading']);
        $this->globex = Company::create(['name' => 'Globex Industrial']);

        $this->member = User::create([
            'name' => 'Ahmed',
            'email' => 'ahmed@example.test',
            'password' => 'password',
        ]);

        // A member of Acme only. Globex is a company they must never reach.
        $this->member->companies()->attach($this->acme, [
            'status' => CompanyMembershipStatus::Active->value,
        ]);
    }

    #[Test]
    public function health_is_reachable_without_authentication(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJson(['status' => 'ok', 'version' => 'v1']);
    }

    #[Test]
    public function protected_routes_reject_unauthenticated_callers(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->getJson('/api/v1/company')->assertUnauthorized();
    }

    #[Test]
    public function it_returns_401_to_clients_that_do_not_request_json(): void
    {
        // Regression: Laravel's default guest redirect targets route('login'),
        // which this application does not define. Without the override in
        // bootstrap/app.php this returned 500. Integrations routinely omit the
        // Accept header, so `get()` is used here deliberately, not `getJson()`.
        $this->get('/api/v1/me')->assertUnauthorized();
        $this->get('/api/v1/company')->assertUnauthorized();
    }

    #[Test]
    public function it_resolves_the_sole_company_when_no_header_is_sent(): void
    {
        Sanctum::actingAs($this->member);

        $this->getJson('/api/v1/company')
            ->assertOk()
            ->assertJsonPath('data.id', $this->acme->getKey())
            ->assertJsonPath('data.name', 'Acme Trading');
    }

    #[Test]
    public function it_honours_a_company_header_the_caller_belongs_to(): void
    {
        Sanctum::actingAs($this->member);

        $this->getJson('/api/v1/company', ['X-Company-Id' => $this->acme->getKey()])
            ->assertOk()
            ->assertJsonPath('data.id', $this->acme->getKey());
    }

    #[Test]
    public function it_refuses_a_company_header_the_caller_does_not_belong_to(): void
    {
        Sanctum::actingAs($this->member);

        // The exact attack the predecessor system permitted.
        $this->getJson('/api/v1/company', ['X-Company-Id' => $this->globex->getKey()])
            ->assertNotFound();
    }

    #[Test]
    public function it_refuses_a_company_the_caller_was_only_invited_to(): void
    {
        $this->member->companies()->attach($this->globex, [
            'status' => CompanyMembershipStatus::Invited->value,
        ]);

        Sanctum::actingAs($this->member);

        // An unaccepted invitation grants no access.
        $this->getJson('/api/v1/company', ['X-Company-Id' => $this->globex->getKey()])
            ->assertNotFound();
    }

    #[Test]
    public function it_refuses_a_suspended_company(): void
    {
        $this->acme->update(['status' => CompanyStatus::Suspended]);

        Sanctum::actingAs($this->member);

        $this->getJson('/api/v1/company', ['X-Company-Id' => $this->acme->getKey()])
            ->assertForbidden();
    }

    #[Test]
    public function it_requires_an_explicit_company_when_the_caller_has_several(): void
    {
        $this->member->companies()->attach($this->globex, [
            'status' => CompanyMembershipStatus::Active->value,
        ]);

        Sanctum::actingAs($this->member);

        // Guessing would risk writing to the wrong company's ledger.
        $this->getJson('/api/v1/company')->assertStatus(400);
    }

    #[Test]
    public function me_lists_only_active_memberships(): void
    {
        $this->member->companies()->attach($this->globex, [
            'status' => CompanyMembershipStatus::Invited->value,
        ]);

        Sanctum::actingAs($this->member);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonCount(1, 'data.companies')
            ->assertJsonPath('data.companies.0.id', $this->acme->getKey());
    }
}
