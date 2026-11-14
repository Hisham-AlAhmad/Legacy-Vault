import type { HTMLAttributes } from 'react';
import './Card.css';

/** Surface card — docs/03_UI_UX_Guidelines.md §4. */
export function Card({ children, className, ...rest }: HTMLAttributes<HTMLDivElement>) {
  return (
    <div className={`card${className ? ` ${className}` : ''}`} {...rest}>
      {children}
    </div>
  );
}
