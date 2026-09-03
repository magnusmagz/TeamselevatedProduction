import React from 'react';
import { Link } from 'react-router-dom';
import { useOrg } from '../contexts/OrgContext';
import { formatDateOnly, toDateOnlyString } from '../utils/dateFormat';
import StatusChip from '../compliance/StatusChip';
import {
  API_URL,
  authHeaders,
  fetchClubStatus,
  recordCompletion,
  reviewCredential,
} from '../compliance/api';
import type {
  ComplianceFilter,
  CompliancePerson,
  ComplianceRow,
  ComplianceSummary,
} from '../compliance/types';

/**
 * The club compliance dashboard — `/compliance` (GOTR G4).
 *
 * Summary tiles, the staff roll call with a per-person rollup, filter chips,
 * a search box, a per-person drawer, and a CSV download.
 *
 * ⚠️ THE TILES COUNT EVERYONE, THE LIST IS FILTERED. `action=club-status`
 * builds its summary before applying the filter precisely so the page can say
 * "3 of 40" — a filtered page that also filtered its own totals cannot.
 *
 * ⚠️ THE FILTER IS A SERVER ROUND TRIP, THE SEARCH IS NOT. The filter decides
 * which people the server returns and must agree with the CSV, which takes the
 * same parameter. Search is a client-side narrowing of what is already on
 * screen; making it a query would mean paging and a second definition of the
 * result set.
 *
 * ⚠️ THE DOWNLOAD IS A FETCH, NOT A LINK. api/compliance-export.php is
 * authenticated with a bearer token from localStorage and a browser navigation
 * cannot carry an Authorization header — an <a href> would save a JSON 401 as a
 * .csv that opens empty and explains nothing. Same reason as
 * RosterDownloadButton.
 *
 * ⚠️ THE EXPORT CAP IS REPORTED. A CSV is a download: nothing renders back to
 * the person who pressed the button, so a file that stopped at the cap is
 * indistinguishable from a club that has that many rows unless the
 * X-Compliance-Export-Truncated header is read and shown.
 */

const FILTERS: { value: ComplianceFilter; label: string }[] = [
  { value: '', label: 'Everyone' },
  { value: 'compliant', label: 'Compliant' },
  { value: 'expiring', label: 'Expiring (30 days)' },
  { value: 'expired', label: 'Expired' },
  { value: 'missing', label: 'Missing' },
];

function fullName(person: CompliancePerson): string {
  return `${person.first_name || ''} ${person.last_name || ''}`.trim() || (person.email || 'Unnamed');
}

