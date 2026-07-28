import React, { useState, useEffect, useRef } from 'react';
import { useOrg } from '../contexts/OrgContext';

/**
 * Crew — the club-wide roster of parents, guardians & family attached to any
 * athlete in the club, with parent-portal invite status and bulk invite.
 * Data: auth-gateway.php?action=club-parents; invites reuse send-parent-invite.
 */

interface CrewMember {
  guardian_id: number;
  first_name: string;
  last_name: string;
  email: string;
  mobile_phone: string;
  athletes: string;
  athlete_id: number;
  status: 'active' | 'invited' | 'not_invited' | 'no_email';
}

const STATUS_META: Record<string, { label: string; cls: string }> = {
  active: { label: 'Portal active', cls: 'bg-green-100 text-green-700' },
  invited: { label: 'Invited', cls: 'bg-amber-100 text-amber-800' },
  not_invited: { label: 'Not invited', cls: 'bg-gray-100 text-gray-600' },
  no_email: { label: 'No email', cls: 'bg-gray-100 text-gray-500' },
};

const FILTERS = [
  { key: 'all', label: 'All' },
  { key: 'not_invited', label: 'Not invited' },
  { key: 'invited', label: 'Invited' },
  { key: 'active', label: 'Portal active' },
  { key: 'no_email', label: 'No email' },
];

