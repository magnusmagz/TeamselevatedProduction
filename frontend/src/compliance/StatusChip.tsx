import React from 'react';
import { formatDateOnly } from '../utils/dateFormat';
import type { ComplianceRow, CredentialStatus } from './types';

/**
 * One status chip, used by the admin roll call and the coach's own page (G4).
 *
 * ⚠️ An unknown status renders as "Unknown", never as a blank chip. Blank reads
 * as "fine", which is the wrong default for a compliance column — the same rule
 * as the Consent column on the athlete list.
 *
 * ⚠️ `expiring` is not a stored status. It is `verified` with 30 days or fewer
 * left, and the distinction is the whole point of the feature: a certificate
 * that is valid today and gone next month has to look different from one that
 * is valid for a year, or nobody renews anything.
 */

export type ChipTone = 'good' | 'warn' | 'bad' | 'neutral' | 'info';

interface ChipShape {
  label: string;
  tone: ChipTone;
}

const TONES: Record<ChipTone, string> = {
  good: 'bg-green-100 text-green-800 border-green-200',
  warn: 'bg-amber-100 text-amber-900 border-amber-300',
  bad: 'bg-red-100 text-red-800 border-red-200',
  info: 'bg-blue-100 text-blue-800 border-blue-200',
  neutral: 'bg-gray-100 text-gray-700 border-gray-200',
};

/** The chip a row deserves, expiry included. */
export function chipFor(row: Pick<ComplianceRow, 'status' | 'days_to_expiry'>): ChipShape {
  const days = row.days_to_expiry;

  switch (row.status as CredentialStatus) {
    case 'verified':
      if (days !== null && days <= 30) {
        return {
          label: days <= 0 ? 'Expires today' : `Expires in ${days} day${days === 1 ? '' : 's'}`,
          tone: 'warn',
        };
      }
      return { label: 'Verified', tone: 'good' };
    case 'submitted':
      return { label: 'Under review', tone: 'info' };
    case 'rejected':
      return { label: 'Not accepted', tone: 'bad' };
    case 'expired':
      return { label: 'Expired', tone: 'bad' };
    case 'missing':
      return { label: 'Not on file', tone: 'neutral' };
    default:
      // Never a blank chip: blank reads as "fine".
      return { label: 'Unknown', tone: 'neutral' };
  }
}

interface Props {
  row: Pick<ComplianceRow, 'status' | 'days_to_expiry'>;
  className?: string;
}

const StatusChip: React.FC<Props> = ({ row, className }) => {
  const chip = chipFor(row);
  return (
    <span
      className={`inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold ${TONES[chip.tone]} ${className || ''}`}
    >
      {chip.label}
    </span>
  );
};

/** "Expires 1 Oct 2026" — through formatDateOnly, so no timezone can shift it. */
export function expiryLine(row: Pick<ComplianceRow, 'expires_at'>): string {
  if (!row.expires_at) {
    return 'No expiry';
  }
  return `Expires ${formatDateOnly(row.expires_at)}`;
}

export default StatusChip;
