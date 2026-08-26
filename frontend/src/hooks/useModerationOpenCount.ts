import { useCallback, useEffect, useState } from 'react';

const API_URL = process.env.REACT_APP_API_URL || '';

/** How often the badge re-checks. Slow on purpose — see the note below. */
const POLL_MS = 5 * 60 * 1000;

interface OpenCount {
  openTotal: number;
  openHigh: number;
}

/**
 * Count of chat reports still waiting for review, for the nav badge.
 *
 * Auto-flagging has fired on every message since moderation shipped on
 * 2026-07-30, and ChatModeration.tsx is pull-only — so a flag sat unseen until
 * an admin happened to open the page. This badge and the email alerts in
 * lib/chat_moderation_alerts.php close that from both ends.
 *
 * Deliberate choices:
 *
 * - **Uses `open-count`, not `summary`.** Summary runs three queries over 90
 *   days to build the oversight report; this is one indexed count, because it
 *   is polled by every admin's navigation rather than opened on request.
 * - **Five minutes, not seconds.** A review queue is not a live feed. Anything
 *   faster is load with no decision attached to it.
 * - **A failure renders nothing, never a zero.** Zero reads as "all clear",
 *   which is the wrong thing to show a compliance surface when the truth is
 *   "we could not ask". Same rule as the Unknown state on the consent column.
 * - **Admins only.** The caller passes `enabled`; the endpoint enforces it
 *   server-side regardless, since a client flag is not an access control.
 */
export function useModerationOpenCount(enabled: boolean, clubId?: number | null): OpenCount | null {
  const [count, setCount] = useState<OpenCount | null>(null);

  const load = useCallback(async () => {
    if (!enabled) {
      setCount(null);
      return;
    }

    try {
      const params = new URLSearchParams({ action: 'open-count' });
      if (clubId) params.set('club_id', String(clubId));

      const token = localStorage.getItem('auth_token');
      const res = await fetch(`${API_URL}/api/chat-moderation.php?${params}`, {
        headers: token
          ? { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }
          : { 'Content-Type': 'application/json' },
      });

      const data = await res.json();

      if (res.ok && data.success) {
        setCount({ openTotal: data.open_total || 0, openHigh: data.open_high || 0 });
      } else {
        // Includes the 403 a non-admin gets. Not an error state worth showing —
        // the badge simply does not apply to them.
        setCount(null);
      }
    } catch {
      setCount(null);
    }
  }, [enabled, clubId]);

  useEffect(() => {
    load();
    if (!enabled) return;

    const timer = setInterval(load, POLL_MS);
    return () => clearInterval(timer);
  }, [load, enabled]);

  return count;
}
