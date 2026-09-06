import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { useOrg } from '../contexts/OrgContext';
import { formatDateOnly } from '../utils/dateFormat';
import { refereeCategoryLabel } from '../constants/refereeFeedbackCategories';
import { RefereeFeedbackRow, RefereeSummaryRow } from '../components/referee/types';

/**
 * Club admin review of referee feedback (CKU R68, slice 8.6).
 *
 * Route /referee-feedback, behind ProtectedClubAdminRoute — and the server
 * gates `list` and `export` on te_is_club_admin() regardless, because a route
 * guard is navigation, not access control.
 *
 * The CSV is fetched with the bearer token and turned into a download, the way
 * RosterDownloadButton does it: a plain <a href> cannot carry the header and
 * would save a JSON 401 as a .csv. The export cap is reported in a response
 * header and shown here rather than handing over a short file that looks
 * complete.
 */

interface Filters {
  from: string;
  to: string;
  team_id: string;
  incident: boolean;
  referee_name: string;
}

const emptyFilters: Filters = { from: '', to: '', team_id: '', incident: false, referee_name: '' };

function query(clubId: number, f: Filters): string {
  const p = new URLSearchParams();
  p.set('club_id', String(clubId));
  if (f.from) p.set('from', f.from);
  if (f.to) p.set('to', f.to);
  if (f.team_id) p.set('team_id', f.team_id);
  if (f.incident) p.set('incident', '1');
  if (f.referee_name.trim()) p.set('referee_name', f.referee_name.trim());
  return p.toString();
}

