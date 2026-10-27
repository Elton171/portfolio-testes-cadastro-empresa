import { describe, expect, it } from 'vitest';

import { companyRequiredFields, validateCompany } from '@/lib/validation-schemas';

describe('validateCompany', () => {
  it('aceita o cadastro quando nome, mercado e cidade estão preenchidos', () => {
    expect(
      validateCompany({ name: 'Empresa Exemplo', industry: 'servicos', address_city: 'Cidade Exemplo' }),
    ).toEqual({ isValid: true, errors: {}, missingFields: [] });
  });

  it('identifica todos os campos obrigatórios vazios', () => {
    expect(validateCompany({ name: '', industry: '', address_city: '' })).toEqual({
      isValid: false,
      errors: {
        name: 'Nome é obrigatório',
        industry: 'Mercado é obrigatório',
        address_city: 'Cidade é obrigatória',
      },
      missingFields: ['Nome', 'Mercado', 'Cidade'],
    });
  });

  it('permite CNPJ e nome fantasia opcionais vazios', () => {
    expect(
      validateCompany({
        name: 'Empresa Exemplo',
        industry: 'servicos',
        address_city: 'Cidade Exemplo',
        cnpj: '',
        trade_name: '',
      }),
    ).toEqual({ isValid: true, errors: {}, missingFields: [] });
  });

  it('rejeita nome acima do limite de 100 caracteres', () => {
    const result = validateCompany({
      name: 'A'.repeat(101),
      industry: 'servicos',
      address_city: 'Cidade Exemplo',
    });

    expect(result.isValid).toBe(false);
    expect(result.errors).toEqual({ name: 'Nome muito longo' });
    expect(result.missingFields).toEqual(['Nome']);
  });

  it('expõe os rótulos usados pela tela para os campos obrigatórios', () => {
    expect(companyRequiredFields).toEqual({
      name: 'Nome',
      industry: 'Mercado',
      address_city: 'Cidade',
    });
  });
});
