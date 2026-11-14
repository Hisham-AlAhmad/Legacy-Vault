/**
 * Wrap user-provided text interpolated into a translated sentence in Unicode isolates
 * (FSI … PDI), so an English name inside an Arabic sentence — or the reverse — can't
 * reorder the surrounding text (docs/03_UI_UX_Guidelines.md §5, the most commonly broken
 * RTL case). Equivalent to <bdi> for plain strings.
 */
export function isolate(text: string): string {
  return `⁨${text}⁩`;
}
