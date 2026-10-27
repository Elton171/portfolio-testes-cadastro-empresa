<?php

namespace Tests\Feature\CRM\Portfolio;

use App\Models\Company\Company;
use Tests\Concerns\GeneratesValidCnpj;
use Tests\Support\DisposableApiTestCase;

/**
 * Verifica a unicidade do CNPJ por organização, incluindo máscara, espaços,
 * edição do próprio registro e registros migrados.
 */
class CompanyCnpjNormalizedUniquenessTest extends DisposableApiTestCase
{
    use GeneratesValidCnpj;

    private int $organizationId;

    private int $otherOrganizationId;

    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizationId = $this->makeOrganization('Organização A');
        $this->otherOrganizationId = $this->makeOrganization('Organização B');
        $this->adminId = $this->makeUser(superAdmin: true, nome: 'Administrador de teste');
    }

    private function authHeaders(?int $organizationId = null): array
    {
        return $this->headers($this->adminId, $organizationId ?? $this->organizationId);
    }

    private function requiredCompanyFields(): array
    {
        return ['industry' => 'servicos', 'address_city' => 'Cidade Teste'];
    }

    /** Payload de criação com os campos obrigatórios preenchidos. */
    private function createPayload(string $cnpj, string $nome): array
    {
        return array_merge(['name' => $nome, 'cnpj' => $cnpj], $this->requiredCompanyFields());
    }

    public function test_cnpj_pontuado_bloqueia_criacao_do_mesmo_sem_pontuacao(): void
    {
        $digits = $this->validCnpjDigits();
        $formatted = $this->formatCnpjDigits($digits);

        // 1) Cria a "migrada" com o CNPJ PONTUADO.
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/companies', $this->createPayload($formatted, 'Empresa de teste A '.uniqid()))
            ->assertStatus(201);

        // 2) Tenta criar OUTRA com o MESMO CNPJ, porém SEM pontuação -> deve barrar.
        $dup = $this->withHeaders($this->authHeaders())
            ->postJson('/api/companies', $this->createPayload($digits, 'Empresa de teste B '.uniqid()));

        $dup->assertStatus(400)->assertJsonStructure(['detail']);
        $this->assertStringContainsString('CNPJ', (string) $dup->json('detail'));
        $this->assertArrayNotHasKey('exception', (array) $dup->json());
    }

    public function test_cnpj_sem_pontuacao_bloqueia_criacao_do_mesmo_pontuado(): void
    {
        $digits = $this->validCnpjDigits();
        $formatted = $this->formatCnpjDigits($digits);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/companies', $this->createPayload($digits, 'Empresa teste C '.uniqid()))
            ->assertStatus(201);

        $dup = $this->withHeaders($this->authHeaders())
            ->postJson('/api/companies', $this->createPayload($formatted, 'Empresa teste D '.uniqid()));

        $dup->assertStatus(400)->assertJsonStructure(['detail']);
        $this->assertStringContainsString('CNPJ', (string) $dup->json('detail'));
    }

    public function test_editar_o_proprio_registro_com_mesmo_cnpj_nao_bloqueia(): void
    {
        $digits = $this->validCnpjDigits();
        $formatted = $this->formatCnpjDigits($digits);

        $created = $this->withHeaders($this->authHeaders())
            ->postJson('/api/companies', $this->createPayload($formatted, 'Empresa teste Self '.uniqid()))
            ->assertStatus(201);

        $id = (int) $created->json('id');

        // PATCH da MESMA empresa com o CNPJ agora sem pontuação — self-match não colide.
        $this->withHeaders($this->authHeaders())
            ->patchJson("/api/companies/{$id}", [
                'cnpj' => $digits,
                'trade_name' => 'Atualizado',
            ])
            ->assertStatus(200);
    }

    public function test_registro_migrado_com_numero_em_document_bloqueia_por_cnpj(): void
    {
        // O cadastro antigo guardou o documento sem preencher `cnpj`. Um novo
        // cadastro com o mesmo número deve ser reconhecido pelo campo legado.
        $digits = $this->validCnpjDigits();

        Company::query()->withoutGlobalScopes()->create(Company::fillCreateDefaults([
            'name' => 'Empresa migrada '.uniqid(),
            'cnpj' => '',
            'document' => $digits,
            'organization_id' => $this->organizationId,
        ]));

        $dup = $this->withHeaders($this->authHeaders())
            ->postJson('/api/companies', $this->createPayload($digits, 'Empresa nova '.uniqid()));

        $dup->assertStatus(400)->assertJsonStructure(['detail']);
        $this->assertStringContainsString('CNPJ', (string) $dup->json('detail'));
    }

    public function test_update_de_outra_empresa_para_cnpj_existente_bloqueia(): void
    {
        $digitsA = $this->validCnpjDigits();
        $formattedA = $this->formatCnpjDigits($digitsA);
        $digitsB = $this->validCnpjDigits();

        // Empresa A (dona do CNPJ).
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/companies', $this->createPayload($formattedA, 'Empresa edição A '.uniqid()))
            ->assertStatus(201);

        // Empresa B com CNPJ diferente.
        $created = $this->withHeaders($this->authHeaders())
            ->postJson('/api/companies', $this->createPayload(
                $this->formatCnpjDigits($digitsB),
                'Empresa edição B '.uniqid(),
            ))
            ->assertStatus(201);
        $idB = (int) $created->json('id');

        // PATCH de B para o CNPJ de A (sem pontuação) -> colide -> 400.
        $dup = $this->withHeaders($this->authHeaders())
            ->patchJson("/api/companies/{$idB}", ['cnpj' => $digitsA]);

        $dup->assertStatus(400)->assertJsonStructure(['detail']);
        $this->assertStringContainsString('CNPJ', (string) $dup->json('detail'));
    }

    public function test_mesmo_cnpj_permitido_em_outra_organizacao(): void
    {
        $digits = $this->validCnpjDigits();
        $formatted = $this->formatCnpjDigits($digits);

        // Primeira organização.
        $this->withHeaders($this->authHeaders($this->organizationId))
            ->postJson('/api/companies', $this->createPayload(
                $formatted,
                'Empresa organização A '.uniqid(),
            ))
            ->assertStatus(201);

        // Mesmo CNPJ em outra organização — a unicidade é por organização → permitido.
        $this->withHeaders($this->authHeaders($this->otherOrganizationId))
            ->postJson('/api/companies', $this->createPayload(
                $digits,
                'Empresa organização B '.uniqid(),
            ))
            ->assertStatus(201);
    }

    public function test_cnpj_distinto_nao_bloqueia(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/companies', $this->createPayload(
                $this->formatCnpjDigits($this->validCnpjDigits()),
                'Empresa teste E '.uniqid(),
            ))
            ->assertStatus(201);

        // CNPJ completamente diferente — passa normalmente.
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/companies', $this->createPayload(
                $this->formatCnpjDigits($this->validCnpjDigits()),
                'Empresa teste F '.uniqid(),
            ))
            ->assertStatus(201);
    }

    /** Espaços (inclusive envolventes) e máscara são removidos antes de comparar. */
    public function test_espacos_e_mascara_sao_normalizados_antes_de_comparar(): void
    {
        $digits = $this->validCnpjDigits();
        $comEspacos = ' '.$this->formatCnpjDigits($digits).' ';

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/companies', $this->createPayload($comEspacos, 'Empresa espaços A '.uniqid()))
            ->assertStatus(201);

        // Mesmos dígitos, sem espaço/máscara → deve colidir (normalização no backend).
        $dup = $this->withHeaders($this->authHeaders())
            ->postJson('/api/companies', $this->createPayload($digits, 'Empresa espaços B '.uniqid()));

        $dup->assertStatus(400)->assertJsonStructure(['detail']);
        $this->assertStringContainsString('CNPJ', (string) $dup->json('detail'));
    }

    /**
     * O endpoint de cadastro exige CNPJ; o valor vazio deve ser erro de validação.
     */
    public function test_cnpj_vazio_e_rejeitado_no_endpoint_manual(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/companies', array_merge(
                ['name' => 'Empresa sem CNPJ '.uniqid(), 'cnpj' => ''],
                $this->requiredCompanyFields(),
            ))
            ->assertStatus(422)
            ->assertJsonValidationErrors('cnpj');
    }
}
