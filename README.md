# Portfólio de testes do cadastro de empresas

## Visão rápida

Este portfólio mostra como testar o cadastro de uma empresa cliente em um CRM real usando a pirâmide de testes. Cada camada cobre o comportamento que consegue validar melhor: regras locais, montagem dos dados na interface, respostas e persistência da API, e o fluxo completo no navegador.

O repositório contém testes e documentação, não a aplicação do CRM.

## O que há nesta pasta

```text
README.md
.gitignore
tests/
├── unit/frontend/       # Regras puras do formulário e do CNPJ
├── component/frontend/  # Comportamento dos diálogos de cadastro e edição
├── api/                 # Validações, respostas e persistência no Laravel
└── e2e/                 # Um cadastro completo pela interface
```

## Mapa da cobertura

| Camada | Responsabilidade | Material | Casos |
|---|---|---|---:|
| Unidade — frontend | Regras puras de máscara, normalização, dígitos verificadores e validação imediata dos campos do formulário. | `tests/unit/frontend/cnpj.test.ts`; `tests/unit/frontend/company-validation.test.ts` | 15 |
| Componente — frontend | Como os diálogos de cadastro e edição montam o payload, preservam campos desconhecidos e bloqueiam uma data final anterior antes da requisição. | `tests/component/frontend/CompanyDialogPayload.test.tsx` | 10 |
| API — Laravel | Respostas HTTP, validações no servidor, persistência, unicidade normalizada, flags e regras de responsável. | Os cinco arquivos em `tests/api/` | 37 |
| E2E — navegador | Autenticação pela tela, cadastro pela interface, resposta real da API e localização do registro na lista. | `tests/e2e/CompanyRegistration.spec.ts` | 1 |

São **63 casos distribuídos** nas quatro camadas. O E2E usa a API real para criar o registro; somente as consultas auxiliares de CNPJ e localidades são isoladas para não depender de serviços externos.

## Por que cada regra fica nessa camada

- **Unidade:** testar as funções diretamente é rápido e localiza falhas na lógica de CNPJ e no retorno da validação do formulário. Não prova que a API persistiu os dados.
- **Componente:** os hooks de gravação são mockados; os testes inspecionam o payload produzido pelos diálogos e se uma ação inválida evita a chamada. Não provam que o servidor aceitou ou gravou esse payload.
- **API:** os testes chamam as rotas Laravel e conferem resposta e, quando aplicável, o banco descartável. Regras importantes continuam protegidas no servidor mesmo quando alguém não usa a interface.
- **E2E:** deve ficar reservado a poucos fluxos de maior impacto, verificando a integração entre tela, API e resultado visível. Não é a camada para enumerar cada variação de máscara ou cada resposta HTTP.

Algumas regras aparecem em mais de uma camada por motivos diferentes. Por exemplo, o frontend verifica o CNPJ para orientar a pessoa durante o preenchimento, enquanto a API repete a validação para proteger dados recebidos por qualquer cliente. O teste unitário não substitui o teste API.

## Testes de unidade

Os dois arquivos de funções puras foram preparados para o portfólio e exercitam funções existentes no CRM (`src/lib/cnpj.ts` e `validateCompany`). Eles não alteram essas funções. A suíte verifica:

- remoção de pontuação e espaços, aplicação progressiva da máscara e limite de dígitos;
- aceitação de CNPJ válido e rejeição de dígito incorreto, sequência repetida ou quantidade incompleta;
- campos obrigatórios, rótulos apresentados pela tela, campos opcionais e limite de tamanho do nome.

O validador frontend `validateCompany` verifica obrigatoriedade e tamanho do nome. A regra da API que restringe `industry` à lista canônica é validada no backend; o helper frontend, isoladamente, não implementa essa lista.

## Testes de componente

`tests/component/frontend/CompanyDialogPayload.test.tsx` adapta uma suíte de componente existente do CRM e remove referências internas ao produto. Ela cobre os diálogos de cadastro e edição:

- inclusão, edição e remoção de sigla/código no payload;
- omissão de campos ausentes em respostas antigas, para evitar apagar valores sem intenção;
- troca ou remoção de empresa mãe e serialização dos campos do vínculo;
- bloqueio no formulário quando a data final precede a inicial.

O teste de componente prova o comportamento da interface antes da requisição. Há testes API mais amplos no CRM para persistência e validação de vínculo; esse conjunto complementar não pretende substituir nem duplicar essa evidência do servidor.

## Testes de API incluídos

Os 37 casos estão agrupados por comportamento:

| Arquivo | Casos | Cobertura |
|---|---:|---|
| `CompanyRequiredFieldsValidationTest.php` | 11 | Cadastro válido, campos obrigatórios, CNPJ inválido e atualização parcial. |
| `CompanyCnpjNormalizedUniquenessTest.php` | 9 | CNPJ com ou sem máscara, espaços, edição, registros antigos e escopo por organização. |
| `CompanyDuplicateCnpjResponseTest.php` | 1 | Resposta `400` com detalhe amigável para CNPJ duplicado. |
| `CompanyTypeFlagsTest.php` | 5 | Persistência, padrão, atualização e retorno das flags de cliente, fornecedor e transportadora. |
| `CompanyOwnerChangeGuardTest.php` | 11 | Permissões para trocar responsável e regra de carteira do vendedor, inclusive pela rota de consolidação. |

O caso de duplicidade verifica a resposta da API. A validação de unicidade da aplicação ocorre antes da gravação; esse teste não afirma que provocou uma exceção SQL do banco. O recorte de 37 testes também não representa todas as suítes API existentes no checkout do CRM.

## Teste E2E

`tests/e2e/CompanyRegistration.spec.ts` atravessa o login, os campos da tela, a API de criação e a listagem. Ele gera nome e CNPJ fictícios, confere a resposta `201` e `is_customer: true` e procura o novo registro na tabela. O teste não consulta o banco diretamente; a persistência é coberta pelos testes API.