const RefereeFeedback: React.FC = () => {
  const { currentClubId } = useOrg();
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

  const [draft, setDraft] = useState<Filters>(emptyFilters);
  const [applied, setApplied] = useState<Filters>(emptyFilters);
  const [rows, setRows] = useState<RefereeFeedbackRow[]>([]);
  const [summary, setSummary] = useState<RefereeSummaryRow[]>([]);
  const [available, setAvailable] = useState(true);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [downloading, setDownloading] = useState(false);
  const [downloadNote, setDownloadNote] = useState<string | null>(null);

  const headers = useMemo(
    () => ({ Authorization: `Bearer ${localStorage.getItem('auth_token')}` }),
    []
  );

  const load = useCallback(async () => {
    if (!currentClubId) return;
    setLoading(true);
    setError(null);
    try {
      const res = await fetch(`${API_URL}/api/referee-feedback.php?action=list&${query(currentClubId, applied)}`, { headers });
      const data = await res.json();
      if (!res.ok || !data?.success) {
        setError(data?.error || `Could not load referee feedback (${res.status})`);
        return;
      }
      setAvailable(data.available !== false);
      setRows(Array.isArray(data.feedback) ? data.feedback : []);
      setSummary(Array.isArray(data.summary) ? data.summary : []);
    } catch (err: any) {
      setError(err?.message || 'Could not load referee feedback');
    } finally {
      setLoading(false);
    }
  }, [API_URL, currentClubId, applied, headers]);

  useEffect(() => {
    load();
  }, [load]);

  // Team choices come from the rows themselves — the teams that have feedback
  // are the only ones worth filtering on.
  const teamOptions = useMemo(() => {
    const seen = new Map<number, string>();
    rows.forEach((r) => {
      if (!seen.has(r.team_id)) seen.set(r.team_id, r.team_name ?? `Team ${r.team_id}`);
    });
    return Array.from(seen.entries()).sort((a, b) => a[1].localeCompare(b[1]));
  }, [rows]);

  const download = async () => {
    if (!currentClubId) return;
    setDownloading(true);
    setDownloadNote(null);
    try {
      const res = await fetch(`${API_URL}/api/referee-feedback.php?action=export&${query(currentClubId, applied)}`, { headers });
      if (!res.ok) {
        let message = `Download failed (${res.status})`;
        try {
          const body = await res.json();
          if (body?.error) message = body.error;
        } catch {
          // keep the status message
        }
        setDownloadNote(message);
        return;
      }
      const blob = await res.blob();
      const disposition = res.headers.get('Content-Disposition') || '';
      const match = disposition.match(/filename="?([^";]+)"?/);
      const filename = match ? match[1] : 'referee-feedback.csv';

      const url = window.URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      window.URL.revokeObjectURL(url);

      const truncated = res.headers.get('X-Referee-Feedback-Export-Truncated');
      if (truncated) setDownloadNote(`Downloaded, but not everything fit. ${truncated}`);
    } catch (err: any) {
      setDownloadNote(`Download failed: ${err?.message || 'network error'}`);
    } finally {
      setDownloading(false);
    }
  };

  const incidentCount = rows.filter((r) => r.incident).length;

  return (
    <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <div className="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-brand-primary">Referee Feedback</h1>
          <p className="text-sm text-gray-600 mt-1">
            What your coaches recorded about the referees of their games. Coaches see only their own; this page is club-admin only.
          </p>
        </div>
        <div className="text-right">
          <button
            type="button"
            onClick={download}
            disabled={downloading || !available || rows.length === 0}
            className="inline-flex items-center px-4 py-2 bg-brand-primary text-white rounded-md font-semibold uppercase text-sm disabled:opacity-50"
          >
            {downloading ? 'Preparing…' : 'Download CSV'}
          </button>
          {downloadNote && (
            <p className="mt-2 text-xs text-amber-900 bg-amber-50 border border-amber-200 rounded p-2 max-w-xs">{downloadNote}</p>
          )}
        </div>
      </div>

      {!available && !loading && (
        <div className="bg-amber-50 border border-amber-200 rounded-md p-4 text-sm text-amber-900 mb-6">
          Referee feedback is not switched on for this club yet. The database update for this feature has not been applied.
        </div>
      )}

      <form
        onSubmit={(e) => {
          e.preventDefault();
          setApplied(draft);
        }}
        className="bg-white border border-gray-200 rounded-lg p-4 mb-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 items-end"
      >
        <div>
          <label htmlFor="rf-from" className="block text-xs font-medium text-gray-600">From</label>
          <input id="rf-from" type="date" value={draft.from} onChange={(e) => setDraft({ ...draft, from: e.target.value })}
            className="mt-1 block w-full border border-gray-300 rounded-md px-2 py-1.5 text-sm" />
        </div>
        <div>
          <label htmlFor="rf-to" className="block text-xs font-medium text-gray-600">To</label>
          <input id="rf-to" type="date" value={draft.to} onChange={(e) => setDraft({ ...draft, to: e.target.value })}
            className="mt-1 block w-full border border-gray-300 rounded-md px-2 py-1.5 text-sm" />
        </div>
        <div>
          <label htmlFor="rf-team" className="block text-xs font-medium text-gray-600">Team</label>
          <select id="rf-team" value={draft.team_id} onChange={(e) => setDraft({ ...draft, team_id: e.target.value })}
            className="mt-1 block w-full border border-gray-300 rounded-md px-2 py-1.5 text-sm">
            <option value="">All teams</option>
            {teamOptions.map(([id, name]) => (
              <option key={id} value={id}>{name}</option>
            ))}
          </select>
        </div>
        <div>
          <label htmlFor="rf-name" className="block text-xs font-medium text-gray-600">Referee</label>
          <input id="rf-name" type="text" value={draft.referee_name} onChange={(e) => setDraft({ ...draft, referee_name: e.target.value })}
            placeholder="Any part of the name"
            className="mt-1 block w-full border border-gray-300 rounded-md px-2 py-1.5 text-sm" />
        </div>
        <label className="flex items-center gap-2 text-sm text-gray-800 pb-2">
          <input id="rf-incident" type="checkbox" checked={draft.incident} onChange={(e) => setDraft({ ...draft, incident: e.target.checked })} />
          Incidents only
        </label>
        <div className="flex gap-2">
          <button type="submit" className="px-4 py-2 bg-brand-primary text-white rounded-md text-sm font-semibold uppercase">Apply</button>
          <button
            type="button"
            onClick={() => { setDraft(emptyFilters); setApplied(emptyFilters); }}
            className="px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-700"
          >
            Clear
          </button>
        </div>
      </form>

      {error && <div className="bg-red-50 border border-red-200 rounded-md p-3 text-sm text-red-800 mb-6">{error}</div>}

      {loading ? (
        <p className="text-sm text-gray-500">Loading…</p>
      ) : (
        <>
          {summary.length > 0 && (
            <section className="mb-8">
              <h2 className="text-lg font-semibold text-brand-primary mb-2">By referee</h2>
              <p className="text-xs text-gray-500 mb-3">
                Grouped on the name exactly as coaches typed it — there is no referee registry, so two spellings are two rows.
              </p>
              <div className="overflow-x-auto border border-gray-200 rounded-lg">
                <table className="min-w-full text-sm">
                  <thead className="bg-gray-50 text-left text-xs uppercase text-gray-600">
                    <tr>
                      <th className="px-4 py-2">Referee</th>
                      <th className="px-4 py-2 text-right">Feedback</th>
                      <th className="px-4 py-2 text-right">Avg rating</th>
                      <th className="px-4 py-2 text-right">Incidents</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-100">
                    {summary.map((s) => (
                      <tr key={s.referee_name} data-testid={`summary-${s.referee_name}`}>
                        <td className="px-4 py-2 font-medium text-gray-900">{s.referee_name}</td>
                        <td className="px-4 py-2 text-right">{s.count}</td>
                        <td className="px-4 py-2 text-right">{Number(s.average_rating).toFixed(1)}</td>
                        <td className={`px-4 py-2 text-right ${s.incident_count > 0 ? 'text-red-700 font-semibold' : ''}`}>{s.incident_count}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>
          )}

          <section>
            <div className="flex items-baseline justify-between mb-2">
              <h2 className="text-lg font-semibold text-brand-primary">All feedback</h2>
              <span className="text-xs text-gray-500">
                {rows.length} row{rows.length === 1 ? '' : 's'}{incidentCount > 0 ? ` · ${incidentCount} flagged` : ''}
              </span>
            </div>
            {rows.length === 0 ? (
              <p className="text-sm text-gray-500 border border-dashed border-gray-300 rounded-lg p-6 text-center">
                No referee feedback matches these filters.
              </p>
            ) : (
              <div className="overflow-x-auto border border-gray-200 rounded-lg">
                <table className="min-w-full text-sm">
                  <thead className="bg-gray-50 text-left text-xs uppercase text-gray-600">
                    <tr>
                      <th className="px-4 py-2">Game</th>
                      <th className="px-4 py-2">Team</th>
                      <th className="px-4 py-2">Referee</th>
                      <th className="px-4 py-2 text-right">Rating</th>
                      <th className="px-4 py-2">Categories</th>
                      <th className="px-4 py-2">Comments</th>
                      <th className="px-4 py-2">Coach</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-100">
                    {rows.map((r) => (
                      <tr key={r.id} data-incident={r.incident ? 'true' : 'false'} className={r.incident ? 'bg-red-50' : ''}>
                        <td className="px-4 py-2 whitespace-nowrap">
                          <div className="text-gray-900">{formatDateOnly(r.event_date)}</div>
                          <div className="text-xs text-gray-500">
                            {r.event_name}{r.opponent_name ? ` vs ${r.opponent_name}` : ''}
                          </div>
                        </td>
                        <td className="px-4 py-2 whitespace-nowrap">{r.team_name ?? '—'}</td>
                        <td className="px-4 py-2 whitespace-nowrap">
                          {r.referee_name}
                          {r.incident && (
                            <span className="ml-2 inline-block px-2 py-0.5 text-xs rounded bg-red-100 text-red-800">Incident</span>
                          )}
                        </td>
                        <td className="px-4 py-2 text-right">{r.rating}/5</td>
                        <td className="px-4 py-2 text-xs text-gray-600">{r.categories.map(refereeCategoryLabel).join(', ') || '—'}</td>
                        <td className="px-4 py-2 text-gray-700 max-w-md">{r.comments || '—'}</td>
                        <td className="px-4 py-2 whitespace-nowrap">{r.submitted_by_name}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>
        </>
      )}
    </main>
  );
};

export default RefereeFeedback;
