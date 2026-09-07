import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import Button from './ui/Button';

/**
 * One line on the staff dashboard for a user whose password was set by a club
 * admin (api/coach-access.php?action=set-temporary-password) and who has not
 * changed it since. Reads users.password_set_by_admin_at through
 * api/user-profile.php; the profile's own password change clears it.
 *
 * A nudge, not a gate — decided 2026-09-06, no forced change and no auth
 * gateway edit. Absent (migration 097 not applied), null, or a failed read all
 * render nothing. Dismissal is per browser session.
 */

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
const DISMISS_KEY = 'te_admin_set_password_banner_dismissed';

const readDismissed = (): boolean => {
  try {
    return sessionStorage.getItem(DISMISS_KEY) === '1';
  } catch {
    return false;
  }
};

export const AdminSetPasswordBanner: React.FC = () => {
  const [show, setShow] = useState(false);

  useEffect(() => {
    if (readDismissed()) return;
    let cancelled = false;
    (async () => {
      try {
        const token = localStorage.getItem('auth_token');
        const res = await fetch(`${API_URL}/api/user-profile.php`, {
          headers: { Authorization: `Bearer ${token}` },
        });
        if (!res.ok) return;
        const body = await res.json();
        if (!cancelled && body?.success && body?.user?.password_set_by_admin_at) {
          setShow(true);
        }
      } catch {
        // A prompt, never a gate.
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  if (!show) return null;

  const dismiss = () => {
    try {
      sessionStorage.setItem(DISMISS_KEY, '1');
    } catch {
      // ignore
    }
    setShow(false);
  };

  return (
    <div
      className="bg-amber-50 border border-amber-300 text-amber-900 rounded-md px-4 py-2 mb-6 flex items-center justify-between gap-4 text-sm"
      role="status"
    >
      <span>
        Your password was set by a club admin. Please choose your own in{' '}
        <Link to="/profile" className="underline font-semibold">
          your profile
        </Link>
        .
      </span>
      <Button
        variant="ghost"
        size="icon"
        onClick={dismiss}
        aria-label="Dismiss"
      >
        ✕
      </Button>
    </div>
  );
};

export default AdminSetPasswordBanner;
