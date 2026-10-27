import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { Company } from '@/types/crm';

/**
 * Testes de componente do cadastro e da edição de empresas.
 *
 * Verificam como os diálogos transformam os campos preenchidos em payloads de criação e edição.
 *
 * O que estes testes seguram:
 *
 *  - sigla e código vão no payload; apagar manda `null` (o campo some do banco);
 *  - o diálogo de edição abre de várias telas e nem todo formato traz sigla, código e vínculo.
 *    Payload que não trouxe o campo NÃO pode mandá-lo de volta zerado — apagaria um valor real
 *    sem ninguém ter mexido nele;
 *  - trocar a mãe recomeça o vínculo do padrão (o servidor faz o mesmo) e limpar a mãe não
 *    manda vínculo nenhum (o servidor zera sozinho);
 *  - fim anterior ao início impede o envio da requisição.
 */

const updateMutateAsync = vi.fn();
const createMutateAsync = vi.fn();
const toastSpy = vi.fn();

vi.mock('@/hooks/useCompanies', () => ({
  useCompanies: () => ({
    // O seletor de grupo lê `companies`; os diálogos usam as mutações mockadas.
    companies: [
      { id: 10, name: 'Grupo Alfa', cnpj: '' },
      { id: 11, name: 'Grupo Beta', cnpj: '' },
    ],
    isFetching: false,
    updateCompany: { mutateAsync: updateMutateAsync, isPending: false },
    createCompany: { mutateAsync: createMutateAsync, isPending: false },
  }),
}));

vi.mock('@/hooks/useSellers', () => ({ useSellers: () => ({ sellers: [] }) }));
vi.mock('@/contexts/AuthContext', () => ({
  useAuth: () => ({ user: { active_role: 'vendedor', is_superuser: false } }),
}));
vi.mock('@/hooks/useBrazilianLocations', () => ({
  useBrazilianStates: () => ({ data: [], isLoading: false }),
  useBrazilianCities: () => ({ data: [], isLoading: false }),
}));
vi.mock('@/hooks/useCnpjLookup', () => ({
  useCnpjLookup: () => ({ data: undefined, isFetching: false, refetch: vi.fn() }),
}));
vi.mock('@/hooks/useCepLookup', () => ({
  useCepLookup: () => ({ data: undefined, isFetching: false }),
}));
vi.mock('@/hooks/usePeople', () => ({ usePeople: () => ({ people: [], isFetching: false }) }));
vi.mock('@/hooks/use-toast', () => ({ toast: (...args: unknown[]) => toastSpy(...args) }));
vi.mock('../CreatePersonDialog', () => ({ CreatePersonDialog: () => null }));
vi.mock('../EditPersonDialog', () => ({ EditPersonDialog: () => null }));
// Nome/mercado/cidade obrigatórios não são o assunto aqui: a validação do formulário passa.
vi.mock('@/lib/validation-schemas', async (orig) => ({
  ...((await orig()) as object),
  validateCompany: () => ({ isValid: true, errors: {}, missingFields: [] }),
}));
vi.mock('@/lib/apiClient', async (orig) => {
  const actual = (await orig()) as Record<string, unknown>;
  return {
    ...actual,
    apiClient: {
      ...(actual.apiClient as object),
      get: vi.fn().mockResolvedValue({ results: [] }),
      patch: vi.fn(),
    },
  };
});

import { CreateCompanyDialog } from '../CreateCompanyDialog';
import { EditCompanyDialog } from '../EditCompanyDialog';

/** CNPJ com dígito verificador válido — o diálogo barra o inválido antes do payload. */
const CNPJ_VALIDO = '11.222.333/0001-81';

