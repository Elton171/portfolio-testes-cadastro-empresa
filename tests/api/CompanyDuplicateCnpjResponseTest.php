<?php

namespace Tests\Feature\CRM\Portfolio;

use Tests\Concerns\GeneratesValidCnpj;
use Tests\Support\DisposableApiTestCase;

/**
 * Confere se o endpoint devolve erro controlado quando recebe um CNPJ duplicado.
 */
class CompanyDuplicateCnpjResponseTest extends DisposableApiTestCase
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

    private function requiredCompanyFields(): array
    {
        return ['industry' => 'servicos', 'address_city' => 'Cidade Teste'];
    }

    public function test_cnpj_duplicado_retorna_400_com_detalhe_amigavel(): void
    {
        $cnpj = $this->formatCnpjDigits($this->validCnpjDigits());

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/companies', array_merge([
                'name' => 'Empresa de teste A '.uniqid(),
                'cnpj' => $cnpj,
            ], $this->requiredCompanyFields()))
            ->assertStatus(201);

        $dup = $this->withHeaders($this->authHeaders())
            ->postJson('/api/companies', array_merge([
                'name' => 'Empresa de teste B '.uniqid(),
                'cnpj' => $cnpj,
            ], $this->requiredCompanyFields()));

        $dup->assertStatus(400)
            ->assertJsonStructure(['detail']);

        $this->assertStringContainsString('CNPJ', (string) $dup->json('detail'));
        // O cliente recebe o detalhe de validação sem dados técnicos da exceção.
        $this->assertArrayNotHasKey('exception', (array) $dup->json());
    }
}
