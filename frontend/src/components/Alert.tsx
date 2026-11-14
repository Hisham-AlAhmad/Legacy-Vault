import type { ReactNode } from 'react';
import './Alert.css';

interface AlertProps {
  tone: 'error' | 'success' | 'warning' | 'info';
  children: ReactNode;
}

/**
 * Status message. Color is never the only signal — tone is also conveyed by an icon glyph
 * and the text itself (docs/03_UI_UX_Guidelines.md §6).
 */
export function Alert({ tone, children }: AlertProps) {
  const glyph = tone === 'error' ? '✕' : tone === 'success' ? '✓' : tone === 'warning' ? '!' : 'ℹ';

  return (
    <div className={`alert alert--${tone}`} role={tone === 'error' ? 'alert' : 'status'}>
      <span className="alert__glyph" aria-hidden="true">
        {glyph}
      </span>
      <div className="alert__body">{children}</div>
    </div>
  );
}
