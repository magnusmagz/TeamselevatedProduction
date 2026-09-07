import React from 'react';
import { Link, LinkProps } from 'react-router-dom';

/**
 * The ONE button treatment for the staff app.
 *
 * Chosen 2026-09-06 from the most common recipes already in the codebase
 * (577 distinct class strings across 857 buttons — tally and the choice in
 * docs/ui-consistency-inventory-2026-09.md, "Buttons"), then brought onto
 * the brand tokens so it matches PageHeader and DataTable: brand-primary
 * fill for the primary action, brand-secondary border for the secondary,
 * brand-light hover for the quiet ones, brand-accent focus ring everywhere.
 *
 * `type` defaults to "button" so a Button inside a form never submits it by
 * accident — pass `type="submit"` where the form relies on it.
 *
 * `uiConsistency.test.ts` fails if a staff page renders a raw `<button` with
 * a className outside this component — add to that test's allowlist with a
 * reason rather than hand-rolling a button.
 */

/** `danger-link` is the red text action in a table row (Delete / Remove) — link weight, destructive colour. */
export type ButtonVariant = 'primary' | 'secondary' | 'danger' | 'ghost' | 'link' | 'danger-link';
/** `icon` is a square ghost/secondary control for a lone glyph (close ×, chevron, kebab). */
export type ButtonSize = 'sm' | 'md' | 'icon';

export const BUTTON_CLASSES = {
  base:
    'inline-flex items-center justify-center gap-2 rounded-md font-semibold uppercase tracking-wide ' +
    'transition-colors whitespace-nowrap select-none ' +
    'focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-accent focus-visible:ring-offset-2 ' +
    'disabled:opacity-50 disabled:cursor-not-allowed',
  variant: {
    primary: 'border border-transparent bg-brand-primary text-white hover:bg-brand-primary-hover',
    secondary: 'border border-brand-secondary bg-white text-brand-primary hover:bg-brand-light/40',
    // The red the app already used for destructive actions (bg-red-600 / hover:bg-red-700).
    danger: 'border border-transparent bg-red-600 text-white hover:bg-red-700',
    ghost: 'border border-transparent bg-transparent text-brand-primary hover:bg-brand-light/40',
    link: 'border-0 bg-transparent text-brand-primary hover:underline',
    'danger-link': 'border-0 bg-transparent text-red-600 hover:text-red-700 hover:underline',
  } as Record<ButtonVariant, string>,
  size: {
    sm: 'px-3 py-1.5 text-xs',
    md: 'px-6 py-2 text-sm',
    icon: 'p-2 leading-none',
  } as Record<ButtonSize, string>,
  /** `link` carries no box: no padding at any size. */
  linkSize: {
    sm: 'p-0 text-xs',
    md: 'p-0 text-sm',
    icon: 'p-0 leading-none',
  } as Record<ButtonSize, string>,
  fullWidth: 'w-full',
};

export function buttonClassName(opts: {
  variant?: ButtonVariant;
  size?: ButtonSize;
  fullWidth?: boolean;
  className?: string;
}): string {
  const variant = opts.variant ?? 'primary';
  const size = opts.size ?? 'md';
  const sizeClass =
    variant === 'link' || variant === 'danger-link' ? BUTTON_CLASSES.linkSize[size] : BUTTON_CLASSES.size[size];
  return [
    BUTTON_CLASSES.base,
    BUTTON_CLASSES.variant[variant],
    sizeClass,
    opts.fullWidth ? BUTTON_CLASSES.fullWidth : '',
    opts.className ?? '',
  ]
    .filter(Boolean)
    .join(' ');
}

const Spinner: React.FC = () => (
  <svg
    className="animate-spin h-4 w-4"
    viewBox="0 0 24 24"
    fill="none"
    aria-hidden="true"
    data-testid="button-spinner"
  >
    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
  </svg>
);

export interface ButtonOwnProps {
  variant?: ButtonVariant;
  size?: ButtonSize;
  /** Shows a spinner and disables the button; the label stays in the DOM so the width does not jump. */
  loading?: boolean;
  fullWidth?: boolean;
  leadingIcon?: React.ReactNode;
  trailingIcon?: React.ReactNode;
}

export type ButtonProps = ButtonOwnProps & React.ButtonHTMLAttributes<HTMLButtonElement>;

const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  {
    variant = 'primary',
    size = 'md',
    loading = false,
    fullWidth = false,
    leadingIcon,
    trailingIcon,
    type = 'button',
    disabled,
    className,
    children,
    ...rest
  },
  ref
) {
  return (
    <button
      ref={ref}
      type={type}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      className={buttonClassName({ variant, size, fullWidth, className: `${loading ? 'relative' : ''} ${className ?? ''}`.trim() })}
      {...rest}
    >
      {loading ? (
        <>
          <span className="absolute inset-0 flex items-center justify-center" aria-hidden="true">
            <Spinner />
          </span>
          <span className="inline-flex items-center justify-center gap-2 invisible">
            {leadingIcon && <span className="inline-flex shrink-0" aria-hidden="true">{leadingIcon}</span>}
            {children}
            {trailingIcon && <span className="inline-flex shrink-0" aria-hidden="true">{trailingIcon}</span>}
          </span>
        </>
      ) : (
        // No wrapper when nothing needs one: the label is the button's own text node.
        <>
          {leadingIcon && <span className="inline-flex shrink-0" aria-hidden="true">{leadingIcon}</span>}
          {children}
          {trailingIcon && <span className="inline-flex shrink-0" aria-hidden="true">{trailingIcon}</span>}
        </>
      )}
    </button>
  );
});

export interface LinkButtonProps
  extends ButtonOwnProps,
    Omit<LinkProps, 'className' | 'to'> {
  /** Router destination. */
  to?: LinkProps['to'];
  /** Plain anchor destination (external URL, mailto:, download). Wins over `to`. */
  href?: string;
  className?: string;
}

/**
 * Navigation that looks like a button. Renders a react-router `Link`
 * (or a plain `<a>` when `href` is given) with the same classes as Button.
 */
export const LinkButton = React.forwardRef<HTMLAnchorElement, LinkButtonProps>(function LinkButton(
  { variant = 'primary', size = 'md', fullWidth = false, leadingIcon, trailingIcon, to, href, className, children, loading: _loading, ...rest },
  ref
) {
  const cls = buttonClassName({ variant, size, fullWidth, className });
  const inner = (
    <>
      {leadingIcon && <span className="inline-flex shrink-0" aria-hidden="true">{leadingIcon}</span>}
      {children}
      {trailingIcon && <span className="inline-flex shrink-0" aria-hidden="true">{trailingIcon}</span>}
    </>
  );
  if (href !== undefined) {
    const { replace: _r, state: _s, reloadDocument: _rd, preventScrollReset: _p, relative: _rel, ...anchor } = rest as LinkProps;
    return (
      <a ref={ref} href={href} className={cls} {...(anchor as React.AnchorHTMLAttributes<HTMLAnchorElement>)}>
        {inner}
      </a>
    );
  }
  return (
    <Link ref={ref} to={to ?? '#'} className={cls} {...rest}>
      {inner}
    </Link>
  );
});

export default Button;
