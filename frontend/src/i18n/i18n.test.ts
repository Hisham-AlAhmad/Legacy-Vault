/**
 * Locale parity check — a key existing in one locale and not the other fails the build
 * (AI_RULES.md #10, docs/15_Testing.md §6).
 */
import { describe, expect, it } from 'vitest';
import enCommon from './en/common.json';
import enAuth from './en/auth.json';
import enDashboard from './en/dashboard.json';
import enVault from './en/vault.json';
import arCommon from './ar/common.json';
import arAuth from './ar/auth.json';
import arDashboard from './ar/dashboard.json';
import arVault from './ar/vault.json';

function flattenKeys(obj: Record<string, unknown>, prefix = ''): string[] {
  return Object.entries(obj).flatMap(([key, value]) => {
    const path = prefix ? `${prefix}.${key}` : key;
    return value !== null && typeof value === 'object'
      ? flattenKeys(value as Record<string, unknown>, path)
      : [path];
  });
}

describe('i18n locale parity (en ⇄ ar)', () => {
  const namespaces: Array<[string, Record<string, unknown>, Record<string, unknown>]> = [
    ['common', enCommon, arCommon],
    ['auth', enAuth, arAuth],
    ['dashboard', enDashboard, arDashboard],
    ['vault', enVault, arVault],
  ];

  it.each(namespaces)('namespace "%s" has identical key sets', (_name, en, ar) => {
    expect(flattenKeys(ar).sort()).toEqual(flattenKeys(en).sort());
  });
});
