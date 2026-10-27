<?php

namespace Tests\Feature\CRM\Portfolio;

use App\Models\Company\Company;
use Tests\Concerns\GeneratesValidCnpj;
use Tests\Support\DisposableApiTestCase;

/**
 * Confere a gravação, os valores padrão, a atualização parcial e a exposição
 * das classificações de cliente, fornecedor e transportadora.
 */
class CompanyTypeFlagsTest extends DisposableApiTestCase
{
    use GeneratesValidCnpj;

    private int $organizationId;

    private int $adminId;

    private const SEG = '__portfolio_flags__';

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizationId = $this->makeOrganization('Organização de teste');
        $this->adminId = $this->makeUser(superAdmin: true, nome: 'Administrador de teste');
    }

    private function authHeaders(): array
    {
        return $this->headers($this->adminId, $this->organizationId);
    }

    /** @param array<string,mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Empresa teste '.uniqid(),
            'cnpj' => $this->formatCnpjDigits($this->validCnpjDigits()),
            'industry' => 'servicos',
            'address_city' => 'Cidade Teste',
        ], $overrides);
    }

    /** @param array<string,mixed> $overrides */
    private function seedCompany(array $overrides = []): int
    {
        return (int) Company::query()->withoutGlobalScopes()->insertGetId(Company::fillCreateDefaults(array_merge([
            'name' => 'Empresa base '.uniqid(),
            'cnpj' => $this->formatCnpjDigits($this->validCnpjDigits()),
            'industry' => 'servicos',
            'segment' => self::SEG,
            'address_city' => 'Cidade Teste',
            'organization_id' => $this->organizationId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides)));
    }

    public function test_store_persiste_as_tres_flags(): void
    {
        $name = 'Empresa teste '.uniqid();

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/companies', $this->payload([
                'name' => $name,
                'is_customer' => true,
                'is_supplier' => true,
                'is_carrier' => false,
            ]))
            ->assertStatus(201);

        $company = Company::query()->withoutGlobalScopes()->where('name', $name)->first();
        $this->assertNotNull($company, 'a empresa deveria ter sido criada.');
        $this->assertTrue((bool) $company->is_customer);
        $this->assertTrue((bool) $company->is_supplier);
        $this->assertFalse((bool) $company->is_carrier);
    }

    public function test_store_sem_flags_mantem_default_false(): void
    {
        // O "Cliente por padrão" é aplicado na UI (chip pré-marcado envia is_customer=1);
        // a API mantém o default false quando o payload omite — trava a decisão.
        $name = 'Empresa teste '.uniqid();

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/companies', $this->payload(['name' => $name]))
            ->assertStatus(201);

        $company = Company::query()->withoutGlobalScopes()->where('name', $name)->first();
        $this->assertNotNull($company);
        $this->assertFalse((bool) $company->is_customer);
        $this->assertFalse((bool) $company->is_supplier);
        $this->assertFalse((bool) $company->is_carrier);
    }

    public function test_update_alterna_as_flags(): void
    {
        $id = $this->seedCompany(['is_customer' => true, 'is_supplier' => false, 'is_carrier' => false]);

        $this->withHeaders($this->authHeaders())
            ->patchJson("/api/companies/{$id}", [
                'is_customer' => false,
                'is_supplier' => true,
                'is_carrier' => true,
            ])
            ->assertStatus(200);

        $company = Company::query()->withoutGlobalScopes()->find($id);
        $this->assertFalse((bool) $company->is_customer);
        $this->assertTrue((bool) $company->is_supplier);
        $this->assertTrue((bool) $company->is_carrier);
    }

    public function test_update_parcial_nao_toca_flag_omitida(): void
    {
        // PATCH que só liga is_carrier mantém is_customer intacto (sometimes por flag).
        $id = $this->seedCompany(['is_customer' => true, 'is_supplier' => false, 'is_carrier' => false]);

        $this->withHeaders($this->authHeaders())
            ->patchJson("/api/companies/{$id}", ['is_carrier' => true])
            ->assertStatus(200);

        $company = Company::query()->withoutGlobalScopes()->find($id);
        $this->assertTrue((bool) $company->is_customer, 'flag omitida no PATCH deve permanecer.');
        $this->assertFalse((bool) $company->is_supplier);
        $this->assertTrue((bool) $company->is_carrier);
    }

    public function test_resource_expoe_as_tres_flags_na_listagem(): void
    {
        // A resposta da listagem deve trazer as três flags de classificação.
        $id = $this->seedCompany(['is_customer' => true, 'is_supplier' => false, 'is_carrier' => true]);

        $resp = $this->withHeaders($this->authHeaders())
            ->getJson('/api/companies/?page_size=200&segment='.self::SEG)
            ->assertStatus(200);

        $row = collect($resp->json('results'))->firstWhere('id', $id);
        $this->assertNotNull($row, 'a empresa semeada deveria aparecer na listagem.');
        $this->assertTrue($row['is_customer']);
        $this->assertFalse($row['is_supplier']);
        $this->assertTrue($row['is_carrier']);
    }
}
