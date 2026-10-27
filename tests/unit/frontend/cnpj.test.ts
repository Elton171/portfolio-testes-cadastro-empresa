import { describe, expect, it } from 'vitest';

import { formatCNPJ, isValidCNPJ, normalizeCNPJ } from '@/lib/cnpj';

const CNPJ_VALIDO = '11.222.333/0001-81';

describe('helpers de CNPJ', () => {
  describe('normalizeCNPJ', () => {
    it('remove máscara e espaços, mantendo apenas os dígitos', () => {
      expect(normalizeCNPJ(' 11.222.333/0001-81 ')).toBe('11222333000181');
    });

    it('retorna uma string vazia quando a entrada está vazia', () => {
      expect(normalizeCNPJ()).toBe('');
    });
  });

  describe('formatCNPJ', () => {
    it('aplica a máscara completa a um CNPJ sem pontuação', () => {
      expect(formatCNPJ('11222333000181')).toBe(CNPJ_VALIDO);
    });

    it('aplica a máscara progressivamente durante a digitação', () => {
      expect(formatCNPJ('11222')).toBe('11.222');
    });

    it('limita a entrada aos 14 dígitos do CNPJ', () => {
      expect(formatCNPJ('1122233300018199')).toBe(CNPJ_VALIDO);
    });
  });

  describe('isValidCNPJ', () => {
    it('aceita um CNPJ válido com máscara', () => {
      expect(isValidCNPJ(CNPJ_VALIDO)).toBe(true);
    });

    it('aceita o mesmo CNPJ sem máscara', () => {
      expect(isValidCNPJ('11222333000181')).toBe(true);
    });

    it('rejeita dígito verificador incorreto', () => {
      expect(isValidCNPJ('11.222.333/0001-80')).toBe(false);
    });

    it('rejeita uma sequência repetida', () => {
      expect(isValidCNPJ('11111111111111')).toBe(false);
    });

    it('rejeita uma entrada com menos de 14 dígitos', () => {
      expect(isValidCNPJ('1122233300018')).toBe(false);
    });
  });
});
