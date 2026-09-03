import React from 'react';
import { Link } from 'react-router-dom';
import { fetchMyRequirements } from './api';
import type { ComplianceRow } from './types';

/**
 * "2 requirements need attention" — on the staff dashboard and the parent
 * dashboard (GOTR G4).
 *
 * ⚠️ **HIDDEN WHEN THERE IS NOTHING TO DO, and hidden when the read fails.**
 * A card that says "0 requirements need attention" trains everyone to ignore
 * the space it sits in, and a card that says "could not load" on a dashboard
 * the whole club opens every morning is noise, not information. The screens
 * that must never render a false negative are the admin roll call and the
 * person's own page; this is a nudge toward them.
 *
 * It is deliberately the ONLY place a person who has never had a credential
 * recorded is told they owe something — the reminder sweep does not mail about
 * a requirement with no stored row (see lib/compliance_reminders.php), because
 * doing so would be 150,000 emails on its first tick.
 *
 * ⚠️ It lives OUTSIDE parent-portal/ on purpose. ParentPortalChildScopeTest
 * scans that directory, and this component is about the signed-in adult's own
 * paperwork — it has nothing to do with which children they can see, and must
 * not grow anything that does.
 */

/** What counts as needing attention. `submitted` is with a reviewer — not the person's move. */
export function needsAttention(row: ComplianceRow): boolean {
  if (row.status === 'missing' || row.status === 'expired' || row.status === 'rejected') {
    return true;
  }
  return row.status === 'verified' && row.days_to_expiry !== null && row.days_to_expiry <= 30;
}

interface Props {
  /** Where the "see them" link goes. The portal keeps people inside the portal. */
  to?: string;
  className?: string;
}

const ComplianceAlertCard: React.FC<Props> = ({ to = '/compliance/mine', className }) => {
  const [count, setCount] = React.useState(0);

  React.useEffect(() => {
    let cancelled = false;

    fetchMyRequirements()
      .then((body) => {
        if (cancelled) return;
        const rows = (body.clubs || []).flatMap((club) => club.requirements || []);
        setCount(rows.filter(needsAttention).length);
      })
      .catch(() => {
        // Silent. The feature may be switched off, the migration may not be
        // applied, or this person may hold no staff role at all — none of which
        // is worth an error box on a dashboard.
        if (!cancelled) setCount(0);
      });

    return () => {
      cancelled = true;
    };
  }, []);

  if (count < 1) {
    return null;
  }

  return (
    <Link
      to={to}
      className={`flex items-center gap-3 p-4 bg-amber-50 border border-amber-200 rounded-lg hover:bg-amber-100 transition-colors ${className || ''}`}
    >
      <span className="flex-shrink-0 w-10 h-10 bg-amber-500 rounded-full flex items-center justify-center">
        <svg className="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeWidth={2}
            d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.74 4a2 2 0 00-3.48 0L3.16 16.25A2 2 0 005 19z"
          />
        </svg>
      </span>
      <span className="flex-1">
        <span className="block font-semibold text-amber-800">
          {count} requirement{count === 1 ? '' : 's'} need
          {count === 1 ? 's' : ''} attention
        </span>
        <span className="block text-sm text-amber-700">
          Expiring, expired or not yet on file
        </span>
      </span>
      <svg className="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
      </svg>
    </Link>
  );
};

export default ComplianceAlertCard;
