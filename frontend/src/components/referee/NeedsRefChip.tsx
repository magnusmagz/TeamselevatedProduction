import React from 'react';

/**
 * "Needs ref" — an upcoming game with no center referee (legacy/events-gateway.php
 * sets `referee_status: 'needs_ref'` from one subselect over game_referees).
 * Warn tone, the same amber the referee-feedback notice uses; no new colour.
 * Rendered on staff game surfaces only — the caller gates on standing.
 */
export const NEEDS_REF_CHIP_CLASS =
  'inline-flex items-center gap-1 rounded-full border border-amber-300 bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-900';

interface Props {
  status?: string | null;
  className?: string;
}

const NeedsRefChip: React.FC<Props> = ({ status, className = '' }) => {
  if (status !== 'needs_ref') return null;
  return (
    <span className={`${NEEDS_REF_CHIP_CLASS} ${className}`} title="No center referee assigned yet" data-testid="needs-ref-chip">
      Needs ref
    </span>
  );
};

export default NeedsRefChip;
