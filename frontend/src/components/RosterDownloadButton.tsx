import React from 'react';
import { useAuth } from '../hooks/useAuth';

/**
 * Download a team's roster as CSV, in one of two flavours.
 *
 * WHY THIS IS A FETCH AND NOT A PLAIN LINK
 * The endpoint is authenticated with a bearer token held in localStorage, and a
 * browser navigation cannot carry an Authorization header. An <a href> would
 * arrive unauthenticated and download a JSON 401 body saved as a .csv — a file
 * that opens, looks empty, and gives no clue why. So the response is fetched,
 * checked, and only then turned into a download.
 *
 * WHO SEES THE BUTTON
 * Staff — a coach or club admin (roles are additive, so each is tested
 * independently; a coach who is also a parent is still a coach). This is a
 * convenience only. `te_team_roster_staff_standing()` on the server is the
 * control that matters, and it is what stops a coach downloading a team that is
 * not theirs — something this component cannot know from the token, because the
 * JWT carries no team scope.
 */

interface Props {
  teamId: number | string;
}

type Flavour = 'athletes' | 'crew';

const RosterDownloadButton: React.FC<Props> = ({ teamId }) => {
  const { user } = useAuth();
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

  const [open, setOpen] = React.useState(false);
  const [busy, setBusy] = React.useState<Flavour | null>(null);
  const [error, setError] = React.useState<string | null>(null);
  const menuRef = React.useRef<HTMLDivElement>(null);

  const isStaff =
    user?.system_role === 'super_admin' ||
    (user?.roles || []).some((r) => r.role === 'club_admin' || r.role === 'coach');

  React.useEffect(() => {
    if (!open) return;
    const onClickAway = (e: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', onClickAway);
    return () => document.removeEventListener('mousedown', onClickAway);
  }, [open]);

  const download = async (flavour: Flavour) => {
    setOpen(false);
    setError(null);
    setBusy(flavour);

    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(
        `${API_URL}/api/roster-export.php?team_id=${teamId}&include=${flavour}`,
        { headers: { Authorization: `Bearer ${token}` } }
      );

      // fetch() does not reject on 4xx/5xx. Without this check the error body
      // would be saved as the .csv.
      if (!response.ok) {
        let message = `Download failed (${response.status})`;
        try {
          const body = await response.json();
          if (body?.error) message = body.error;
        } catch {
          // Non-JSON error body — keep the status message.
        }
        setError(message);
        return;
      }

      const blob = await response.blob();

      // Prefer the filename the server chose; it carries the team name and date.
      const disposition = response.headers.get('Content-Disposition') || '';
      const match = disposition.match(/filename="?([^";]+)"?/);
      const filename = match ? match[1] : `roster-${flavour}.csv`;

      const url = window.URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      window.URL.revokeObjectURL(url);

      // The caps are never silent: if the roster did not fit, say so rather than
      // handing over a short file that looks complete.
      const truncated = response.headers.get('X-Roster-Export-Truncated');
      if (truncated) {
        setError(`Downloaded, but not everything fit. ${truncated}`);
      }
    } catch (err: any) {
      setError(`Download failed: ${err?.message || 'network error'}`);
    } finally {
      setBusy(null);
    }
  };

  if (!isStaff) return null;

  return (
    <div className="relative inline-block text-left" ref={menuRef}>
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        disabled={busy !== null}
        className="inline-flex items-center text-sm text-brand-primary hover:underline uppercase font-medium disabled:opacity-50"
        aria-haspopup="menu"
        aria-expanded={open}
      >
        <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
        </svg>
        {busy ? 'Preparing…' : 'Download'}
      </button>

      {open && (
        <div
          role="menu"
          className="absolute right-0 z-20 mt-2 w-64 bg-white border border-brand-secondary rounded-lg shadow-lg py-1"
        >
          <button
            type="button"
            role="menuitem"
            onClick={() => download('athletes')}
            className="w-full text-left px-4 py-3 hover:bg-gray-50"
          >
            <div className="text-sm font-semibold text-brand-primary">Athletes (CSV)</div>
            <div className="text-xs text-gray-500">Name, jersey number, date of birth, position, status</div>
          </button>
          <button
            type="button"
            role="menuitem"
            onClick={() => download('crew')}
            className="w-full text-left px-4 py-3 hover:bg-gray-50 border-t border-gray-100"
          >
            <div className="text-sm font-semibold text-brand-primary">Athletes + Crew (CSV)</div>
            <div className="text-xs text-gray-500">Everything above, plus each athlete's crew and their contact details</div>
          </button>
        </div>
      )}

      {error && (
        <div className="absolute right-0 z-20 mt-2 w-72 bg-amber-50 border border-amber-200 rounded-lg p-3 text-left">
          <p className="text-xs text-amber-900">{error}</p>
          <button
            type="button"
            onClick={() => setError(null)}
            className="mt-2 text-xs text-amber-900 underline"
          >
            Dismiss
          </button>
        </div>
      )}
    </div>
  );
};

export default RosterDownloadButton;
