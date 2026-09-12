import { useEffect } from 'react';

const API_URL = process.env.REACT_APP_API_URL || '';

export type UsageSurface = 'staff' | 'parent' | 'referee';

/** The user's own calendar day — the honest DAU day. Never toISOString(): that is the UTC day. */
export function localDateString(now: Date = new Date()): string {
  const y = now.getFullYear();
  const m = String(now.getMonth() + 1).padStart(2, '0');
  const d = String(now.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

export function surfaceForPath(pathname: string): UsageSurface {
  if (pathname === '/referee' || pathname.startsWith('/referee/')) return 'referee';
  if (pathname.startsWith('/parent')) return 'parent';
  return 'staff';
}

const KEY_PREFIX = 'te_usage_ping:';

/**
 * "I am here today." One POST per signed-in user per local day to
 * api/usage-ping.php, which records the user's daily-active rows per club and
 * role (lib/usage_activity.php). Called from AppContent so the staff app, the
 * parent portal and the referee page are one call.
 *
 * - Dedupes in localStorage on (user id, local date); a second tab or a route
 *   change on the same day sends nothing. The server is idempotent anyway.
 * - localStorage can throw (private window, blocked site data); every access is
 *   wrapped, and a failure means we ping — a duplicate costs one upsert, a
 *   missed ping costs a data point.
 * - Never surfaces an error. A metrics write must not break the page.
 */
export function useUsagePing(userId: number | string | null | undefined, pathname: string): void {
  const surface = surfaceForPath(pathname);

  useEffect(() => {
    if (userId === null || userId === undefined || userId === '') return;
    const today = localDateString();
    const key = `${KEY_PREFIX}${userId}`;
    try {
      if (localStorage.getItem(key) === today) return;
    } catch {
      // fall through and ping
    }

    let token: string | null = null;
    try {
      token = localStorage.getItem('auth_token');
    } catch {
      token = null;
    }
    if (!token) return;

    fetch(`${API_URL}/api/usage-ping.php`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({ local_date: today, surface }),
    })
      .then((res) => {
        if (res.ok) {
          try {
            localStorage.setItem(key, today);
          } catch {
            // nothing to do; tomorrow's ping is still correct
          }
        }
      })
      .catch(() => {
        // silent by design
      });
    // surface is derived from pathname; the day key is what stops re-sends.
  }, [userId, surface]);
}
