<?php

namespace Tests\Feature\CRM\Portfolio;

use App\Models\Company\Company;
use App\Models\Organization\OrganizationMember;
use App\Services\Deals\VisibilidadeDeNegocios;
use App\Support\AppSettings\AppSettingsService;
use Tests\Concerns\GrantsPermissionsViaGroup;
use Tests\Concerns\GeneratesValidCnpj;
use Tests\Support\DisposableApiTestCase;

/**
 * Confere quem pode alterar o responsável da empresa e garante que vendedor
 * sempre crie empresas na própria carteira, inclusive pela rota de consolidação.
 */
class CompanyOwnerChangeGuardTest extends DisposableApiTestCase
{
    use GrantsPermissionsViaGroup;
    use GeneratesValidCnpj;

    private int $organizationId;

    private const SEG = '__own_seg_marker__';

    /** Super-admin: o "outro dono" e o papel que PODE reatribuir responsável. */
    private int $admin;

    /** Membro da org cujo papel muda por teste (vendedor / member / admin). */
    private int $vendedor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizationId = $this->makeOrganization('Organização de teste');

        $this->admin = $this->makeUser(superAdmin: true, nome: 'Administrador de teste');
        $this->vendedor = $this->makeUser(nome: 'Vendedor de teste');
        $this->makeMember($this->vendedor, $this->organizationId, 'vendedor');

