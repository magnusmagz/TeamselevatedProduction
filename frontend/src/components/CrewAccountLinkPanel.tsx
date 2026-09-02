import React, { useCallback, useEffect, useMemo, useState } from 'react';

/**
 * Accounts not connected to a family — the club-admin repair for the case the
 * email match cannot reach.
 *
 * A crew member signs in on one address while their crew record carries
 * another, so nothing derives their family and the portal tells them no
 * athletes are registered to them. Until now there was no repair inside the
 * product: an admin could edit one of the two addresses, which moves the
 * problem rather than recording who this person is.
 *
 * The panel lists those accounts, offers the name-matched crew records the
 * backend found, and lets an admin search the whole club when none of them is
 * right. Connecting writes one durable row (`user_guardians`, source
 * `admin_link`); it does not touch either email address.
 *
 * Three things here are deliberate:
 *
 *  - **Every suggestion is a name-string match and nothing more.** They are
 *    offered to somebody who knows the club, never applied. Each one shows the
 *    athletes it would connect, because a surname is weak evidence and the
 *    children are the thing an admin actually recognises.
 *  - **Success states the COUNT.** "Connected" alone cannot tell you whether the
 *    link resolved to two children or to nobody, and a link pointed at the wrong
 *    crew record looks exactly like a correct one until a family complains.
 *  - **A crew record already connected to someone else is refused with a name**
 *    (409), and that message is shown as-is rather than flattened into "could
 *    not connect". Who holds it is the only fact that helps.
 */

export interface LinkableCrewMember {
  guardian_id: number;
  first_name: string;
  last_name: string;
  email: string;
  athletes?: string;
}

interface Suggestion {
  guardian_id: number;
  first_name: string;
  last_name: string;
  email: string;
  mobile_phone: string;
  match: 'first_and_last_name' | 'last_name' | 'first_name';
  athletes: Array<{ id: number; first_name: string; last_name: string }>;
  already_reachable_by: { user_id: number; email: string } | null;
}

interface Candidate {
  user_id: number;
  first_name: string;
  last_name: string;
  email: string;
  last_login_at: string | null;
  suggestions: Suggestion[];
}

interface Props {
  clubProfileId?: number | null;
  isClubAdmin: boolean;
  /**
   * The club's crew, as the Crew page already has it. The search box picks from
   * this rather than calling a new endpoint: the page has loaded every crew
   * member in the club by the time this renders, so a second club-wide search
   * endpoint would return the same people over the network again.
   */
  searchPool: LinkableCrewMember[];
  /** Ask the page to reload — a connected family changes their portal status. */
  onLinked?: () => void;
}

const MATCH_LABEL: Record<Suggestion['match'], string> = {
  first_and_last_name: 'Same first and last name',
  last_name: 'Same last name',
  first_name: 'Same first name',
};

