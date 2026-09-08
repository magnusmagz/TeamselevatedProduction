import { REFEREE_GRADE_OPTIONS } from '../constants/refereeGrades';
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import PageHeader from '../components/ui/PageHeader';
import Button from '../components/ui/Button';
import { useAuth } from '../contexts/AuthContext';
import { formatDateOnly } from '../utils/dateFormat';
import { eventWhere } from '../utils/eventWhere';
import { RefereeClub, RefereeGame, GAME_REFEREE_ROLE_LABEL, GameRefereeRole, GAME_REFEREE_ROLES } from '../components/referee/refereeTypes';

/**
 * /referee — the signed-in referee's page (Maggie, 2026-09-08).
 *
 * Their games ARE the page: open games they can take, then upcoming games,
 * past games collapsed, and their own contact card below. Built off the USER
 * ID on the server (`api/referees.php?action=my-games` / `open-games`), never
 * the token's active club — a referee holds the role in several clubs and the
 * Clubs strip at the top filters across all of them. There is no nav to
 * anything else: a referee-only account never sees the staff app or the parent
 * portal (App.tsx hides the chrome on this route).
 *
 * Grade is editable here (Maggie, 2026-09-08) and applies to every club's row.
 */
const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

interface Profile {
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
}

// "Venue · Field" when the game has a pitch, then the free-text location —
// a referee reads directions here, so both are kept.
function whereLine(g: RefereeGame): string {
  const place = eventWhere({ venue_name: g.venue_name, field_name: g.field_name });
  const parts = [place, g.location].filter((p) => p && p.trim() !== '');
  return parts.length ? parts.join(' · ') : 'Location to be confirmed';
}

// The full street address, so a referee can be there on time (Maggie,
// 2026-09-08). Returns null when the venue has no address on file.
export function addressLine(g: RefereeGame): string | null {
  const street = (g.venue_address || '').trim();
  const cityState = [g.venue_city, g.venue_state].filter((p) => p && p.trim() !== '').join(', ');
  const tail = [cityState, g.venue_zip].filter((p) => p && String(p).trim() !== '').join(' ');
  const line = [street, tail].filter((p) => p !== '').join(', ');
  return line !== '' ? line : null;
}

