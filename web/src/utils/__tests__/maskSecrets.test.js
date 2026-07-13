import { describe, it, expect } from 'vitest';
import { maskSecrets, maskSecretsDeep } from '../maskSecrets.js';

describe('maskSecrets', () => {
  it('passes through safe text unchanged', () => {
    const text = 'Our product helps finance teams automate reporting.';
    expect(maskSecrets(text)).toBe(text);
  });

  it('masks OpenAI API key', () => {
    const key  = 'sk-' + 'A'.repeat(48);
    const result = maskSecrets(`Use key: ${key}`);
    expect(result).not.toContain(key);
    expect(result).toContain('[OPENAI_KEY]');
  });

  it('masks AWS access key', () => {
    const result = maskSecrets('Key: AKIAIOSFODNN7EXAMPLE');
    expect(result).toContain('[AWS_KEY]');
  });

  it('masks Bearer token', () => {
    const result = maskSecrets('Authorization: Bearer abc123.def456.ghi789');
    expect(result).toContain('[TOKEN]');
  });

  it('masks private key block', () => {
    const result = maskSecrets('-----BEGIN RSA PRIVATE KEY-----\nMIIEpAIBAAK\n-----END RSA PRIVATE KEY-----');
    expect(result).toContain('[PRIVATE_KEY]');
  });

  it('handles non-string input', () => {
    expect(maskSecrets(42)).toBe(42);
    expect(maskSecrets(null)).toBe(null);
  });
});

describe('maskSecretsDeep', () => {
  it('masks nested string values', () => {
    const obj = {
      name: 'test',
      auth: { token: 'Bearer abc123.def456.ghi789extra' },
      count: 5,
    };
    const result = maskSecretsDeep(obj);
    expect(result.auth.token).toContain('[TOKEN]');
    expect(result.name).toBe('test');
    expect(result.count).toBe(5);
  });

  it('handles arrays', () => {
    const arr = ['safe', 'sk-' + 'B'.repeat(48)];
    const result = maskSecretsDeep(arr);
    expect(result[0]).toBe('safe');
    expect(result[1]).toContain('[OPENAI_KEY]');
  });

  it('passes through non-string/non-object values unchanged', () => {
    expect(maskSecretsDeep(42)).toBe(42);
    expect(maskSecretsDeep(true)).toBe(true);
  });
});
