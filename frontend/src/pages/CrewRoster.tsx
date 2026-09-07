import React, { useState, useEffect, useRef } from 'react';
import { useOrg } from '../contexts/OrgContext';
import CrewAccountLinkPanel from '../components/CrewAccountLinkPanel';
import PageHeader from '../components/ui/PageHeader';
import DataTable, { DataTableColumn } from '../components/ui/DataTable';
import Button from '../components/ui/Button';
import {
  PortalStatus,
  PORTAL_STATUS_META,
  PORTAL_STATUS_ORDER,
  portalStatusMeta,
  portalStatusDetail,
} from '../utils/portalStatus';

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
  status: PortalStatus | string;
  first_login_at?: string | null;
  invited_at?: string | null;
  shared_account?: boolean;
  shared_reason?: string | null;
}

const FILTERS = [
  { key: 'all', label: 'All' },
  ...PORTAL_STATUS_ORDER.map((k) => ({ key: k, label: PORTAL_STATUS_META[k].label })),
];

const CrewRoster: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const token = localStorage.getItem('auth_token');
  const { activeContext, isClubAdmin } = useOrg();
  const clubProfileId = activeContext?.scope_id;

  const [crew, setCrew] = useState<CrewMember[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [filter, setFilter] = useState('all');
  const [inviting, setInviting] = useState<number | null>(null);
  // Detail slide-out. `editing` is separate from `selected` so opening a crew
  // member shows their details read-only first — editing is a deliberate second
  // click, not the default state of the panel.
  const [selected, setSelected] = useState<CrewMember | null>(null);
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState({ first_name: '', last_name: '', email: '', mobile_phone: '' });
  const [saving, setSaving] = useState(false);
  const [panelError, setPanelError] = useState<string | null>(null);
  const [panelWarnings, setPanelWarnings] = useState<string[]>([]);
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

  // A crew member who already has an account cannot be "invited" — ParentInvite
  // returns already_active and sends nothing, so the old UI showed no button at
  // all for them. This is the path that actually helps: mail them a sign-in link.
  const handleLoginLink = async (m: CrewMember) => {
    setInviting(m.guardian_id);
    try {
      const res = await fetch(`${API_URL}/api/portal-access.php?action=send-login-link`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
        body: JSON.stringify({ guardian_id: m.guardian_id, club_id: clubProfileId }),
      });
      const data = await res.json();
      if (res.ok && data.success) {
        // State the expiry the backend actually used. Telling someone "sent!"
        // without it is how the 2026-08-03 invite ticket happened.
        alert(`Sign-in link sent to ${data.email}. It is valid for ${data.expires_in}.`);
      } else if (data.reason === 'no_account') {
        alert('They do not have an account yet — send them an invite instead.');
        await load();
      } else {
        alert(data.error || 'Could not send the sign-in link.');
      }
    } catch {
      alert('Could not send the sign-in link.');
    } finally {
      setInviting(null);
    }
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

  const openMember = (m: CrewMember) => {
    setSelected(m);
    setEditing(false);
    setPanelError(null);
    setPanelWarnings([]);
    setDraft({
      first_name: m.first_name || '',
      last_name: m.last_name || '',
      email: m.email || '',
      mobile_phone: m.mobile_phone || '',
    });
  };

  const closePanel = () => {
    setSelected(null);
    setEditing(false);
    setPanelError(null);
    setPanelWarnings([]);
  };

  // Esc closes the panel — a slide-out that can only be dismissed by mouse is a
  // trap for anyone working down the list from the keyboard.
  useEffect(() => {
    if (!selected) return;
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') closePanel(); };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [selected]);

  const saveContact = async () => {
    if (!selected) return;
    setSaving(true);
    setPanelError(null);
    setPanelWarnings([]);
    try {
      const res = await fetch(`${API_URL}/api/crew.php?action=update-contact`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
        body: JSON.stringify({
          club_profile_id: clubProfileId,
          guardian_id: selected.guardian_id,
          ...draft,
        }),
      });
      const data = await res.json();
      if (!res.ok || !data.success) throw new Error(data.error || 'Could not save changes');

      // Patch the row in place so the table reflects the edit immediately, then
      // reload in the background — portal status is derived from the email, so a
      // changed address can move them to a different filter bucket.
      setCrew((prev) =>
        prev.map((m) => (m.guardian_id === selected.guardian_id ? { ...m, ...data.data } : m))
      );
      setSelected((prev) => (prev ? { ...prev, ...data.data } : prev));
      setPanelWarnings(data.data?.warnings || []);
      setEditing(false);
      load();
    } catch (e: any) {
      setPanelError(e.message || 'Could not save changes.');
    } finally {
      setSaving(false);
    }
  };

  const counts: Record<string, number> = {
    total: crew.length,
    ...Object.fromEntries(
      PORTAL_STATUS_ORDER.map((k) => [k, crew.filter((m) => m.status === k).length])
    ),
  };

  const filtered = crew.filter((m) => {
    const matchesFilter = filter === 'all' || m.status === filter;
    const q = search.trim().toLowerCase();
    const matchesSearch =
      q === '' ||
      `${m.first_name} ${m.last_name}`.toLowerCase().includes(q) ||
      (m.email || '').toLowerCase().includes(q) ||
      (m.athletes || '').toLowerCase().includes(q) ||
      // Digits only, so "9256280439" finds "(925) 628-0439" — the number is
      // almost never typed the way it is stored.
      (q.replace(/\D/g, '').length >= 3 &&
        (m.mobile_phone || '').replace(/\D/g, '').includes(q.replace(/\D/g, '')));
    return matchesFilter && matchesSearch;
  });

  const inviteAll = async () => {
    // 'invite_expired' is included deliberately: a lapsed invite needs exactly the
    // same action as a missing one, and it used to be indistinguishable from
    // 'not_invited' because the badge let expired invites decay into it.
    const targets = filtered.filter(
      (m) => m.status === 'not_invited' || m.status === 'invite_expired'
    );
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

  const crewColumns: DataTableColumn<CrewMember>[] = [
    {
      key: 'name',
      header: 'Crew member',
      className: 'whitespace-nowrap',
      render: (m) => <span className="font-medium text-gray-900">{m.first_name} {m.last_name}</span>,
    },
    {
      key: 'email',
      header: 'Email',
      render: (m) => <span className="text-gray-500">{m.email || <span className="italic text-gray-400">none</span>}</span>,
    },
    {
      key: 'mobile_phone',
      header: 'Phone',
      className: 'whitespace-nowrap tabular-nums',
      render: (m) => <span className="text-gray-500">{m.mobile_phone || <span className="italic text-gray-400">none</span>}</span>,
    },
    {
      key: 'athletes',
      header: 'Athlete(s)',
      render: (m) => <span className="text-gray-500">{m.athletes}</span>,
    },
    {
      key: 'status',
      header: 'Status',
      className: 'whitespace-nowrap',
      render: (m) => {
        const meta = portalStatusMeta(m.status);
        const detail = portalStatusDetail(m);
        return (
          <>
            <span
              className={`text-xs px-2 py-0.5 rounded-full font-semibold ${meta.cls}`}
              title={meta.help}
            >
              {meta.label}
            </span>
            {/* The date IS the claim. "On the platform" without it is the
                old badge again, asserting more than we can show. */}
            {detail && (
              <div className="text-[11px] text-gray-500 mt-0.5 tabular-nums">{detail}</div>
            )}
            {m.shared_account && (
              <div className="text-[11px] text-amber-700 mt-0.5" title={m.shared_reason || ''}>
                ⚠ may be another account
              </div>
            )}
          </>
        );
      },
    },
    {
      key: 'actions',
      header: '',
      actions: true,
      render: (m) => {
        const busy = inviting === m.guardian_id;
        /* One control, four behaviours — the admin should not have
           to know which state someone is in. `active` used to
           render NOTHING, which is why a family who could not get
           in had no path: "Invite to portal" sends nothing to an
           existing account, and there was no other button. */
        if (m.status === 'no_email') return null;
        return (
          <Button
            variant={m.status === 'invited' || m.status === 'active' ? 'link' : 'primary'}
            size="sm"
            // The row opens the panel, so this must not also trigger it.
            onClick={(e) => {
              e.stopPropagation();
              if (m.status === 'active') {
                handleLoginLink(m);
              } else {
                handleInvite(m);
              }
            }}
            disabled={busy || bulk.running}
          >
            {busy
              ? 'Sending…'
              : m.status === 'active'
              ? 'Send login link'
              : m.status === 'invited' || m.status === 'invite_expired'
              ? 'Resend'
              : 'Invite to portal'}
          </Button>
        );
      },
    },
  ];

  return (
    <div className="p-6 max-w-7xl mx-auto">
      <PageHeader
        title="Crew"
        subtitle={<>Crew &amp; family across your club</>}
        className="mb-2"
        actions={
          <Button
            onClick={inviteAll}
            disabled={bulk.running || counts.not_invited + counts.invite_expired === 0}
          >
            {bulk.running
              ? `Inviting ${bulk.done} of ${bulk.total}…`
              : `Invite all not yet in (${
                  filtered.filter(
                    (m) => m.status === 'not_invited' || m.status === 'invite_expired'
                  ).length
                })`}
          </Button>
        }
      />

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
          <Button variant="link" onClick={() => { cancelRef.current = true; }}>
            Stop
          </Button>
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

      {/* Accounts that can sign in but reach no family. Admin-only, and it
          renders nothing at all when there are none. */}
      <CrewAccountLinkPanel
        clubProfileId={clubProfileId}
        isClubAdmin={isClubAdmin}
        searchPool={crew}
        onLinked={load}
      />

      {/* Search */}
      <div className="mb-5">
        <input
          type="text"
          placeholder="Search by name, email, phone, or athlete…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="w-full sm:max-w-md px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-primary focus:border-brand-primary outline-none"
        />
      </div>

      {loading ? (
        <div className="space-y-2">
          {[1, 2, 3, 4, 5].map((i) => <div key={i} className="h-12 bg-gray-100 rounded animate-pulse" />)}
        </div>
      ) : (
        <DataTable<CrewMember>
          columns={crewColumns}
          rows={filtered}
          rowKey={(m) => m.guardian_id}
          onRowClick={openMember}
          rowClassName={(m) => (selected?.guardian_id === m.guardian_id ? 'bg-brand-light' : '')}
          emptyState="No crew members match this view."
        />
      )}

      {/* ── Detail slide-out ───────────────────────────────────────────── */}
      {selected && (
        <>
          <div
            className="fixed inset-0 bg-black/30 z-40"
            onClick={closePanel}
            aria-hidden="true"
          />
          <aside
            role="dialog"
            aria-modal="true"
            aria-label={`${selected.first_name} ${selected.last_name}`}
            className="fixed inset-y-0 right-0 w-full sm:w-[420px] bg-white shadow-2xl z-50 flex flex-col border-l border-gray-200"
          >
            <header className="px-5 py-4 border-b border-gray-200 flex items-start gap-3">
              <div className="min-w-0">
                <h2 className="text-lg font-bold text-brand-primary truncate">
                  {selected.first_name} {selected.last_name}
                </h2>
                <p className="text-xs text-gray-500 mt-0.5 truncate">
                  Crew for {selected.athletes || '—'}
                </p>
              </div>
              <Button
                variant="ghost"
                size="icon"
                onClick={closePanel}
                aria-label="Close"
                className="ml-auto text-xl"
              >
                ×
              </Button>
            </header>

            <div className="flex-1 overflow-y-auto px-5 py-4 space-y-5">
              {panelError && (
                <div className="p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm">
                  {panelError}
                </div>
              )}
              {panelWarnings.map((w, i) => (
                <div key={i} className="p-3 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg text-sm">
                  {w}
                </div>
              ))}

              {!editing ? (
                <>
                  <dl className="space-y-4">
                    <div>
                      <dt className="text-xs uppercase tracking-wide text-gray-400 font-semibold">Email</dt>
                      <dd className="text-sm text-gray-900 mt-1 break-all">
                        {selected.email || <span className="italic text-gray-400">none on file</span>}
                      </dd>
                    </div>
                    <div>
                      <dt className="text-xs uppercase tracking-wide text-gray-400 font-semibold">Mobile phone</dt>
                      <dd className="text-sm text-gray-900 mt-1">
                        {selected.mobile_phone || <span className="italic text-gray-400">none on file</span>}
                      </dd>
                    </div>
                    <div>
                      <dt className="text-xs uppercase tracking-wide text-gray-400 font-semibold">Athletes</dt>
                      <dd className="text-sm text-gray-900 mt-1">{selected.athletes || '—'}</dd>
                    </div>
                    <div>
                      <dt className="text-xs uppercase tracking-wide text-gray-400 font-semibold">Parent portal</dt>
                      <dd className="mt-1">
                        <span className={`text-xs px-2 py-0.5 rounded-full font-semibold ${
                          portalStatusMeta(selected.status).cls
                        }`}>
                          {portalStatusMeta(selected.status).label}
                        </span>
                      </dd>
                    </div>
                  </dl>

                  <Button fullWidth onClick={() => setEditing(true)}>
                    Edit details
                  </Button>
                </>
              ) : (
                <form
                  onSubmit={(e) => { e.preventDefault(); saveContact(); }}
                  className="space-y-4"
                >
                  <div className="grid grid-cols-2 gap-3">
                    <label className="block">
                      <span className="text-xs uppercase tracking-wide text-gray-500 font-semibold">First name</span>
                      <input
                        required
                        value={draft.first_name}
                        onChange={(e) => setDraft({ ...draft, first_name: e.target.value })}
                        className="mt-1 w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-primary focus:border-brand-primary outline-none"
                      />
                    </label>
                    <label className="block">
                      <span className="text-xs uppercase tracking-wide text-gray-500 font-semibold">Last name</span>
                      <input
                        required
                        value={draft.last_name}
                        onChange={(e) => setDraft({ ...draft, last_name: e.target.value })}
                        className="mt-1 w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-primary focus:border-brand-primary outline-none"
                      />
                    </label>
                  </div>

                  <label className="block">
                    <span className="text-xs uppercase tracking-wide text-gray-500 font-semibold">Email</span>
                    <input
                      type="email"
                      value={draft.email}
                      onChange={(e) => setDraft({ ...draft, email: e.target.value })}
                      className="mt-1 w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-primary focus:border-brand-primary outline-none"
                    />
                    <span className="block text-xs text-gray-400 mt-1">
                      Their portal account is matched on this address — changing it may need a fresh invite.
                    </span>
                  </label>

                  <label className="block">
                    <span className="text-xs uppercase tracking-wide text-gray-500 font-semibold">Mobile phone</span>
                    <input
                      type="tel"
                      value={draft.mobile_phone}
                      onChange={(e) => setDraft({ ...draft, mobile_phone: e.target.value })}
                      className="mt-1 w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-primary focus:border-brand-primary outline-none"
                    />
                    <span className="block text-xs text-gray-400 mt-1">
                      Used for club texts. Include the area code.
                    </span>
                  </label>

                  <div className="flex gap-2 pt-1">
                    <Button type="submit" loading={saving} className="flex-1">
                      Save
                    </Button>
                    <Button
                      variant="secondary"
                      disabled={saving}
                      onClick={() => { setEditing(false); setPanelError(null); }}
                    >
                      Cancel
                    </Button>
                  </div>
                </form>
              )}
            </div>
          </aside>
        </>
      )}
    </div>
  );
};

export default CrewRoster;
