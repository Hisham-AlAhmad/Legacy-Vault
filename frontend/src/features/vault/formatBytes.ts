/**
 * Human-readable sizes in either locale, with Western digits in both
 * (docs/03_UI_UX_Guidelines.md §5). Binary multiples, so the 10,485,760-byte quota reads
 * as "10 MB".
 */
export function formatBytes(bytes: number, language: string): string {
  const locale = language === 'ar' ? 'ar-u-nu-latn' : 'en';
  const [value, unit] =
    bytes >= 1024 * 1024 ? [bytes / (1024 * 1024), 'megabyte'] : bytes >= 1024 ? [bytes / 1024, 'kilobyte'] : [bytes, 'byte'];

  return new Intl.NumberFormat(locale, {
    style: 'unit',
    unit,
    // "0 bytes", not "0 byte"; kB/MB stay short.
    unitDisplay: unit === 'byte' ? 'long' : 'short',
    maximumFractionDigits: value < 10 && unit !== 'byte' ? 1 : 0,
  }).format(value);
}

/** Dates in either locale, Western digits. */
export function formatDate(iso: string, language: string, withTime = true): string {
  return new Intl.DateTimeFormat(language === 'ar' ? 'ar-u-nu-latn' : 'en', {
    dateStyle: 'medium',
    ...(withTime ? { timeStyle: 'short' } : {}),
  }).format(new Date(iso));
}