function comQuery(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

function empresa(extra: Partial<Company> = {}): Company {
  return {
    id: 5,
    name: 'Empresa Exemplo',
    cnpj: CNPJ_VALIDO,
    industry: 'industria',
    address_city: 'Rio de Janeiro',
    address_state: 'RJ',
    status: 'customer',
    icms_contributor: true,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    ...extra,
  } as Company;
}

/** Fixture para uma associação empresarial já salva. */
const JOINT_VENTURE: Partial<Company> = {
  sigla: 'CAB',
  codigo_interno: null,
  parent_company_id: 10,
  parent_company_name: 'Grupo Alfa',
  vinculo_tipo: 'joint_venture',
  vinculo_participacao_pct: '60.00',
  vinculo_inicio: '2020-01-01',
  vinculo_fim: null,
  vinculo_nota: 'Participação restante de sócia fictícia',
  vinculo_situacao: 'ativo',
};

function abrirSeletorDaMae(textoNoGatilho: string) {
  const gatilho = screen
    .getAllByRole('combobox')
    .find((el) => el.textContent?.includes(textoNoGatilho));
  if (!gatilho) throw new Error(`seletor da mãe com "${textoNoGatilho}" não encontrado`);
  fireEvent.click(gatilho);
}

function salvarEdicao() {
  fireEvent.click(screen.getByRole('button', { name: 'Salvar Alterações' }));
}

function payloadDaEdicao(): Record<string, unknown> {
  expect(updateMutateAsync).toHaveBeenCalledTimes(1);
  return updateMutateAsync.mock.calls[0][0] as Record<string, unknown>;
}

describe('EditCompanyDialog — sigla, código e vínculo no PATCH', () => {
  beforeEach(() => {
    updateMutateAsync.mockReset().mockResolvedValue({});
    toastSpy.mockReset();
  });

  it('devolve o vínculo gravado e manda a sigla editada; código vazio vai como null', async () => {
    comQuery(<EditCompanyDialog open onOpenChange={vi.fn()} company={empresa(JOINT_VENTURE)} />);

    expect(screen.getByText('Vínculo com Grupo Alfa')).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Sigla'), { target: { value: 'CABX' } });
    salvarEdicao();

    await waitFor(() => expect(updateMutateAsync).toHaveBeenCalled());
    expect(payloadDaEdicao()).toEqual(
      expect.objectContaining({
        sigla: 'CABX',
        codigo_interno: null,
        parent_company_id: 10,
        vinculo_tipo: 'joint_venture',
        vinculo_participacao_pct: 60,
        vinculo_inicio: '2020-01-01',
        vinculo_fim: null,
        vinculo_nota: 'Participação restante de sócia fictícia',
      }),
    );
  });

  it('apagar a sigla manda null', async () => {
    comQuery(<EditCompanyDialog open onOpenChange={vi.fn()} company={empresa(JOINT_VENTURE)} />);

    fireEvent.change(screen.getByLabelText('Sigla'), { target: { value: '' } });
    salvarEdicao();

    await waitFor(() => expect(updateMutateAsync).toHaveBeenCalled());
    expect(payloadDaEdicao()).toEqual(expect.objectContaining({ sigla: null }));
  });

  it('payload que não trouxe sigla, código nem vínculo não os manda de volta zerados', async () => {
    // Uma resposta legada pode trazer a empresa mãe sem os detalhes do vínculo.
    comQuery(
      <EditCompanyDialog
        open
        onOpenChange={vi.fn()}
        company={empresa({ parent_company_id: 10, parent_company_name: 'Grupo Alfa' })}
      />,
    );

    // Sem saber o vínculo gravado, o campo nem aparece: mostrar "Controlada" e salvar apagaria
    // tipo, % e datas reais.
    expect(screen.queryByText('Vínculo com Grupo Alfa')).not.toBeInTheDocument();
    salvarEdicao();

    await waitFor(() => expect(updateMutateAsync).toHaveBeenCalled());
    const payload = payloadDaEdicao();
    expect(payload).not.toHaveProperty('sigla');
    expect(payload).not.toHaveProperty('codigo_interno');
    expect(payload).not.toHaveProperty('vinculo_tipo');
    expect(payload).not.toHaveProperty('vinculo_fim');
  });

  it('mesmo sem a sigla no payload, a sigla DIGITADA vai', async () => {
    comQuery(<EditCompanyDialog open onOpenChange={vi.fn()} company={empresa()} />);

    fireEvent.change(screen.getByLabelText('Sigla'), { target: { value: 'SANEX' } });
    salvarEdicao();

    await waitFor(() => expect(updateMutateAsync).toHaveBeenCalled());
    const payload = payloadDaEdicao();
    expect(payload).toEqual(expect.objectContaining({ sigla: 'SANEX' }));
    expect(payload).not.toHaveProperty('codigo_interno');
  });

  it('trocar a mãe recomeça o vínculo do padrão — tipo, % e datas eram da mãe antiga', async () => {
    comQuery(<EditCompanyDialog open onOpenChange={vi.fn()} company={empresa(JOINT_VENTURE)} />);

    abrirSeletorDaMae('Grupo Alfa');
    fireEvent.click(screen.getByText('Grupo Beta'));
    expect(screen.getByText('Vínculo com Grupo Beta')).toBeInTheDocument();
    salvarEdicao();

    await waitFor(() => expect(updateMutateAsync).toHaveBeenCalled());
    expect(payloadDaEdicao()).toEqual(
      expect.objectContaining({
        parent_company_id: 11,
        vinculo_tipo: 'controlada',
        vinculo_participacao_pct: null,
        vinculo_inicio: null,
        vinculo_fim: null,
        vinculo_nota: null,
      }),
    );
  });

  it('limpar a mãe some com o bloco e não manda vínculo (o servidor zera sozinho)', async () => {
    comQuery(<EditCompanyDialog open onOpenChange={vi.fn()} company={empresa(JOINT_VENTURE)} />);

    abrirSeletorDaMae('Grupo Alfa');
    fireEvent.click(screen.getByText('Não pertence a grupo'));
    expect(screen.queryByText(/^Vínculo com/)).not.toBeInTheDocument();
    salvarEdicao();

    await waitFor(() => expect(updateMutateAsync).toHaveBeenCalled());
    const payload = payloadDaEdicao();
    expect(payload).toEqual(expect.objectContaining({ parent_company_id: null }));
    expect(payload).not.toHaveProperty('vinculo_tipo');
  });

  it('fim antes do início não chega ao servidor', async () => {
    comQuery(
      <EditCompanyDialog
        open
        onOpenChange={vi.fn()}
        company={empresa({ ...JOINT_VENTURE, vinculo_inicio: '2026-05-10' })}
      />,
    );

    fireEvent.change(screen.getByLabelText('Fim'), { target: { value: '2026-05-01' } });
    salvarEdicao();

    await waitFor(() =>
      expect(toastSpy).toHaveBeenCalledWith(
        expect.objectContaining({ description: 'O fim do vínculo não pode ser antes do início.' }),
      ),
    );
    expect(updateMutateAsync).not.toHaveBeenCalled();
  });
});

describe('CreateCompanyDialog — sigla, código e vínculo no POST', () => {
  beforeEach(() => {
    createMutateAsync.mockReset().mockResolvedValue({ id: 99, name: 'Nova' });
    toastSpy.mockReset();
  });

  function preencherCnpjECriar() {
    fireEvent.change(screen.getByPlaceholderText('00.000.000/0000-00'), {
      target: { value: CNPJ_VALIDO },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Criar Empresa' }));
  }

  it('manda a sigla e o código digitados; sem mãe, nada de vínculo', async () => {
    comQuery(<CreateCompanyDialog open onOpenChange={vi.fn()} />);

    fireEvent.change(screen.getByLabelText('Sigla'), { target: { value: ' CAB ' } });
    fireEvent.change(screen.getByLabelText('Código interno do cliente'), { target: { value: '0042' } });
    preencherCnpjECriar();

    await waitFor(() => expect(createMutateAsync).toHaveBeenCalled());
    const payload = createMutateAsync.mock.calls[0][0] as Record<string, unknown>;
    expect(payload).toEqual(expect.objectContaining({ sigla: 'CAB', codigo_interno: '0042' }));
    expect(payload).not.toHaveProperty('vinculo_tipo');
  });

  it('sem sigla nem código digitados, as chaves nem vão', async () => {
    comQuery(<CreateCompanyDialog open onOpenChange={vi.fn()} />);

    preencherCnpjECriar();

    await waitFor(() => expect(createMutateAsync).toHaveBeenCalled());
    const payload = createMutateAsync.mock.calls[0][0] as Record<string, unknown>;
    expect(payload).not.toHaveProperty('sigla');
    expect(payload).not.toHaveProperty('codigo_interno');
  });

  it('com mãe escolhida, o vínculo nasce controlado e leva o que foi preenchido', async () => {
    comQuery(<CreateCompanyDialog open onOpenChange={vi.fn()} />);

    abrirSeletorDaMae('Não pertence a grupo');
    fireEvent.click(screen.getByText('Grupo Alfa'));
    fireEvent.change(screen.getByLabelText('Fim'), { target: { value: '2026-12-31' } });
    preencherCnpjECriar();

    await waitFor(() => expect(createMutateAsync).toHaveBeenCalled());
    expect(createMutateAsync.mock.calls[0][0]).toEqual(
      expect.objectContaining({
        parent_company_id: 10,
        vinculo_tipo: 'controlada',
        vinculo_participacao_pct: null,
        vinculo_fim: '2026-12-31',
      }),
    );
  });
});