const CrewRoster: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const token = localStorage.getItem('auth_token');
  const { activeContext } = useOrg();
  const clubProfileId = activeContext?.scope_id;

  const [crew, setCrew] = useState<CrewMember[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [filter, setFilter] = useState('all');
  const [inviting, setInviting] = useState<number | null>(null);
  const [bulk, setBulk] = useState<{ running: boolean; done: number; total: number }>({ running: false, done: 0, total: 0 });
  const cancelRef = useRef(false);

  const load = async () => {
    if (!clubProfileId) return;
    setLoading(true);
    setError(null);
    try {
      const res = await fetch(`${API_URL}/api/auth-gateway.php?action=club-parents`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
        body: JSON.stringify({ club_id: clubProfileId }),
      });
      const data = await res.json();
      if (!res.ok || !data.success) throw new Error(data.error || 'Failed to load crew');
      setCrew(data.parents || []);
    } catch (e: any) {
      setError(e.message || 'Failed to load crew.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { load(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [clubProfileId]);

  const sendInvite = async (m: CrewMember) => {
    const res = await fetch(`${API_URL}/api/auth-gateway.php?action=send-parent-invite`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify({ guardian_id: m.guardian_id, club_id: clubProfileId, athlete_id: m.athlete_id }),
    });
    const data = await res.json();
    return { ok: res.ok && data.success, data };
  };

  const handleInvite = async (m: CrewMember) => {
    setInviting(m.guardian_id);
    try {
      const { ok, data } = await sendInvite(m);
      if (ok) {
        alert(data.status === 'already_active' ? 'They already have an account.' : `Invite sent to ${data.email}`);
        await load();
      } else {
        alert(data.error || 'Could not send invite.');
      }
    } catch {
      alert('Could not send invite.');
    } finally {
      setInviting(null);
    }
  };

  const counts = {
    total: crew.length,
    not_invited: crew.filter((m) => m.status === 'not_invited').length,
    invited: crew.filter((m) => m.status === 'invited').length,
    active: crew.filter((m) => m.status === 'active').length,
    no_email: crew.filter((m) => m.status === 'no_email').length,
  };

  const filtered = crew.filter((m) => {
    const matchesFilter = filter === 'all' || m.status === filter;
    const q = search.trim().toLowerCase();
    const matchesSearch =
      q === '' ||
      `${m.first_name} ${m.last_name}`.toLowerCase().includes(q) ||
      (m.email || '').toLowerCase().includes(q) ||
      (m.athletes || '').toLowerCase().includes(q);
    return matchesFilter && matchesSearch;
  });

  const inviteAll = async () => {
    const targets = filtered.filter((m) => m.status === 'not_invited');
    if (!targets.length) return;
    if (!window.confirm(
      `Send a portal invite to ${targets.length} crew member${targets.length === 1 ? '' : 's'}?\n\n` +
      `Each gets an email with a link to set their password.`
    )) return;

    cancelRef.current = false;
    setBulk({ running: true, done: 0, total: targets.length });
    let sent = 0, failed = 0;
    for (const m of targets) {
      if (cancelRef.current) break;
      try {
        const { ok } = await sendInvite(m);
        ok ? sent++ : failed++;
      } catch {
        failed++;
      }
      setBulk((b) => ({ ...b, done: b.done + 1 }));
    }
    setBulk({ running: false, done: 0, total: 0 });
    alert(`Invites sent: ${sent}${failed ? ` · failed: ${failed}` : ''}${cancelRef.current ? ' (stopped early)' : ''}`);
    await load();
  };

  return (
    <div className="p-6 max-w-7xl mx-auto">
      {/* Header */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-2 gap-4">
        <div>
          <h1 className="text-2xl font-bold text-brand-primary uppercase tracking-wide">Crew</h1>
          <p className="text-sm text-gray-500 mt-1">Crew &amp; family across your club</p>
        </div>
        <button
          onClick={inviteAll}
          disabled={bulk.running || counts.not_invited === 0}
          className="inline-flex items-center bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-3 hover:bg-brand-primary uppercase font-semibold text-sm disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {bulk.running
            ? `Inviting ${bulk.done} of ${bulk.total}…`
            : `Invite all not invited (${filtered.filter((m) => m.status === 'not_invited').length})`}
        </button>
      </div>

      {/* Bulk progress */}
      {bulk.running && (
        <div className="mb-4 p-3 bg-brand-light border border-brand-secondary rounded-lg flex items-center justify-between gap-4">
          <div className="flex-1">
            <div className="h-2 bg-white rounded-full overflow-hidden">
              <div
                className="h-full bg-brand-accent transition-all"
                style={{ width: `${bulk.total ? (bulk.done / bulk.total) * 100 : 0}%` }}
              />
            </div>
          </div>
          <button onClick={() => { cancelRef.current = true; }} className="text-sm font-semibold uppercase text-brand-primary">
            Stop
          </button>
        </div>
      )}

      {error && (
        <div className="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm">{error}</div>
      )}

      {/* Status filter chips */}
      <div className="flex flex-wrap items-center gap-2 mb-4">
        {FILTERS.map((f) => {
          const n = f.key === 'all' ? counts.total : (counts as any)[f.key];
          const active = filter === f.key;
          return (
            <button
              key={f.key}
              onClick={() => setFilter(f.key)}
              className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold transition-all ${
                active ? 'bg-brand-primary text-white' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50'
              }`}
            >
              {f.label}
              <span className={active ? 'text-white/70' : 'text-gray-400'}>{n}</span>
            </button>
          );
        })}
      </div>

      {/* Search */}
      <div className="mb-5">
        <input
          type="text"
          placeholder="Search by name, email, or athlete…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="w-full sm:max-w-md px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-primary focus:border-brand-primary outline-none"
        />
      </div>

      {loading ? (
        <div className="space-y-2">
          {[1, 2, 3, 4, 5].map((i) => <div key={i} className="h-12 bg-gray-100 rounded animate-pulse" />)}
        </div>
      ) : filtered.length === 0 ? (
        <div className="text-center py-16 text-gray-500">No crew members match this view.</div>
      ) : (
        <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  {['Crew member', 'Email', 'Athlete(s)', 'Status', ''].map((h, i) => (
                    <th key={i} className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {filtered.map((m) => {
                  const meta = STATUS_META[m.status] || STATUS_META.not_invited;
                  const busy = inviting === m.guardian_id;
                  return (
                    <tr key={m.guardian_id} className="hover:bg-gray-50">
                      <td className="px-4 py-3 text-sm font-medium text-gray-900 whitespace-nowrap">
                        {m.first_name} {m.last_name}
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-500">{m.email || <span className="italic text-gray-400">none</span>}</td>
                      <td className="px-4 py-3 text-sm text-gray-500">{m.athletes}</td>
                      <td className="px-4 py-3 whitespace-nowrap">
                        <span className={`text-xs px-2 py-0.5 rounded-full font-semibold ${meta.cls}`}>{meta.label}</span>
                      </td>
                      <td className="px-4 py-3 text-right whitespace-nowrap">
                        {m.status === 'active' || m.status === 'no_email' ? null : (
                          <button
                            onClick={() => handleInvite(m)}
                            disabled={busy || bulk.running}
                            className={
                              m.status === 'invited'
                                ? 'text-brand-primary hover:underline text-xs font-semibold uppercase disabled:opacity-50'
                                : 'bg-brand-primary text-white rounded-md px-3 py-1.5 text-xs font-bold uppercase hover:bg-brand-primary-hover disabled:opacity-50'
                            }
                          >
                            {busy ? 'Sending…' : m.status === 'invited' ? 'Resend' : 'Invite to portal'}
                          </button>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
};

export default CrewRoster;
