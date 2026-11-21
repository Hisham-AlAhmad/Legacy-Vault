import { useMemo } from 'react';
import { sanitizeHtml } from '../vault/sanitizeHtml';
import './SanitizedHtml.css';

interface SanitizedHtmlProps {
  /** Decrypted message HTML. Sanitized here, every time, whatever its source claims. */
  html: string;
  className?: string;
}

/**
 * The one place in the app that renders stored HTML (docs/19_Decision_Log.md Decisions
 * #16, #30). It runs the allow-list sanitizer itself, at render time, so nothing reaches
 * `dangerouslySetInnerHTML` without passing through it — not even HTML a content source
 * says it has already sanitized. The sanitizer is idempotent, so the second pass costs
 * nothing but time. Block elements carry `dir="auto"` from the sanitizer, so each
 * paragraph takes its own direction (docs/03_UI_UX_Guidelines.md §5).
 */
export function SanitizedHtml({ html, className = '' }: SanitizedHtmlProps) {
  const clean = useMemo(() => sanitizeHtml(html), [html]);

  return <div className={`sanitized-html ${className}`.trim()} dangerouslySetInnerHTML={{ __html: clean }} />;
}
