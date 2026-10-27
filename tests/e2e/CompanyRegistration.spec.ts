import { expect, test, type Page, type Request } from '@playwright/test';

const TEST_EMAIL = process.env.PORTFOLIO_TEST_EMAIL;
const TEST_PASSWORD = process.env.PORTFOLIO_TEST_PASSWORD;
const MANUAL_LOGIN = process.env.PORTFOLIO_MANUAL_LOGIN === '1';

function calcularDigito(base: string, pesos: number[]): number {
  const soma = pesos.reduce((total, peso, indice) => total + Number(base[indice]) * peso, 0);
  const resto = soma % 11;
  return resto < 2 ? 0 : 11 - resto;
}

/** Gera um CNPJ fictício válido para evitar colisão entre execuções no ambiente de teste. */
function gerarCnpjDeTeste(): string {
  const raiz = Math.floor(10_000_000 + Math.random() * 90_000_000).toString();
  const base = `${raiz}0001`;
  const primeiroDigito = calcularDigito(base, [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
  const segundoDigito = calcularDigito(`${base}${primeiroDigito}`, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);

  return `${base}${primeiroDigito}${segundoDigito}`;
}

function isCreateCompanyRequest(request: Request): boolean {
  const path = new URL(request.url()).pathname;
  return request.method() === 'POST' && /\/api\/companies\/?$/.test(path);
}

async function stubExternalLookups(page: Page): Promise<void> {
  await page.route('https://servicodados.ibge.gov.br/api/v1/localidades/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    const origin = route.request().headers().origin ?? '*';

    if (path.endsWith('/estados')) {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        headers: { 'access-control-allow-origin': origin },
        body: JSON.stringify([{ id: 8, sigla: 'ES', nome: 'Espírito Santo' }]),
      });
      return;
    }

    if (path.endsWith('/municipios')) {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        headers: { 'access-control-allow-origin': origin },
        body: JSON.stringify([{ id: 1, nome: 'Vitória' }]),
      });
      return;
    }

    await route.continue();
  });

  // O cadastro continua usando a API real; somente a consulta auxiliar é isolada.
  await page.route('**/companies/lookup-cnpj**', async (route) => {
    const request = route.request();
    const origin = request.headers().origin ?? '*';

    if (request.method() === 'OPTIONS') {
      await route.fulfill({
        status: 204,
        headers: {
          'access-control-allow-origin': origin,
          'access-control-allow-methods': 'GET, OPTIONS',
          'access-control-allow-headers': 'authorization, content-type, accept, x-organization-id',
        },
      });
      return;
    }

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      headers: { 'access-control-allow-origin': origin },
      body: JSON.stringify({ found: false, data: null }),
    });
  });
}

async function entrarEabrirCadastro(page: Page): Promise<void> {
  await page.goto('/auth', { waitUntil: 'domcontentloaded', timeout: 30_000 });

  if (MANUAL_LOGIN) {
    console.log('Faça o login manual nesta janela do Playwright e depois clique em Resume no Inspector.');
    await page.pause();
  } else {
    if (!TEST_EMAIL || !TEST_PASSWORD) {
      throw new Error(
        'Defina PORTFOLIO_TEST_EMAIL e PORTFOLIO_TEST_PASSWORD, ou PORTFOLIO_MANUAL_LOGIN=1 para entrar manualmente.',
      );
    }

    await page.getByLabel('Email').fill(TEST_EMAIL);
    await page.getByLabel('Senha').fill(TEST_PASSWORD);
    await page.getByRole('button', { name: 'Entrar' }).click();
  }

  await expect(page).not.toHaveURL((url) => url.pathname === '/auth', { timeout: 30_000 });

  await page.goto('/cadastros?tab=companies', {
    waitUntil: 'domcontentloaded',
    timeout: 30_000,
  });
  await expect(page.getByRole('button', { name: /nova empresa/i })).toBeVisible();
}

test('cadastra uma empresa cliente e encontra o registro na lista', async ({ page }) => {
  await stubExternalLookups(page);
  await entrarEabrirCadastro(page);

  const nome = `Empresa de Teste ${Date.now()}`;
  const cnpj = gerarCnpjDeTeste();

  await page.getByRole('button', { name: /nova empresa/i }).click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  await dialog.getByLabel(/^Nome/).fill(nome);
  await dialog.getByRole('textbox', { name: /CNPJ/i }).fill(cnpj);

  const mercado = dialog.locator('label[for="industry"]').locator('..');
  await mercado.getByRole('combobox').click();
  await page.getByRole('option', { name: 'Serviços', exact: true }).click();

  const estado = dialog.locator('label[for="address_state"]').locator('..');
  await estado.getByRole('combobox').click();
  await page.getByPlaceholder('Buscar estado...').fill('Espírito Santo');
  await page.getByRole('option', { name: /Espírito Santo/ }).click();

  const cidade = dialog.locator('label[for="address_city"]').locator('..');
  const seletorDeCidade = cidade.getByRole('combobox');
  await expect(seletorDeCidade).toBeEnabled();
  await seletorDeCidade.click();
  await page.getByPlaceholder('Buscar cidade...').fill('Vitória');
  await page.getByRole('option', { name: 'Vitória', exact: true }).click();

  const respostaDaCriacao = page.waitForResponse((response) =>
    isCreateCompanyRequest(response.request()),
  );
  const botaoCriar = dialog.getByRole('button', { name: 'Criar Empresa' });
  await expect(botaoCriar).toBeEnabled();
  await botaoCriar.click();
  const resposta = await respostaDaCriacao;

  expect(resposta.status()).toBe(201);
  const empresaCriada = await resposta.json();
  expect(empresaCriada.name).toBe(nome);
  expect(empresaCriada.is_customer).toBe(true);
  console.log('Registro sintético criado no QA:', { nome, cnpj });

  const busca = page.getByPlaceholder(/buscar por nome, CNPJ/i);
  await busca.fill(nome);
  await expect(page.getByRole('row').filter({ hasText: nome })).toBeVisible();
});
