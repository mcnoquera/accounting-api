<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyApiTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Bertrnk Tours Inc.',
            'tin' => '123456789',
            'rdo_code' => '044',
            'address' => '171 Apitong St., Taguig City',
            'zip_code' => '1630',
            'vat_registered' => true,
            'fiscal_year_start_month' => 1,
        ], $overrides);
    }

    public function test_company_is_null_before_setup(): void
    {
        $this->actingAsRole(Role::Accountant);

        $this->getJson('/api/v1/company')->assertOk()->assertJsonPath('data', null);
    }

    public function test_admin_sets_up_then_updates_company(): void
    {
        $this->actingAsRole(Role::Admin);

        $this->putJson('/api/v1/company', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.tin', '123-456-789-000')
            ->assertJsonPath('data.currency', 'PHP');

        $this->putJson('/api/v1/company', $this->payload(['name' => 'Bertrnk Travel Inc.', 'tin' => '123-456-789-00001']))
            ->assertOk()
            ->assertJsonPath('data.name', 'Bertrnk Travel Inc.')
            ->assertJsonPath('data.tin', '123-456-789-00001');

        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'updated', 'auditable_type' => 'Company']);
    }

    public function test_tin_and_fields_are_validated(): void
    {
        $this->actingAsRole(Role::Admin);

        $this->putJson('/api/v1/company', $this->payload([
            'tin' => '12345', 'rdo_code' => '44', 'zip_code' => 'abc', 'currency' => 'USD', 'fiscal_year_start_month' => 13,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tin', 'rdo_code', 'zip_code', 'currency', 'fiscal_year_start_month']);

        $this->putJson('/api/v1/company', $this->payload(['tin' => '123-ABC-789']))->assertJsonValidationErrors(['tin']);
    }

    public function test_only_admins_edit_company(): void
    {
        $this->actingAsRole(Role::Accountant);

        $this->putJson('/api/v1/company', $this->payload())->assertForbidden();
    }
}
