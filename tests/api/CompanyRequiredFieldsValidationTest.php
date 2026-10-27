<?php

namespace Tests\Feature\CRM\Portfolio;

use App\Models\Company\Company;
use Tests\Concerns\GeneratesValidCnpj;
use Tests\Support\DisposableApiTestCase;

/**
 * Valida campos obrigatórios no cadastro e na edição de empresas.
 */
class CompanyRequiredFieldsValidationTest extends DisposableApiTestCase
{
    use GeneratesValidCnpj;

    private int $organizationId;

    private int $adminId;

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

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Empresa teste '.uniqid(),
            'cnpj' => $this->formatCnpjDigits($this->validCnpjDigits()),
            'industry' => 'servicos',
            'address_city' => 'Cidade Teste',
        ], $overrides);
    }

    private function seedCompany(): int
    {
        return (int) Company::query()->withoutGlobalScopes()->insertGetId(Company::fillCreateDefaults([
            'name' => 'Empresa base '.uniqid(),
            'cnpj' => $this->formatCnpjDigits($this->validCnpjDigits()),
            'industry' => 'servicos',
            'address_city' => 'Cidade Teste',
            'organization_id' => $this->organizationId,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    // -------------------- CREATE --------------------

    public function test_create_com_todos_os_obrigatorios_validos_passa(): void
    {
        $resposta = $this->withHeaders($this->authHeaders())
            ->postJson('/api/companies', $this->payload())
            ->assertStatus(201);

        $empresa = Company::query()->withoutGlobalScopes()->find((int) $resposta->json('id'));

        $this->assertNotNull($empresa, 'A empresa deve estar persistida no banco descartável.');
        $this->assertSame($this->organizationId, (int) $empresa->organization_id);
    }

    public function test_create_cnpj_ausente_422(): void
    {
        $p = $this->payload();
        unset($p['cnpj']);

        $this->withHeaders($this->authHeaders())->postJson('/api/companies', $p)
            ->assertStatus(422)->assertJsonValidationErrors('cnpj');
    }

    public function test_create_cnpj_com_dv_invalido_422(): void
    {
        // 14 dígitos, mas dígito verificador errado.
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/companies', $this->payload(['cnpj' => '11.222.333/4444-55']))
            ->assertStatus(422)->assertJsonValidationErrors('cnpj');
    }

    public function test_create_cnpj_sequencia_repetida_422(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/companies', $this->payload(['cnpj' => '11111111111111']))
            ->assertStatus(422)->assertJsonValidationErrors('cnpj');
    }

    public function test_create_industry_vazio_422(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/companies', $this->payload(['industry' => '']))
            ->assertStatus(422)->assertJsonValidationErrors('industry');
    }

    public function test_create_industry_fora_da_lista_422(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/companies', $this->payload(['industry' => 'petroleo']))
            ->assertStatus(422)->assertJsonValidationErrors('industry');
    }

    public function test_create_cidade_vazia_422(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/companies', $this->payload(['address_city' => '']))
            ->assertStatus(422)->assertJsonValidationErrors('address_city');
    }

    // -------------------- UPDATE --------------------

    public function test_update_cnpj_invalido_422(): void
    {
        $id = $this->seedCompany();

        $this->withHeaders($this->authHeaders())
            ->patchJson("/api/companies/{$id}", ['cnpj' => '12.345.678/0001-00'])
            ->assertStatus(422)->assertJsonValidationErrors('cnpj');
    }

    public function test_update_industry_fora_da_lista_422(): void
    {
        $id = $this->seedCompany();

        $this->withHeaders($this->authHeaders())
            ->patchJson("/api/companies/{$id}", ['industry' => 'nao_existe'])
            ->assertStatus(422)->assertJsonValidationErrors('industry');
    }

    public function test_update_cidade_vazia_422(): void
    {
        $id = $this->seedCompany();

        $this->withHeaders($this->authHeaders())
            ->patchJson("/api/companies/{$id}", ['address_city' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('address_city');
    }

    public function test_update_parcial_sem_campos_obrigatorios_passa(): void
    {
        // PATCH que não toca cnpj/industry/cidade (sometimes) segue válido.
        $id = $this->seedCompany();

        $this->withHeaders($this->authHeaders())
            ->patchJson("/api/companies/{$id}", ['trade_name' => 'Apenas nome fantasia'])
            ->assertStatus(200);
    }
}
