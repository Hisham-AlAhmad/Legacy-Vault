import type { ButtonHTMLAttributes } from 'react';
import './Button.css';

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: 'primary' | 'secondary' | 'ghost';
  busy?: boolean;
}

/** Primary action button — docs/03_UI_UX_Guidelines.md §4. */
export function Button({ variant = 'primary', busy = false, disabled, children, ...rest }: ButtonProps) {
  return (
    <button
      type={rest.type ?? 'button'}
      className={`btn btn--${variant}`}
      disabled={disabled || busy}
      aria-busy={busy || undefined}
      {...rest}
    >
      {children}
    </button>
  );
}
