/**
 * i18next setup — docs/10_Frontend_Architecture.md §5. Namespace-per-feature JSON,
 * en/ar mirrored 1:1 (a mismatch fails CI/tests, AI_RULES.md #10). `dir` is toggled at
 * the document root, driving the logical-property RTL layout
 * (docs/03_UI_UX_Guidelines.md §5).
 */
import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';

import enCommon from './en/common.json';
import enAuth from './en/auth.json';
import enDashboard from './en/dashboard.json';
import enVault from './en/vault.json';
import arCommon from './ar/common.json';
import arAuth from './ar/auth.json';
import arDashboard from './ar/dashboard.json';
import arVault from './ar/vault.json';

export const resources = {
  en: { common: enCommon, auth: enAuth, dashboard: enDashboard, vault: enVault },
  ar: { common: arCommon, auth: arAuth, dashboard: arDashboard, vault: arVault },
} as const;

export type Locale = 'en' | 'ar';

export function applyDocumentDirection(locale: Locale): void {
  document.documentElement.lang = locale;
  document.documentElement.dir = locale === 'ar' ? 'rtl' : 'ltr';
}

void i18n.use(initReactI18next).init({
  resources,
  lng: 'en',
  fallbackLng: 'en',
  defaultNS: 'common',
  interpolation: { escapeValue: false }, // React already escapes
});

i18n.on('languageChanged', (lng) => applyDocumentDirection(lng as Locale));

export default i18n;