export const ClubCompliance: React.FC = () => {
  const { currentClubId } = useOrg();

  const [summary, setSummary] = React.useState<ComplianceSummary | null>(null);
  const [people, setPeople] = React.useState<CompliancePerson[]>([]);
  const [available, setAvailable] = React.useState(true);
  const [filter, setFilter] = React.useState<ComplianceFilter>('');
  const [search, setSearch] = React.useState('');
  const [openPerson, setOpenPerson] = React.useState<number | null>(null);
  const [loading, setLoading] = React.useState(true);
  const [error, setError] = React.useState<string | null>(null);
  const [notice, setNotice] = React.useState<string | null>(null);
  const [downloading, setDownloading] = React.useState(false);

  const load = React.useCallback(async () => {
    if (!currentClubId) return;
    setLoading(true);
    setError(null);
    try {
      const body = await fetchClubStatus(currentClubId, filter);
      setSummary(body.summary);
      setPeople(body.people || []);
      setAvailable(body.available !== false);
    } catch (err: any) {
      setError(err?.message || 'Could not load the compliance report');
    } finally {
      setLoading(false);
    }
  }, [currentClubId, filter]);

  React.useEffect(() => {
    load();
  }, [load]);

  const visible = React.useMemo(() => {
    const needle = search.trim().toLowerCase();
    if (!needle) return people;
    return people.filter(
      (person) =>
        fullName(person).toLowerCase().includes(needle) ||
        (person.email || '').toLowerCase().includes(needle)
    );
  }, [people, search]);

  const download = async () => {
    if (!currentClubId) return;
    setDownloading(true);
    setNotice(null);
    setError(null);
    try {
      const suffix = filter ? `&filter=${filter}` : '';
      const response = await fetch(
        `${API_URL}/api/compliance-export.php?club_id=${currentClubId}${suffix}`,
        { headers: authHeaders() }
      );

      // fetch() does not reject on 4xx/5xx. Without this the error body would
      // be saved as the .csv.
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
      const disposition = response.headers.get('Content-Disposition') || '';
      const match = disposition.match(/filename="?([^";]+)"?/);
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = match ? match[1] : 'compliance.csv';
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      window.URL.revokeObjectURL(url);

      const truncated = response.headers.get('X-Compliance-Export-Truncated');
      if (truncated) {
        setNotice(`Downloaded, but not everything fit. ${truncated}`);
      }
    } catch (err: any) {
      setError(`Download failed: ${err?.message || 'network error'}`);
    } finally {
      setDownloading(false);
    }
  };

  if (!currentClubId) {
    return (
      <div className="container mx-auto p-6">
        <p className="text-gray-600">Choose a club to see its compliance report.</p>
      </div>
    );
  }

  return (
    <div className="container mx-auto p-6">
      <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-brand-primary uppercase tracking-wide">Compliance</h1>
          <p className="mt-1 text-gray-600">
            Who is cleared to take part, and what is about to lapse.
          </p>
        </div>
        <div className="flex items-center gap-3">
          <Link to="/compliance/requirements" className="text-sm text-brand-primary underline">
            Requirements
          </Link>
          <button
            type="button"
            onClick={download}
            disabled={downloading}
            className="rounded-md border border-brand-primary px-4 py-2 text-sm font-semibold uppercase text-brand-primary hover:bg-brand-secondary disabled:opacity-50"
          >
            {downloading ? 'Preparing…' : 'Download CSV'}
          </button>
        </div>
      </div>

      {!available && (
        <div className="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
          Compliance is not switched on for this database yet.
        </div>
      )}

      {error && (
        <div className="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">{error}</div>
      )}

      {notice && (
        <div className="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
          {notice}
        </div>
      )}

      {summary && (
        <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
          <Tile testId="tile-compliant" label="Compliant" value={summary.compliant} total={summary.total} accent="border-green-500" />
          <Tile testId="tile-expiring" label="Expiring in 30 days" value={summary.expiring_30} total={summary.total} accent="border-amber-500" />
          <Tile testId="tile-expired" label="Expired" value={summary.expired} total={summary.total} accent="border-red-500" />
          <Tile testId="tile-missing" label="Missing" value={summary.missing} total={summary.total} accent="border-gray-400" />
        </div>
      )}

      <div className="mb-4 flex flex-wrap items-center gap-2">
        {FILTERS.map((option) => (
          <button
            key={option.value || 'all'}
            type="button"
            aria-pressed={filter === option.value}
            onClick={() => setFilter(option.value)}
            className={`rounded-full border px-3 py-1.5 text-sm ${
              filter === option.value
                ? 'border-brand-primary bg-brand-primary text-white'
                : 'border-gray-300 bg-white text-gray-700'
            }`}
          >
            {option.label}
          </button>
        ))}
        <input
          type="search"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Search name or email"
          aria-label="Search name or email"
          className="ml-auto w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm sm:w-64"
        />
      </div>

      {loading ? (
        <p className="text-gray-500">Loading…</p>
      ) : visible.length === 0 ? (
        <p className="rounded-lg border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">
          {/* An empty FILTER result and an empty club are different answers and
              must not read the same. */}
          {people.length === 0
            ? 'Nobody matches this filter.'
            : 'No one on this list matches your search.'}
        </p>
      ) : (
        <ul className="space-y-2">
          {visible.map((person) => (
            <li key={person.user_id} className="rounded-lg border border-gray-200 bg-white">
              <button
                type="button"
                onClick={() => setOpenPerson(openPerson === person.user_id ? null : person.user_id)}
                aria-expanded={openPerson === person.user_id}
                className="flex w-full flex-wrap items-center gap-3 p-4 text-left hover:bg-gray-50"
              >
                <span className="flex-1">
                  <span className="block font-semibold text-brand-primary">{fullName(person)}</span>
                  <span className="block text-sm text-gray-500">{person.email}</span>
                </span>
                <PersonRollup person={person} />
              </button>

              {openPerson === person.user_id && (
                <PersonDrawer
                  clubId={currentClubId}
                  person={person}
                  onChanged={load}
                  onError={setError}
                />
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
};

const Tile: React.FC<{ testId: string; label: string; value: number; total: number; accent: string }> = ({
  testId,
  label,
  value,
  total,
  accent,
}) => (
  <div data-testid={testId} className={`rounded-lg border-l-4 ${accent} bg-white p-4 shadow-sm`}>
    <div className="text-2xl font-bold text-brand-primary">
      {value}
      <span className="ml-1 text-sm font-normal text-gray-500">of {total}</span>
    </div>
    <div className="mt-1 text-xs uppercase tracking-wide text-gray-500">{label}</div>
  </div>
);

const PersonRollup: React.FC<{ person: CompliancePerson }> = ({ person }) => {
  const { rollup } = person;
  if (rollup.compliant) {
    return (
      <span className="rounded-full border border-green-200 bg-green-100 px-2.5 py-1 text-xs font-semibold text-green-800">
        Compliant
      </span>
    );
  }
  const parts: string[] = [];
  if (rollup.expired) parts.push(`${rollup.expired} expired`);
  if (rollup.missing) parts.push(`${rollup.missing} missing`);
  if (rollup.expiring_30) parts.push(`${rollup.expiring_30} expiring`);
  return (
    <span className="rounded-full border border-amber-300 bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-900">
      {parts.join(' · ') || 'Not compliant'}
    </span>
  );
};

/** Every requirement for one person, with the three admin actions. */
const PersonDrawer: React.FC<{
  clubId: number;
  person: CompliancePerson;
  onChanged: () => void;
  onError: (message: string) => void;
}> = ({ clubId, person, onChanged, onError }) => {
  const [busy, setBusy] = React.useState<number | null>(null);
  const [recording, setRecording] = React.useState<number | null>(null);
  const [completedAt, setCompletedAt] = React.useState(toDateOnlyString(new Date()));
  const [expiresAt, setExpiresAt] = React.useState('');

  const run = async (requirementId: number, action: () => Promise<unknown>) => {
    setBusy(requirementId);
    try {
      await action();
      setRecording(null);
      setExpiresAt('');
      onChanged();
    } catch (err: any) {
      onError(err?.message || 'That did not save');
    } finally {
      setBusy(null);
    }
  };

  const reject = (row: ComplianceRow) => {
    // A rejection with no reason is a dead end for the person on the other
    // side: they are told no and cannot act on it. The server enforces this
    // too — this prompt exists so they are not sent back by a 400.
    const reason = window.prompt('Why is this not accepted? The person will see this.');
    if (!reason || !reason.trim()) return;
    run(row.requirement.id, () =>
      reviewCredential({
        club_id: clubId,
        user_id: person.user_id,
        requirement_id: row.requirement.id,
        decision: 'reject',
        rejection_reason: reason.trim(),
      })
    );
  };

  return (
    <div className="border-t border-gray-100 p-4">
      <ul className="space-y-3">
        {person.requirements.map((row) => (
          <li key={row.requirement.id} className="rounded-md border border-gray-100 bg-gray-50 p-3">
            <div className="flex flex-wrap items-center gap-2">
              <span className="font-medium text-gray-800">{row.requirement.name}</span>
              <StatusChip row={row} />
              {row.requirement.origin && (
                <span className="text-xs text-gray-500">{row.requirement.origin.label}</span>
              )}
              {!row.requirement.required && (
                <span className="rounded-full bg-white px-2 py-0.5 text-xs text-gray-600 ring-1 ring-gray-200">
                  Optional
                </span>
              )}
            </div>

            <p className="mt-1 text-xs text-gray-500">
              {row.completed_at ? `Completed ${formatDateOnly(row.completed_at)}` : 'No completion on file'}
              {row.expires_at ? ` · expires ${formatDateOnly(row.expires_at)}` : ''}
            </p>

            {row.rejection_reason && (
              <p className="mt-1 text-xs text-red-700">Not accepted: {row.rejection_reason}</p>
            )}

            <div className="mt-2 flex flex-wrap gap-3">
              <button
                type="button"
                onClick={() => setRecording(recording === row.requirement.id ? null : row.requirement.id)}
                className="text-sm text-brand-primary underline"
              >
                Record completion
              </button>
              {row.status === 'submitted' && (
                <>
                  <button
                    type="button"
                    disabled={busy === row.requirement.id}
                    onClick={() =>
                      run(row.requirement.id, () =>
                        reviewCredential({
                          club_id: clubId,
                          user_id: person.user_id,
                          requirement_id: row.requirement.id,
                          decision: 'verify',
                        })
                      )
                    }
                    className="text-sm text-green-700 underline disabled:opacity-50"
                  >
                    Verify
                  </button>
                  <button
                    type="button"
                    disabled={busy === row.requirement.id}
                    onClick={() => reject(row)}
                    className="text-sm text-red-700 underline disabled:opacity-50"
                  >
                    Reject
                  </button>
                </>
              )}
            </div>

            {recording === row.requirement.id && (
              <div className="mt-3 flex flex-wrap items-end gap-3">
                <label className="block">
                  <span className="mb-1 block text-xs font-medium text-gray-600">Completed on</span>
                  <input
                    type="date"
                    value={completedAt}
                    onChange={(e) => setCompletedAt(e.target.value)}
                    className="rounded-md border border-gray-300 px-2 py-1 text-sm"
                  />
                </label>
                <label className="block">
                  <span className="mb-1 block text-xs font-medium text-gray-600">
                    Expires (optional override)
                  </span>
                  <input
                    type="date"
                    value={expiresAt}
                    onChange={(e) => setExpiresAt(e.target.value)}
                    className="rounded-md border border-gray-300 px-2 py-1 text-sm"
                  />
                </label>
                <button
                  type="button"
                  disabled={busy === row.requirement.id}
                  onClick={() =>
                    run(row.requirement.id, () =>
                      recordCompletion({
                        club_id: clubId,
                        user_id: person.user_id,
                        requirement_id: row.requirement.id,
                        // Both are already 'YYYY-MM-DD' from the date input —
                        // never round-tripped through a Date, which would move
                        // them a day for half the country.
                        completed_at: completedAt,
                        expires_at: expiresAt || null,
                      })
                    )
                  }
                  className="rounded-md bg-brand-primary px-3 py-1.5 text-sm font-semibold uppercase text-white disabled:opacity-50"
                >
                  Save
                </button>
              </div>
            )}
          </li>
        ))}
      </ul>
    </div>
  );
};

export default ClubCompliance;
