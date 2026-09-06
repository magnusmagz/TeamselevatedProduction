import React, { useState } from 'react';
import {
  COACH_ACCESS_ENDPOINT,
  COACH_ACCESS_LABEL,
  coachAccessAction,
} from '../utils/coachAccess';

/**
 * One control, four behaviours — the club admin should not have to know which
 * state a coach is in. Same shape as the Crew page's button:
 *
 *   not_invited          → Invite            (mint + mail a 7-day invite)
 *   invited / expired    → Resend invite     (re-mint; the old link stops working)
 *   active / never used  → Send login link   (24h magic link)
 *   no_email             → nothing, and the reason
 *
 * Plus "Set password", which needs no email and so is offered on every row.
 * Rendered by BOTH tables in CoachManagement — it lives here so they cannot
 * drift apart the way the hard-coded "Active" status did.
 */

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

export interface CoachAccessRow {
  id: number;
  first_name: string;
  last_name: string;
  email?: string | null;
  status?: string;
}

interface Props {
  coach: CoachAccessRow;
  clubId: number | null;
  onChanged: () => void;
  onSetPassword: (coach: CoachAccessRow) => void;
}

const CoachAccessControl: React.FC<Props> = ({ coach, clubId, onChanged, onSetPassword }) => {
  const [busy, setBusy] = useState(false);
  const action = coachAccessAction(coach.status);

  const run = async () => {
    if (!action) return;
    if (clubId == null) {
      alert('Select a club first.');
      return;
    }
    setBusy(true);
    try {
      const token = localStorage.getItem('auth_token');
      const res = await fetch(
        `${API_URL}/api/coach-access.php?action=${COACH_ACCESS_ENDPOINT[action]}`,
        {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
          body: JSON.stringify({ user_id: coach.id, club_id: clubId }),
        }
      );
      const data = await res.json().catch(() => ({}));
      if (res.ok && data.success) {
        // State the expiry the backend actually used — telling someone "sent!"
        // without it is how the 2026-08-03 invite ticket happened.
        const what = action === 'login_link' ? 'Sign-in link' : 'Invite';
        alert(`${what} sent to ${data.email}. It is valid for ${data.expires_in}.`);
        onChanged();
      } else if (data.reason === 'already_active') {
        alert('They already have a password — use Send login link instead.');
        onChanged();
      } else if (data.reason === 'not_active') {
        alert('They have not set a password yet — send them an invite instead.');
        onChanged();
      } else {
        alert(data.error || 'Could not send the email.');
      }
    } catch {
      alert('Could not send the email.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <span className="inline-flex items-center gap-3 whitespace-nowrap">
      {action ? (
        <button
          type="button"
          onClick={run}
          disabled={busy}
          className={
            action === 'invite'
              ? 'bg-brand-primary text-white rounded-md px-3 py-1.5 text-xs font-bold uppercase hover:bg-brand-primary-hover disabled:opacity-50'
              : 'text-brand-primary hover:underline text-xs font-semibold uppercase disabled:opacity-50'
          }
        >
          {busy ? 'Sending…' : COACH_ACCESS_LABEL[action]}
        </button>
      ) : (
        <span className="text-xs text-gray-500" title="Add an email address to invite or send a link.">
          No email on file
        </span>
      )}
      <button
        type="button"
        onClick={() => onSetPassword(coach)}
        className="text-brand-primary hover:underline text-xs uppercase"
      >
        Set password
      </button>
    </span>
  );
};

export default CoachAccessControl;