        $this->disableBusinessVisibility();
    }

    private function disableBusinessVisibility(): void
    {
        foreach ([$this->organizationId, null] as $organizationId) {
            AppSettingsService::set(
                VisibilidadeDeNegocios::CHAVE,
                false,
                $organizationId,
                VisibilidadeDeNegocios::DESCRICAO,
                VisibilidadeDeNegocios::CATEGORIA,
            );
        }

        $this->app->make(VisibilidadeDeNegocios::class)->esquecer();
    }

    private function authHeaders(int $userId): array
    {
        return $this->headers($userId, $this->organizationId);
    }

    private function setRole(string $role): void
    {
        OrganizationMember::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $this->organizationId)
            ->where('user_id', $this->vendedor)
            ->update(['role' => $role]);
    }

    private function seedCompany(array $overrides): int
    {
        return Company::query()->withoutGlobalScopes()->insertGetId(array_merge([
            'name' => 'Empresa teste',
            'trade_name' => '',
            'cnpj' => '',
            'email' => '',
            'phone' => '',
            'website' => '',
            'industry' => '',
            'segment' => self::SEG,
            'size' => '',
            'icms_contributor' => false,
            'status' => 'prospect',
            'origin' => 'other',
            'address_street' => '',
            'address_number' => '',
            'address_complement' => '',
            'address_neighborhood' => '',
            'address_city' => '',
            'address_state' => '',
            'address_zip' => '',
            'notes' => '',
            'document' => '',
            'document_type' => '',
            'is_customer' => false,
            'is_supplier' => false,
            'is_carrier' => false,
            'owner_id' => null,
            'created_by_id' => null,
            'organization_id' => $this->organizationId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function ownerInDb(int $companyId): ?int
    {
        $owner = Company::query()->withoutGlobalScopes()
            ->whereKey($companyId)
            ->value('owner_id');

        return $owner === null ? null : (int) $owner;
    }

    public function test_vendedor_nao_muda_owner_da_propria(): void
    {
        $this->setRole('vendedor');
        $this->grantViaGroup($this->vendedor, $this->organizationId, ['companies.view', 'companies.update']);

        $id = $this->seedCompany(['name' => 'Empresa teste vend__', 'owner_id' => $this->vendedor]);

        $this->withHeaders($this->authHeaders($this->vendedor))
            ->patchJson("/api/companies/{$id}", ['owner' => $this->admin])
            ->assertStatus(403)
            ->assertJsonPath('detail', 'Apenas administradores podem alterar o responsável da empresa.');

        $this->assertSame($this->vendedor, $this->ownerInDb($id), 'owner não pode ter mudado.');
    }

    public function test_update_owner_igual_ao_atual_passa(): void
    {
        $this->setRole('vendedor');
        $this->grantViaGroup($this->vendedor, $this->organizationId, ['companies.view', 'companies.update']);

        $id = $this->seedCompany(['name' => 'Empresa teste noop__', 'owner_id' => $this->vendedor]);

        $this->withHeaders($this->authHeaders($this->vendedor))
            ->patchJson("/api/companies/{$id}", [
                'owner' => $this->vendedor,
                'notes' => 'editado pelo dono',
            ])
            ->assertStatus(200);

        $this->assertSame($this->vendedor, $this->ownerInDb($id));
    }

    public function test_membro_nao_admin_nao_muda_owner(): void
    {
        // Grant primeiro (a base efetiva é lida do estado atual), depois o papel.
        $this->grantViaGroup($this->vendedor, $this->organizationId, ['companies.view', 'companies.update']);
        $this->setRole('member');

        $id = $this->seedCompany(['name' => 'Empresa teste member__', 'owner_id' => $this->admin]);

        $this->withHeaders($this->authHeaders($this->vendedor))
            ->patchJson("/api/companies/{$id}", ['owner' => $this->vendedor])
            ->assertStatus(403);

        $this->assertSame($this->admin, $this->ownerInDb($id));
    }

    public function test_admin_da_org_muda_owner(): void
    {
        $this->grantViaGroup($this->vendedor, $this->organizationId, ['companies.view', 'companies.update']);
        $this->setRole('admin');

        $id = $this->seedCompany(['name' => 'Empresa teste admin__', 'owner_id' => $this->admin]);

        $this->withHeaders($this->authHeaders($this->vendedor))
            ->patchJson("/api/companies/{$id}", ['owner' => $this->vendedor])
            ->assertStatus(200);

        $this->assertSame($this->vendedor, $this->ownerInDb($id),
            'admin da org pode reatribuir o responsável.');
    }

    public function test_super_admin_muda_owner(): void
    {
        $id = $this->seedCompany(['name' => 'Empresa teste super__', 'owner_id' => $this->vendedor]);

        $this->withHeaders($this->authHeaders($this->admin))
            ->patchJson("/api/companies/{$id}", ['owner' => $this->admin])
            ->assertStatus(200);

        $this->assertSame($this->admin, $this->ownerInDb($id));
    }

    public function test_vendedor_store_forca_owner_self(): void
    {
        $this->setRole('vendedor');
        $this->grantViaGroup($this->vendedor, $this->organizationId, ['companies.view', 'companies.create']);

        $resp = $this->withHeaders($this->authHeaders($this->vendedor))
            ->postJson('/api/companies', [
                'name' => 'Empresa teste store__',
                'segment' => self::SEG,
                'owner' => $this->admin,
                // Campos obrigatórios do StoreCompanyRequest (CNPJ com DV válido +
                // Mercado + cidade) — o foco do teste é o owner forçado.
                'cnpj' => $this->formatCnpjDigits($this->validCnpjDigits()),
                'industry' => 'servicos',
                'address_city' => 'Cidade Teste',
            ])
            ->assertStatus(201);

        $this->assertSame($this->vendedor, $resp->json('owner'),
            'vendedor cria SEMPRE na própria carteira — payload owner é ignorado.');
    }

    // -----------------------------------------------------------------
    // Endpoint irmão: PATCH /api/consolidation/companies/{id} escreve na
    // MESMA coluna owner_id e precisa da MESMA trava (achado da revisão).
    // -----------------------------------------------------------------

    public function test_vendedor_nao_muda_owner_via_consolidation(): void
    {
        $this->setRole('vendedor');
        $this->grantViaGroup($this->vendedor, $this->organizationId, ['companies.view', 'companies.update']);

        $id = $this->seedCompany(['name' => 'Empresa teste cons_vend__', 'owner_id' => $this->vendedor]);

        $this->withHeaders($this->authHeaders($this->vendedor))
            ->patchJson("/api/consolidation/companies/{$id}", ['owner' => $this->admin])
            ->assertStatus(403);

        $this->assertSame($this->vendedor, $this->ownerInDb($id));
    }

    public function test_membro_nao_admin_nao_muda_owner_via_consolidation(): void
    {
        $this->grantViaGroup($this->vendedor, $this->organizationId, ['companies.view', 'companies.update']);
        $this->setRole('member');

        $id = $this->seedCompany(['name' => 'Empresa teste cons_member__', 'owner_id' => $this->admin]);

        $this->withHeaders($this->authHeaders($this->vendedor))
            ->patchJson("/api/consolidation/companies/{$id}", ['owner' => $this->vendedor])
            ->assertStatus(403);

        $this->assertSame($this->admin, $this->ownerInDb($id));
    }

    public function test_admin_muda_owner_via_consolidation(): void
    {
        $this->grantViaGroup($this->vendedor, $this->organizationId, ['companies.view', 'companies.update']);
        $this->setRole('admin');

        $id = $this->seedCompany(['name' => 'Empresa teste cons_admin__', 'owner_id' => $this->admin]);

        $this->withHeaders($this->authHeaders($this->vendedor))
            ->patchJson("/api/consolidation/companies/{$id}", ['owner' => $this->vendedor])
            ->assertStatus(200);

        $this->assertSame($this->vendedor, $this->ownerInDb($id));
    }

    public function test_vendedor_store_via_consolidation_forca_owner_self(): void
    {
        $this->setRole('vendedor');
        $this->grantViaGroup($this->vendedor, $this->organizationId, ['companies.view', 'companies.create']);

        $resp = $this->withHeaders($this->authHeaders($this->vendedor))
            ->postJson('/api/consolidation/companies', [
                'name' => 'Empresa teste cons_store__',
                'segment' => self::SEG,
                'owner' => $this->admin,
            ])
            ->assertStatus(201);

        $created = (int) $resp->json('id');
        $this->assertSame($this->vendedor, $this->ownerInDb($created),
            'vendedor cria SEMPRE na própria carteira também pelo endpoint consolidado.');
    }

    /** Regressão do findForWrite: órfã segue invisível p/ escrita do vendedor. */
    public function test_vendedor_patch_em_orfa_segue_404(): void
    {
        $this->setRole('vendedor');
        $this->grantViaGroup($this->vendedor, $this->organizationId, ['companies.view', 'companies.update']);

        $id = $this->seedCompany(['name' => 'Empresa teste orfa__']);

        $this->withHeaders($this->authHeaders($this->vendedor))
            ->patchJson("/api/companies/{$id}", ['notes' => 'tentativa'])
            ->assertStatus(404);

        $this->assertNull($this->ownerInDb($id));
    }
}
