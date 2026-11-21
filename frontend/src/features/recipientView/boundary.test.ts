/**
 * The renderer's import boundary — docs/19_Decision_Log.md Decision #30,
 * docs/10_Frontend_Architecture.md §7b.
 *
 * The Phase 3 recipient access page must be able to mount RecipientContentView in a
 * minimal route tree, with no vault key, no API client, no router and no query client —
 * and the renderer must never be able to decrypt or fetch. So every import in this folder
 * must come from this allow-list. Widening it is a design change: update Decision #30.
 */
import { describe, expect, it } from 'vitest';

const ALLOWED = new Set([
  'react',
  'react-i18next',
  'react-dom/client', // tests only
  'vitest', // tests only
  '../../i18n', // tests only
  '../../components/Alert',
  '../../components/Button',
  '../vault/sanitizeHtml',
  '../vault/bidi',
  '../vault/formatBytes',
]);

/** This folder's sources, as text (Vite raw imports — no Node APIs needed). */
const renderer = import.meta.glob<string>('./*.{ts,tsx}', { query: '?raw', import: 'default', eager: true });
/** Every non-test source in the app, for the raw-HTML check. */
const app = import.meta.glob<string>(['../../**/*.{ts,tsx}', '!../../**/*.test.{ts,tsx}'], {
  query: '?raw',
  import: 'default',
  eager: true,
});

function importsOf(source: string): string[] {
  const statics = [...source.matchAll(/^\s*import\s+(?:[^'"]*?\sfrom\s+)?['"]([^'"]+)['"]/gm)];
  const dynamics = [...source.matchAll(/import\(\s*['"]([^'"]+)['"]\s*\)/g)];
  return [...statics, ...dynamics].map((match) => match[1]);
}

describe('recipientView import boundary', () => {
  it('finds the renderer files', () => {
    expect(Object.keys(renderer)).toEqual(expect.arrayContaining(['./RecipientContentView.tsx', './SanitizedHtml.tsx', './types.ts']));
  });

  it.each(Object.entries(renderer))('%s imports only from the allow-list', (_name: string, source: string) => {
    const outside = importsOf(source).filter((specifier) => !specifier.startsWith('./') && !ALLOWED.has(specifier));
    expect(outside).toEqual([]);
  });

  it('SanitizedHtml is the only place that renders raw HTML', () => {
    const offenders = Object.entries(app)
      // Vite keys files of this folder as "./…", others as "../../…".
      .filter(([path, source]) => source.includes('dangerouslySetInnerHTML') && path !== './SanitizedHtml.tsx')
      .map(([path]) => path);

    expect(Object.keys(app).length).toBeGreaterThan(50);
    expect(offenders).toEqual([]);
  });
});
