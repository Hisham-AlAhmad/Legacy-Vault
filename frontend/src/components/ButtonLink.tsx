import { Link, type LinkProps } from 'react-router-dom';
import './Button.css';

interface ButtonLinkProps extends LinkProps {
  variant?: 'primary' | 'secondary' | 'ghost';
}

/** A navigation link that looks like a Button — still a real link for keyboard and assistive tech. */
export function ButtonLink({ variant = 'primary', className, children, ...rest }: ButtonLinkProps) {
  return (
    <Link className={`btn btn--${variant}${className ? ` ${className}` : ''}`} {...rest}>
      {children}
    </Link>
  );
}