const CrewAccountLinkPanel: React.FC<Props> = ({ clubProfileId, isClubAdmin, searchPool, onLinked }) => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const token = localStorage.getItem('auth_token');

  // Rebuilt every render, the effects below could not honestly list it as a
  // dependency — which is the exhaustive-deps warning shape the lint ratchet is
  // being worked down through. Memoised on the token instead.
  const headers = useMemo(
    () => ({ 'Content-Type': 'application/json', Authorization: `Bearer ${token}` }),
    [token]
  );

  const [candidates, setCandidates] = useState<Candidate[]>([]);
  const [loading, setLoading] = useState(false);
  const [loaded, setLoaded] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<number | null>(null);
  const [result, setResult] = useState<{ userId: number; message: string; ok: boolean } | null>(null);
  const [searchFor, setSearchFor] = useState<number | null>(null);
  const [query, setQuery] = useState('');

  const load = useCallback(async () => {
    if (!clubProfileId || !isClubAdmin) return;
    setLoading(true);
    setError(null);
    try {
      const res = await fetch(
        `${API_URL}/api/crew-link.php?action=candidates&club_id=${clubProfileId}`,
        { headers }
      );
      const data = await res.json();
      if (!res.ok || !data.success) throw new Error(data.error || 'Could not load unconnected accounts');
      setCandidates(data.candidates || []);
    } catch (e: any) {
      setError(e.message || 'Could not load unconnected accounts.');
    } finally {
      setLoading(false);
      setLoaded(true);
    }
  }, [API_URL, clubProfileId, headers, isClubAdmin]);

  useEffect(() => { load(); }, [load]);

  const connect = async (userId: number, guardianId: number, guardianName: string) => {
    if (!clubProfileId) return;
    setBusy(userId);
    setResult(null);
    try {
      const res = await fetch(`${API_URL}/api/crew-link.php?action=link`, {
        method: 'POST',
        headers,
        body: JSON.stringify({ club_id: clubProfileId, user_id: userId, guardian_id: guardianId }),
      });
      const data = await res.json();

      if (res.status === 409 && data.linked_to) {
        // Name the account that holds it. "Could not connect" would send the
        // admin looking for a bug instead of at the other account.
        setResult({
          userId,
          ok: false,
          message:
            `${guardianName} is already connected to ` +
            `${data.linked_to.first_name} ${data.linked_to.last_name} (${data.linked_to.email}).`,
        });
        return;
      }

      if (!res.ok || !data.success) throw new Error(data.error || 'Could not connect them');

      const n = (data.athletes || []).length;
      setResult({
        userId,
        ok: n > 0,
        message:
          n > 0
            ? `Connected — ${n} athlete${n === 1 ? '' : 's'} now visible to ${guardianName}.`
            : `Connected, but no athletes are attached to ${guardianName}. ` +
              `That is probably the wrong crew record — check it before leaving it in place.`,
      });

      setSearchFor(null);
      setQuery('');
      await load();
      onLinked?.();
    } catch (e: any) {
      setResult({ userId, ok: false, message: e.message || 'Could not connect them.' });
    } finally {
      setBusy(null);
    }
  };

  const matches = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (q === '') return [];
    return searchPool
      .filter(
        (m) =>
          `${m.first_name} ${m.last_name}`.toLowerCase().includes(q) ||
          (m.email || '').toLowerCase().includes(q) ||
          (m.athletes || '').toLowerCase().includes(q)
      )
      .slice(0, 8);
  }, [query, searchPool]);

  if (!isClubAdmin || !clubProfileId) return null;
  // Nothing to repair is the normal state. An empty panel would be a permanent
  // scary-looking header on a healthy club.
  //
  // ⚠️ `result` is part of this condition on purpose. Connecting the last stuck
  // account empties the list, and without it the panel unmounts in the same tick
  // as the reload — taking the confirmation with it, so the one moment the admin
  // most needs to read a count shows nothing at all.
  if (loaded && !error && candidates.length === 0 && !result) return null;

  return (
    <div className="mb-5 border border-amber-200 bg-amber-50 rounded-lg p-4">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h2 className="text-sm font-semibold uppercase tracking-wide text-amber-900">
            Accounts not connected to a family
          </h2>
          <p className="text-xs text-amber-800 mt-1 max-w-2xl">
            These people can sign in, but nothing links them to a crew record, so their portal is
            empty. That usually means they signed up with a different email address from the one
            on file. Connecting them records who they are — it does not change either address.
          </p>
        </div>
        {loading && <span className="text-xs text-amber-800">Loading…</span>}
      </div>

      {error && (
        <div className="mt-3 p-2 bg-red-50 border border-red-200 text-red-700 rounded text-sm">{error}</div>
      )}

      {result && (
        <div
          role="status"
          className={`mt-3 text-sm rounded px-2 py-1 ${
            result.ok ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-700'
          }`}
        >
          {result.message}
        </div>
      )}

      <ul className="mt-3 space-y-3">
        {candidates.map((c) => (
          <li key={c.user_id} className="bg-white border border-amber-200 rounded-lg p-3">
            <div className="flex flex-wrap items-baseline gap-x-2">
              <span className="font-semibold text-gray-900">
                {`${c.first_name} ${c.last_name}`}
              </span>
              <span className="text-sm text-gray-500">{c.email}</span>
              <span className="text-xs text-gray-400">
                {c.last_login_at ? `last signed in ${c.last_login_at}` : 'never signed in'}
              </span>
            </div>

            {c.suggestions.length > 0 ? (
              <ul className="mt-2 space-y-1">
                {c.suggestions.map((s) => (
                  <li
                    key={s.guardian_id}
                    className="flex flex-wrap items-center justify-between gap-2 text-sm border-t border-gray-100 pt-2"
                  >
                    <div>
                      <span className="font-medium text-gray-800">
                        {s.first_name} {s.last_name}
                      </span>{' '}
                      <span className="text-gray-500">{s.email || 'no email on file'}</span>
                      <div className="text-xs text-gray-500">
                        {MATCH_LABEL[s.match]}
                        {s.athletes.length > 0 && (
                          <> · {s.athletes.map((a) => `${a.first_name} ${a.last_name}`).join(', ')}</>
                        )}
                      </div>
                      {s.already_reachable_by && (
                        <div className="text-xs text-amber-700">
                          Already reachable by {s.already_reachable_by.email} — connect only if that
                          is the same person with two logins.
                        </div>
                      )}
                    </div>
                    <button
                      type="button"
                      disabled={busy === c.user_id}
                      onClick={() =>
                        connect(c.user_id, s.guardian_id, `${s.first_name} ${s.last_name}`)
                      }
                      className="shrink-0 px-3 py-1.5 rounded-md bg-brand-primary text-white text-xs font-semibold uppercase disabled:opacity-50"
                    >
                      Connect to {s.first_name} {s.last_name}
                    </button>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="mt-2 text-xs text-gray-500">
                No crew record in this club shares their name. Search for the right one below.
              </p>
            )}

            <div className="mt-2">
              {searchFor === c.user_id ? (
                <div>
                  <input
                    autoFocus
                    type="text"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder="Search crew by name, email or athlete…"
                    aria-label={`Search crew to connect to ${c.first_name} ${c.last_name}`}
                    className="w-full sm:max-w-sm px-3 py-1.5 border border-gray-300 rounded-md text-sm outline-none focus:ring-2 focus:ring-brand-primary"
                  />
                  <ul className="mt-1 space-y-1">
                    {matches.map((m) => (
                      <li key={m.guardian_id} className="flex items-center justify-between gap-2 text-sm">
                        <span>
                          {m.first_name} {m.last_name}{' '}
                          <span className="text-gray-500">{m.email}</span>
                          {m.athletes && <span className="text-xs text-gray-500"> · {m.athletes}</span>}
                        </span>
                        <button
                          type="button"
                          disabled={busy === c.user_id}
                          onClick={() =>
                            connect(c.user_id, m.guardian_id, `${m.first_name} ${m.last_name}`)
                          }
                          className="shrink-0 px-3 py-1 rounded-md border border-brand-primary text-brand-primary text-xs font-semibold uppercase disabled:opacity-50"
                        >
                          Connect
                        </button>
                      </li>
                    ))}
                    {query.trim() !== '' && matches.length === 0 && (
                      <li className="text-xs text-gray-500">No crew member matches that.</li>
                    )}
                  </ul>
                  <button
                    type="button"
                    onClick={() => { setSearchFor(null); setQuery(''); }}
                    className="mt-1 text-xs text-gray-500 underline"
                  >
                    Cancel
                  </button>
                </div>
              ) : (
                <button
                  type="button"
                  onClick={() => { setSearchFor(c.user_id); setQuery(''); }}
                  className="text-xs font-semibold uppercase text-brand-primary"
                >
                  Search all crew
                </button>
              )}
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
};

export default CrewAccountLinkPanel;
