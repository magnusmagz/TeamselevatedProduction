import React from 'react';
import { Link } from 'react-router-dom';

/**
 * The ONE page-header treatment for the staff app.
 *
 * Chosen 2026-09-06 from the most common shape already in the codebase
 * (18 of ~90 h1s: `text-2xl font-bold text-brand-primary uppercase
 * tracking-wide`, subtitle `mt-1 text-sm text-gray-500`, primary action
 * right-aligned on the same row, stacking under the title on mobile).
 * See docs/ui-consistency-inventory-2026-09.md for the tally and the
 * alternatives that lost.
 *
 * `uiConsistency.test.ts` fails if a staff page renders a raw `<h1` outside
 * this component — add to that test's allowlist with a reason rather than
 * hand-rolling a header.
 */
export interface PageHeaderProps {
  title: React.ReactNode;
  subtitle?: React.ReactNode;
  /** Route for a "back" link rendered above the title. */
  backTo?: string;
  /** Label for the back link; defaults to "Back". */
  backLabel?: string;
  /**
   * Right-aligned controls. Convention: the PRIMARY action is the first
   * child; secondary buttons follow it.
   */
  actions?: React.ReactNode;
  /** Chips / counts / status badges rendered under the subtitle. */
  meta?: React.ReactNode;
  className?: string;
}

const PageHeader: React.FC<PageHeaderProps> = ({
  title,
  subtitle,
  backTo,
  backLabel = 'Back',
  actions,
  meta,
  className = '',
}) => {
  return (
    <header className={`mb-6 ${className}`.trim()} data-testid="page-header">
      {backTo && (
        <Link
          to={backTo}
          className="inline-flex items-center text-sm text-brand-primary hover:underline mb-2"
        >
          <span aria-hidden="true" className="mr-1">&larr;</span>
          {backLabel}
        </Link>
      )}
      <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
        <div className="min-w-0">
          <h1 className="text-2xl font-bold text-brand-primary uppercase tracking-wide">
            {title}
          </h1>
          {subtitle && <p className="mt-1 text-sm text-gray-500">{subtitle}</p>}
          {meta && <div className="mt-2 flex flex-wrap items-center gap-2">{meta}</div>}
        </div>
        {actions && (
          <div
            className="flex flex-wrap items-center gap-2 sm:justify-end sm:flex-shrink-0"
            data-testid="page-header-actions"
          >
            {actions}
          </div>
        )}
      </div>
    </header>
  );
};

export default PageHeader;