export function directionsUrl(g: RefereeGame): string | null {
  if (g.venue_map_url && g.venue_map_url.trim() !== '') return g.venue_map_url;
  const addr = addressLine(g);
  const q = addr ?? (g.venue_name || '');
  return q ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(q)}` : null;
}

function timeLine(g: RefereeGame): string {
  if (g.start_time && g.end_time) return `${g.start_time} – ${g.end_time}`;
  return g.start_time || 'Time to be confirmed';
}

function teamsLine(g: RefereeGame): string {
  const names = g.teams.map((t) => t.name);
  if (g.opponent_name) names.push(`vs ${g.opponent_name}`);
  return names.join(' ');
}

const RefereeHome: React.FC = () => {
  const { user, logout } = useAuth();
  const token = localStorage.getItem('auth_token');
  const headers = useMemo(
    () => ({ 'Content-Type': 'application/json', Authorization: `Bearer ${token}` }),
    [token]
  );

  const [clubs, setClubs] = useState<RefereeClub[]>([]);
  const [upcoming, setUpcoming] = useState<RefereeGame[]>([]);
  const [past, setPast] = useState<RefereeGame[]>([]);
  const [open, setOpen] = useState<RefereeGame[]>([]);
  const [available, setAvailable] = useState(true);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [clubFilter, setClubFilter] = useState<number | null>(null);
  const [showPast, setShowPast] = useState(false);
  const [busyGame, setBusyGame] = useState<number | null>(null);
  const [roleChoice, setRoleChoice] = useState<Record<number, GameRefereeRole>>({});
  const [notice, setNotice] = useState<string | null>(null);

  const [profile, setProfile] = useState<Profile | null>(null);
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState<Profile | null>(null);
  const [gradeDraft, setGradeDraft] = useState<string>('');
  const [savingProfile, setSavingProfile] = useState(false);
  const [profileError, setProfileError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setLoadError(null);
    try {
      const [mine, openRes] = await Promise.all([
        fetch(`${API_URL}/api/referees.php?action=my-games`, { headers }),
        fetch(`${API_URL}/api/referees.php?action=open-games`, { headers }),
      ]);
      const mineData = await mine.json();
      const openData = await openRes.json();
      if (!mine.ok || !mineData?.success) {
        setLoadError(mineData?.error || `Could not load your games (${mine.status})`);
        return;
      }
      setAvailable(mineData.available !== false);
      setClubs(Array.isArray(mineData.clubs) ? mineData.clubs : []);
      setUpcoming(Array.isArray(mineData.upcoming) ? mineData.upcoming : []);
      setPast(Array.isArray(mineData.past) ? mineData.past : []);
      setOpen(openRes.ok && openData?.success && Array.isArray(openData.games) ? openData.games : []);
    } catch (err: any) {
      setLoadError(err?.message || 'Could not load your games');
    } finally {
      setLoading(false);
    }
  }, [headers]);

  const loadProfile = useCallback(async () => {
    try {
      const res = await fetch(`${API_URL}/api/user-profile.php`, { headers });
      const data = await res.json();
      if (res.ok && data?.success && data.user) {
        setProfile({
          first_name: data.user.first_name ?? '',
          last_name: data.user.last_name ?? '',
          email: data.user.email ?? '',
          phone: data.user.phone ?? '',
        });
      }
    } catch {
      /* the card shows the token's name instead */
    }
  }, [headers]);

  useEffect(() => {
    load();
    loadProfile();
  }, [load, loadProfile]);

  const inFilter = (g: RefereeGame) => clubFilter === null || g.club_id === clubFilter;
  const visibleOpen = open.filter(inFilter);
  const visibleUpcoming = upcoming.filter(inFilter);
  const visiblePast = past.filter(inFilter);

  const claim = async (g: RefereeGame) => {
    setBusyGame(g.id);
    setNotice(null);
    try {
      const role = roleChoice[g.id] ?? (g.open_roles?.includes('center') ? 'center' : (g.open_roles?.[0] as GameRefereeRole) ?? 'referee');
      const res = await fetch(`${API_URL}/api/referees.php?action=claim`, {
        method: 'POST', headers, body: JSON.stringify({ event_id: g.id, role }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data?.success) {
        setNotice(data?.error || `Could not take this game (${res.status})`);
        return;
      }
      setNotice(`You are on ${g.name} (${formatDateOnly(g.event_date)}).`);
      await load();
    } finally {
      setBusyGame(null);
    }
  };

  const release = async (g: RefereeGame) => {
    setBusyGame(g.id);
    setNotice(null);
    try {
      const res = await fetch(`${API_URL}/api/referees.php?action=release`, {
        method: 'POST', headers, body: JSON.stringify({ event_id: g.id }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data?.success) {
        setNotice(data?.error || `Could not release this game (${res.status})`);
        return;
      }
      setNotice(`Released ${g.name}.`);
      await load();
    } finally {
      setBusyGame(null);
    }
  };

  const saveProfile = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!draft) return;
    setSavingProfile(true);
    setProfileError(null);
    try {
      const res = await fetch(`${API_URL}/api/user-profile.php`, {
        method: 'PUT', headers,
        body: JSON.stringify({ first_name: draft.first_name, last_name: draft.last_name, email: draft.email, phone: draft.phone }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data?.success) {
        setProfileError(data?.error || `Could not save (${res.status})`);
        return;
      }
      const currentGrade = clubs.find((c) => c.grade)?.grade ?? '';
      if (clubs.length > 0 && gradeDraft !== currentGrade) {
        const gr = await fetch(`${API_URL}/api/referees.php?action=set-my-grade`, {
          method: 'POST', headers, body: JSON.stringify({ grade: gradeDraft }),
        });
        const gdata = await gr.json().catch(() => ({}));
        if (!gr.ok || !gdata?.success) {
          setProfileError(gdata?.error || `Could not save grade (${gr.status})`);
          return;
        }
        setClubs((prev) => prev.map((c) => ({ ...c, grade: gradeDraft || null })));
      }
      setProfile(draft);
      setEditing(false);
    } catch (err: any) {
      setProfileError(err?.message || 'Could not save');
    } finally {
      setSavingProfile(false);
    }
  };

  const gameCard = (g: RefereeGame, kind: 'open' | 'upcoming' | 'past') => (
    <li
      key={`${kind}-${g.id}`}
      className={`border border-brand-secondary rounded-md p-4 bg-white ${kind === 'open' && g.conflict ? 'opacity-60' : ''}`}
      data-testid={`${kind}-game-${g.id}`}
      aria-disabled={kind === 'open' && g.conflict ? true : undefined}
    >
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <div className="text-sm text-gray-500">
            <span className="inline-block w-2 h-2 rounded-full mr-1 align-middle" style={{ backgroundColor: g.primary_color || '#9ca3af' }} />
            {g.club_name || 'Club'}
          </div>
          <div className="font-semibold text-brand-primary">{g.name}</div>
          <div className="text-sm text-gray-800">{formatDateOnly(g.event_date)} · {timeLine(g)}</div>
          <div className="text-sm text-gray-600">{whereLine(g)}</div>
          {(addressLine(g) || directionsUrl(g)) && (
            <div className="text-sm text-gray-600" data-testid="game-address">
              {addressLine(g) ?? 'Address not on file'}
              {directionsUrl(g) && (
                <>
                  {' · '}
                  <a href={directionsUrl(g)!} target="_blank" rel="noopener noreferrer" className="underline text-brand-primary" onClick={(e) => e.stopPropagation()}>Directions</a>
                </>
              )}
            </div>
          )}
          {teamsLine(g) && <div className="text-sm text-gray-600">{teamsLine(g)}</div>}
          {kind !== 'open' && g.role && (
            <div className="text-xs text-gray-500 mt-1">
              Your role: {GAME_REFEREE_ROLE_LABEL[g.role as GameRefereeRole] ?? g.role}
              {g.self_assigned && <span className="ml-2 inline-block px-1.5 py-0.5 rounded bg-sky-100 text-sky-800">You claimed this</span>}
            </div>
          )}
          {kind === 'open' && (
            <div className="text-xs text-gray-500 mt-1">
              {g.min_referee_grade ? `Minimum grade: ${g.min_referee_grade}. ` : ''}
              {g.referees && g.referees.length > 0
                ? `Already on it: ${g.referees.map((r) => `${r.name} (${GAME_REFEREE_ROLE_LABEL[r.role as GameRefereeRole] ?? r.role})`).join(', ')}`
                : 'Nobody assigned yet.'}
            </div>
          )}
          {kind === 'open' && g.conflict && (
            <div className="text-xs text-amber-900 mt-1" data-testid={`conflict-${g.id}`}>{g.conflict_reason || 'Overlaps a game you are already on.'}</div>
          )}
        </div>
        {kind === 'open' && (
          <div className="flex items-center gap-2">
            <select
              aria-label={`Role for ${g.name}`}
              value={roleChoice[g.id] ?? (g.open_roles?.includes('center') ? 'center' : (g.open_roles?.[0] ?? 'referee'))}
              onChange={(e) => setRoleChoice({ ...roleChoice, [g.id]: e.target.value as GameRefereeRole })}
              className="bg-white text-brand-primary border border-brand-secondary rounded-md px-2 py-1 text-sm"
            >
              {(g.open_roles && g.open_roles.length ? g.open_roles : GAME_REFEREE_ROLES).map((r) => (
                <option key={r} value={r}>{GAME_REFEREE_ROLE_LABEL[r as GameRefereeRole] ?? r}</option>
              ))}
            </select>
            <Button size="sm" loading={busyGame === g.id} disabled={Boolean(g.conflict)} onClick={() => claim(g)}>Take this game</Button>
          </div>
        )}
        {kind === 'upcoming' && g.self_assigned && (
          <Button variant="danger-link" size="sm" disabled={busyGame === g.id} onClick={() => release(g)}>Release</Button>
        )}
      </div>
    </li>
  );

  const displayName = profile ? `${profile.first_name} ${profile.last_name}`.trim() : (user?.name ?? '');

  return (
    <main className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <PageHeader
        title="My Games"
        subtitle={displayName ? `Signed in as ${displayName}` : undefined}
        actions={<Button variant="secondary" size="sm" onClick={() => logout()}>Sign out</Button>}
      />

      {clubs.length > 0 && (
        <div className="mb-6 flex flex-wrap gap-2" data-testid="clubs-strip">
          <label className="flex items-center gap-2 text-sm text-gray-700">
            <span>Club</span>
            <select
              aria-label="Filter by club"
              value={clubFilter ?? ''}
              onChange={(e) => setClubFilter(e.target.value === '' ? null : Number(e.target.value))}
              className="bg-white text-brand-primary border border-brand-secondary rounded-md px-2 py-1 text-sm"
            >
              <option value="">All clubs</option>
              {clubs.map((c) => (
                <option key={c.club_id} value={c.club_id}>{c.club_name || `Club ${c.club_id}`}</option>
              ))}
            </select>
          </label>
          {clubs.map((c) => (
            <span key={c.club_id} className="inline-flex items-center gap-1.5 px-2 py-1 rounded-full border border-brand-secondary text-xs text-gray-700" data-testid={`club-chip-${c.club_id}`}>
              <span className="inline-block w-2 h-2 rounded-full" style={{ backgroundColor: c.primary_color || '#9ca3af' }} />
              {c.club_name || `Club ${c.club_id}`}
              <span className="text-gray-500">· grade {c.grade || 'not set'}</span>
            </span>
          ))}
        </div>
      )}

      {notice && (
        <div className="mb-4 bg-brand-light/40 border border-brand-secondary rounded-md p-3 text-sm text-brand-primary" role="status">{notice}</div>
      )}
      {loadError && <div className="mb-4 bg-red-50 border border-red-200 rounded-md p-3 text-sm text-red-800">{loadError}</div>}
      {loading && <p className="text-sm text-gray-500">Loading…</p>}

      {!loading && !loadError && !available && (
        <div className="mb-6 bg-amber-50 border border-amber-200 rounded-md p-3 text-sm text-amber-900">
          Referee games are not switched on yet. Your club will let you know.
        </div>
      )}

      {!loading && !loadError && available && clubs.length === 0 && (
        <div className="mb-6 border border-brand-secondary rounded-md p-6 bg-white text-center" data-testid="not-connected">
          <p className="text-brand-primary font-semibold">No club has connected you yet</p>
          <p className="text-sm text-gray-600 mt-1">Ask your club admin to add you to their referee directory using this email address.</p>
        </div>
      )}

      {!loading && !loadError && available && clubs.length > 0 && (
        <>
          <section className="mb-8">
            <h2 className="text-sm font-semibold text-brand-primary uppercase tracking-wide mb-2">Open games</h2>
            {visibleOpen.length === 0 ? (
              <p className="text-sm text-gray-500" data-testid="open-empty">No open games right now.</p>
            ) : (
              <ul className="space-y-3">{visibleOpen.map((g) => gameCard(g, 'open'))}</ul>
            )}
          </section>

          <section className="mb-8">
            <h2 className="text-sm font-semibold text-brand-primary uppercase tracking-wide mb-2">My games</h2>
            {visibleUpcoming.length === 0 ? (
              <p className="text-sm text-gray-500" data-testid="upcoming-empty">No games assigned yet — your club will let you know.</p>
            ) : (
              <ul className="space-y-3">{visibleUpcoming.map((g) => gameCard(g, 'upcoming'))}</ul>
            )}
          </section>

          <section className="mb-8">
            <Button variant="link" size="sm" onClick={() => setShowPast(!showPast)} aria-expanded={showPast}>
              {showPast ? 'Hide past games' : `Past games (${visiblePast.length})`}
            </Button>
            {showPast && (
              visiblePast.length === 0
                ? <p className="text-sm text-gray-500 mt-2">No past games.</p>
                : <ul className="space-y-3 mt-2">{visiblePast.map((g) => gameCard(g, 'past'))}</ul>
            )}
          </section>
        </>
      )}

      <section className="border border-brand-secondary rounded-md p-4 bg-white" data-testid="contact-card">
        <div className="flex items-center justify-between mb-2">
          <h2 className="text-sm font-semibold text-brand-primary uppercase tracking-wide">Your details</h2>
          {profile && !editing && (
            <Button variant="link" size="sm" onClick={() => { setDraft(profile); setGradeDraft(clubs.find((c) => c.grade)?.grade ?? ''); setProfileError(null); setEditing(true); }}>Edit</Button>
          )}
        </div>
        {!editing && (
          <dl className="text-sm text-gray-800 space-y-1">
            <div><dt className="inline text-gray-500">Name: </dt><dd className="inline">{displayName || '—'}</dd></div>
            <div><dt className="inline text-gray-500">Email: </dt><dd className="inline">{profile?.email || user?.email || '—'}</dd></div>
            <div><dt className="inline text-gray-500">Phone: </dt><dd className="inline">{profile?.phone || '—'}</dd></div>
            {clubs.map((c) => (
              <div key={c.club_id}><dt className="inline text-gray-500">Grade at {c.club_name || `club ${c.club_id}`}: </dt><dd className="inline">{c.grade || 'not set'}</dd></div>
            ))}
          </dl>
        )}
        {editing && draft && (
          <form onSubmit={saveProfile} className="space-y-3">
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label htmlFor="me-first" className="block text-xs text-gray-600">First name</label>
                <input id="me-first" type="text" required value={draft.first_name} onChange={(e) => setDraft({ ...draft, first_name: e.target.value })}
                  className="mt-1 block w-full border border-brand-secondary rounded-md px-3 py-2 text-sm" />
              </div>
              <div>
                <label htmlFor="me-last" className="block text-xs text-gray-600">Last name</label>
                <input id="me-last" type="text" required value={draft.last_name} onChange={(e) => setDraft({ ...draft, last_name: e.target.value })}
                  className="mt-1 block w-full border border-brand-secondary rounded-md px-3 py-2 text-sm" />
              </div>
            </div>
            <div>
              <label htmlFor="me-email" className="block text-xs text-gray-600">Email</label>
              <input id="me-email" type="email" required value={draft.email} onChange={(e) => setDraft({ ...draft, email: e.target.value })}
                className="mt-1 block w-full border border-brand-secondary rounded-md px-3 py-2 text-sm" />
            </div>
            <div>
              <label htmlFor="me-phone" className="block text-xs text-gray-600">Phone</label>
              <input id="me-phone" type="tel" value={draft.phone} onChange={(e) => setDraft({ ...draft, phone: e.target.value })}
                className="mt-1 block w-full border border-brand-secondary rounded-md px-3 py-2 text-sm" />
            </div>
            {clubs.length > 0 && (
              <div>
                <label htmlFor="me-grade" className="block text-xs text-gray-600">Grade</label>
                <select id="me-grade" value={gradeDraft} onChange={(e) => setGradeDraft(e.target.value)}
                  className="mt-1 block w-full border border-brand-secondary rounded-md px-3 py-2 text-sm bg-white">
                  <option value="">Not set</option>
                  <optgroup label="US Soccer">
                    {REFEREE_GRADE_OPTIONS.filter((o) => o.group === 'current').map((o) => (
                      <option key={o.value} value={o.value}>{o.label}</option>
                    ))}
                  </optgroup>
                  <optgroup label="Older scale">
                    {REFEREE_GRADE_OPTIONS.filter((o) => o.group !== 'current').map((o) => (
                      <option key={o.value} value={o.value}>{o.label}</option>
                    ))}
                  </optgroup>
                </select>
                <p className="mt-1 text-xs text-gray-500">Applies to every club you referee for. Games with a minimum grade use it.</p>
              </div>
            )}
            {profileError && <p className="text-sm text-red-700" role="alert">{profileError}</p>}
            <div className="flex justify-end gap-2">
              <Button variant="secondary" size="sm" onClick={() => setEditing(false)}>Cancel</Button>
              <Button type="submit" size="sm" loading={savingProfile}>Save</Button>
            </div>
          </form>
        )}
      </section>
    </main>
  );
};

export default RefereeHome;
