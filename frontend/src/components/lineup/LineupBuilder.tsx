import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  BENCH, FIELD_PLAYERS, FIELD_SIZES, FieldSize, FormationSlot, defaultFormation, formationsFor, isFieldSize, slotsFor,
} from '../../utils/lineupFormations';
import { formatDateOnly } from '../../utils/dateFormat';
import LineupPitch from './LineupPitch';
import Button from '../ui/Button';
import { LineupRow, LineupStaffResponse, PitchPlayer, RosterPlayer } from './types';

/**
 * The coach's lineup screen (CKU R67, slice 8.5). Mobile-first: this is used
 * pitch-side. Tap a slot, tap a player, done; tap two occupied slots to swap.
 *
 * Everything it decides, the server decides again (lib/lineups.php): a
 * placement the server refuses comes back as a sentence, never a silent drop.
 * The screen keeps three lists — the field (slot → athlete), the bench (on the
 * sheet, in order) and the rest of the roster ("not dressed") — and saves the
 * first two.
 */

interface Props {
  teamId: number;
  eventId: number | null;
  apiUrl: string;
  /** Where the Print button goes. Rendered as a plain link so a new tab works. */
  printHref?: string;
}

type Notice = { kind: 'info' | 'error' | 'warn'; text: string } | null;

const attendanceWord: Record<string, string> = {
  absent: 'absent', excused: 'excused', late: 'late', present: 'here',
};

function fromLineup(lineup: LineupRow | null, roster: RosterPlayer[], attendance: Record<string, string>) {
  const field: Record<string, number> = {};
  const bench: number[] = [];
  if (lineup) {
    for (const s of lineup.slots) {
      if (s.slot === BENCH) bench.push(s.athlete_id);
      else field[s.slot] = s.athlete_id;
    }
  } else {
    // A fresh sheet: everyone who is here and fit starts on the bench.
    for (const p of roster) {
      const a = attendance[String(p.athlete_id)];
      if (p.status === 'active' && a !== 'absent' && a !== 'excused') bench.push(p.athlete_id);
    }
  }
  const captain = lineup?.slots.find((s) => s.captain)?.athlete_id ?? null;
  const notes: Record<number, string> = {};
  lineup?.slots.forEach((s) => { if (s.note) notes[s.athlete_id] = s.note; });
  return { field, bench, captain, notes };
}

const LineupBuilder: React.FC<Props> = ({ teamId, eventId, apiUrl, printHref }) => {
  const token = localStorage.getItem('auth_token');
  const headers = useMemo(() => ({
    'Content-Type': 'application/json',
    Authorization: `Bearer ${token}`,
  }), [token]);

  const [data, setData] = useState<LineupStaffResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<Notice>(null);
  const [warnings, setWarnings] = useState<string[]>([]);

  const [fieldSize, setFieldSize] = useState<FieldSize>('11v11');
  const [formation, setFormation] = useState<string>('4-3-3');
  const [field, setField] = useState<Record<string, number>>({});
  const [bench, setBench] = useState<number[]>([]);
  const [captain, setCaptain] = useState<number | null>(null);
  const [notes, setNotes] = useState<Record<number, string>>({});
  const [selectedSlot, setSelectedSlot] = useState<string | null>(null);
  const [selectedPlayer, setSelectedPlayer] = useState<number | null>(null);
  const [dirty, setDirty] = useState(false);

  const query = `team_id=${teamId}${eventId ? `&event_id=${eventId}` : ''}`;

  const applyLineup = useCallback((d: LineupStaffResponse, lineup: LineupRow | null) => {
    const size = lineup?.field_size ?? d.team.field_size;
    setFieldSize(size);
    setFormation(lineup?.formation ?? defaultFormation(size));
    const s = fromLineup(lineup, d.roster, d.attendance);
    setField(s.field);
    setBench(s.bench);
    setCaptain(s.captain);
    setNotes(s.notes);
    setSelectedSlot(null);
    setSelectedPlayer(null);
    setDirty(false);
  }, []);

  const load = useCallback(async () => {
    setLoading(true);
    setLoadError(null);
    try {
      const res = await fetch(`${apiUrl}/api/lineups.php?action=get&${query}`, { headers });
      const body = await res.json();
      if (!res.ok || !body?.success) {
        setLoadError(body?.error || `Could not load the lineup (${res.status})`);
        return;
      }
      if (!body.can_edit) {
        setLoadError('Only a coach of this team, or a club admin, can set its lineup');
        return;
      }
      const d = body as LineupStaffResponse;
      setData(d);
      applyLineup(d, d.lineup);
    } catch (e) {
      setLoadError('Could not load the lineup');
    } finally {
      setLoading(false);
    }
  }, [apiUrl, query, headers, applyLineup]);

  useEffect(() => { load(); }, [load]);

  const roster = useMemo(() => data?.roster ?? [], [data]);
  const byId = useMemo(() => {
    const m: Record<number, RosterPlayer> = {};
    roster.forEach((p) => { m[p.athlete_id] = p; });
    return m;
  }, [roster]);
  const attendance = useMemo(() => data?.attendance ?? {}, [data]);
  const slots = useMemo(() => slotsFor(fieldSize, formation) ?? [], [fieldSize, formation]);
  const fieldPlayers = FIELD_PLAYERS[fieldSize];
  const onFieldCount = Object.keys(field).length;
  const emptySlots = slots.filter((s) => field[s.slot] === undefined);

  const pitchPlayers = useMemo(() => {
    const out: Record<string, PitchPlayer | undefined> = {};
    for (const [slot, id] of Object.entries(field)) {
      const p = byId[id];
      if (!p) continue;
      out[slot] = {
        athlete_id: id, name: p.name, last_name: p.last_name, jersey_number: p.jersey_number,
        captain: captain === id, status: p.status, attendance: attendance[String(id)] as PitchPlayer['attendance'],
      };
    }
    return out;
  }, [field, byId, captain, attendance]);

  const onField = new Set(Object.values(field));
  const benchPlayers = bench.map((id) => byId[id]).filter(Boolean) as RosterPlayer[];
  const notDressed = roster.filter((p) => !onField.has(p.athlete_id) && !bench.includes(p.athlete_id));

  const removeFromBench = (id: number) => setBench((b) => b.filter((x) => x !== id));
  const toBench = (id: number) => setBench((b) => (b.includes(id) ? b : [...b, id]));

  /** Put a player in a slot; whoever was there goes to the bench. */
  const place = (slot: string, athleteId: number) => {
    const next = { ...field };
    const occupant = next[slot];
    // The player may be coming from another slot — vacate it.
    for (const [s, id] of Object.entries(next)) if (id === athleteId) delete next[s];
    next[slot] = athleteId;
    setField(next);
    setBench((b) => {
      const nb = b.filter((x) => x !== athleteId);
      return occupant !== undefined && occupant !== athleteId && !nb.includes(occupant) ? [...nb, occupant] : nb;
    });
    setSelectedSlot(null);
    setSelectedPlayer(null);
    setDirty(true);
    setNotice(null);
  };

  const clearSlot = (slot: string) => {
    const id = field[slot];
    if (id === undefined) return;
    setField((f) => { const n = { ...f }; delete n[slot]; return n; });
    toBench(id);
    setSelectedSlot(null);
    setDirty(true);
  };

  const handleSlotTap = (s: FormationSlot) => {
    const occupant = field[s.slot];
    if (selectedPlayer !== null) { place(s.slot, selectedPlayer); return; }
    if (selectedSlot === null) { setSelectedSlot(s.slot); return; }
    if (selectedSlot === s.slot) { setSelectedSlot(null); return; }
    const other = field[selectedSlot];
    if (other === undefined && occupant === undefined) { setSelectedSlot(s.slot); return; }
    // Swap (or move) between two slots.
    setField((f) => {
      const n = { ...f };
      if (other !== undefined) n[s.slot] = other; else delete n[s.slot];
      if (occupant !== undefined) n[selectedSlot] = occupant; else delete n[selectedSlot];
      return n;
    });
    setSelectedSlot(null);
    setDirty(true);
  };

  const handlePlayerTap = (id: number) => {
    if (selectedSlot !== null) { place(selectedSlot, id); return; }
    if (selectedPlayer === id) { setSelectedPlayer(null); setNotice(null); return; }
    if (emptySlots.length === 0) {
      setSelectedPlayer(id);
      setNotice({ kind: 'info', text: `All ${fieldPlayers} field slots are filled — tap a player on the pitch to swap ${byId[id]?.name ?? 'them'} in.` });
      return;
    }
    setSelectedPlayer(id);
    setNotice({ kind: 'info', text: `Tap a slot for ${byId[id]?.name ?? 'this player'}.` });
  };

  const changeFormation = (next: string) => {
    const keep = new Set((slotsFor(fieldSize, next) ?? []).map((s) => s.slot));
    const n: Record<string, number> = {};
    const displaced: number[] = [];
    for (const [slot, id] of Object.entries(field)) {
      if (keep.has(slot)) n[slot] = id; else displaced.push(id);
    }
    setField(n);
    setBench((b) => [...b, ...displaced.filter((id) => !b.includes(id))]);
    setFormation(next);
    setSelectedSlot(null);
    setDirty(true);
  };

  const changeFieldSize = (next: FieldSize) => {
    if (next === fieldSize) return;
    const displaced = Object.values(field);
    setFieldSize(next);
    setFormation(formationsFor(next)[0]);
    setField({});
    setBench((b) => [...b, ...displaced.filter((id) => !b.includes(id))]);
    setDirty(true);
  };

  const payloadSlots = () => [
    ...Object.entries(field).map(([slot, athlete_id]) => ({
      athlete_id, slot, captain: captain === athlete_id, note: notes[athlete_id] || null,
    })),
    ...bench.map((athlete_id, i) => ({
      athlete_id, slot: BENCH, sort_order: i + 1, captain: captain === athlete_id, note: notes[athlete_id] || null,
    })),
  ];

  const post = async (action: string, body: Record<string, unknown>) => {
    setBusy(true);
    setNotice(null);
    try {
      const res = await fetch(`${apiUrl}/api/lineups.php?action=${action}`, {
        method: 'POST', headers, body: JSON.stringify({ team_id: teamId, ...body }),
      });
      const json = await res.json();
      if (!res.ok || !json?.success) {
        setNotice({ kind: 'error', text: json?.error || `Could not ${action} (${res.status})` });
        return null;
      }
      setWarnings(Array.isArray(json.warnings) ? json.warnings : []);
      return json;
    } catch (e) {
      setNotice({ kind: 'error', text: `Could not ${action}` });
      return null;
    } finally {
      setBusy(false);
    }
  };

  const save = async (asTemplate: boolean) => {
    const body: Record<string, unknown> = { formation, field_size: fieldSize, slots: payloadSlots() };
    if (!asTemplate && eventId) body.event_id = eventId;
    const json = await post('save', body);
    if (!json || !data) return;
    if (asTemplate) {
      setData({ ...data, has_template: true });
      setNotice({ kind: 'info', text: 'Saved as this team\'s default lineup.' });
      if (!eventId) applyLineup(data, json.lineup);
    } else {
      setData({ ...data, lineup: json.lineup, is_template: false });
      applyLineup(data, json.lineup);
      setNotice({ kind: 'info', text: 'Lineup saved.' });
    }
  };

  const copyFrom = async (source: 'template' | 'last') => {
    if (!eventId || !data) return;
    const json = await post('copy-from', { event_id: eventId, source });
    if (!json) return;
    setData({ ...data, lineup: json.lineup, is_template: false });
    applyLineup(data, json.lineup);
    setNotice({ kind: 'info', text: source === 'template' ? 'Default lineup loaded and saved for this game.' : `Copied from ${json.copied_from_event?.opponent_name ? `vs ${json.copied_from_event.opponent_name}` : 'the last game'}.` });
  };

  const publish = async (on: boolean) => {
    if (!eventId || !data) return;
    const json = await post(on ? 'publish' : 'unpublish', { event_id: eventId });
    if (!json) return;
    setData({ ...data, lineup: json.lineup, is_template: false });
    setNotice({ kind: 'info', text: on ? 'Published — families can see this lineup on the game.' : 'Unpublished.' });
  };

  // ---------------------------------------------------------------- render

  if (loading) {
    return <div className="py-12 text-center text-brand-primary">Loading lineup…</div>;
  }
  if (loadError || !data) {
    return <div className="m-4 rounded-lg bg-red-50 px-4 py-3 text-red-700">{loadError || 'Could not load the lineup'}</div>;
  }

  const savedForGame = Boolean(eventId && data.lineup && !data.is_template);
  const published = savedForGame && Boolean(data.lineup?.published_at);
  const title = data.event
    ? `${data.event.opponent_name ? `vs ${data.event.opponent_name}` : data.event.name} · ${formatDateOnly(data.event.event_date, { month: 'short', day: 'numeric' })}`
    : 'Default lineup';

  const playerChip = (p: RosterPlayer, onSheet: boolean) => {
    const att = attendance[String(p.athlete_id)];
    const out = att === 'absent' || att === 'excused';
    const selected = selectedPlayer === p.athlete_id;
    return (
      <div key={p.athlete_id} className="flex items-center gap-1" data-testid={`player-${p.athlete_id}`}>
        <button
          type="button"
          onClick={() => handlePlayerTap(p.athlete_id)}
          aria-pressed={selected}
          aria-label={`${p.name}${p.jersey_number != null ? ` #${p.jersey_number}` : ''}${out ? `, ${attendanceWord[att]}` : ''}`}
          className={`flex min-h-[44px] flex-1 items-center gap-2 rounded-lg border px-3 py-2 text-left text-sm ${
            selected ? 'border-yellow-400 bg-yellow-50' : 'border-gray-200 bg-white'
          } ${out ? 'opacity-50' : ''}`}
        >
          <span className="w-7 shrink-0 text-center font-bold text-brand-primary">{p.jersey_number ?? '–'}</span>
          <span className="flex-1 truncate">
            {p.name}
            {captain === p.athlete_id && <span className="ml-1 text-xs font-bold text-yellow-600">C</span>}
          </span>
          {p.status !== 'active' && (
            <span className="rounded bg-red-100 px-1.5 py-0.5 text-xs font-semibold uppercase text-red-700">{p.status}</span>
          )}
          {att && att !== 'present' && (
            <span className="rounded bg-gray-200 px-1.5 py-0.5 text-xs text-gray-700">{attendanceWord[att]}</span>
          )}
        </button>
        {onSheet ? (
          <Button variant="ghost" size="icon" onClick={() => removeFromBench(p.athlete_id)} aria-label={`Leave ${p.name} off the sheet`}
            className="min-h-[44px]">×</Button>
        ) : (
          <Button variant="ghost" size="icon" onClick={() => { toBench(p.athlete_id); setDirty(true); }} aria-label={`Add ${p.name} to the bench`}
            className="min-h-[44px]">+</Button>
        )}
      </div>
    );
  };

  const selectedOccupant = selectedSlot !== null ? field[selectedSlot] : undefined;

  return (
    <div className="mx-auto max-w-lg pb-24">
      <div className="px-4 pt-3">
        <div className="flex items-baseline justify-between gap-2">
          <h1 className="text-lg font-bold text-brand-primary">{data.team.name}</h1>
          {printHref && (
            <a href={printHref} target="_blank" rel="noreferrer" className="text-sm font-semibold uppercase text-brand-primary underline">Print</a>
          )}
        </div>
        <p className="text-sm text-gray-600">{title}</p>
        {data.is_template && eventId && (
          <p className="mt-1 text-xs text-gray-500">Starting from your default — save to keep it for this game.</p>
        )}
        {published && <p className="mt-1 text-xs font-semibold text-green-700">Published to families</p>}
      </div>

      <div className="mt-2 flex items-center gap-2 px-4">
        <label className="text-xs text-gray-600" htmlFor="lineup-formation">Formation</label>
        <select id="lineup-formation" value={formation} onChange={(e) => changeFormation(e.target.value)}
          className="min-h-[40px] rounded border border-gray-300 px-2 text-sm">
          {formationsFor(fieldSize).map((f) => <option key={f} value={f}>{f}</option>)}
        </select>
        <select aria-label="Field size" value={fieldSize} onChange={(e) => { if (isFieldSize(e.target.value)) changeFieldSize(e.target.value); }}
          className="min-h-[40px] rounded border border-gray-300 px-2 text-sm">
          {FIELD_SIZES.map((s) => <option key={s} value={s}>{s}</option>)}
        </select>
        <span className="ml-auto text-sm text-gray-700" data-testid="field-count">{onFieldCount}/{fieldPlayers} on field</span>
      </div>

      <div className="mt-2 px-2">
        <LineupPitch fieldSize={fieldSize} formation={formation} players={pitchPlayers} selectedSlot={selectedSlot} onSlotTap={handleSlotTap} />
      </div>

      {selectedSlot !== null && selectedOccupant !== undefined && (
        <div className="mt-2 flex flex-wrap items-center gap-2 px-4 text-sm">
          <span className="text-gray-700">{byId[selectedOccupant]?.name}</span>
          <Button variant="secondary" size="sm" onClick={() => { setCaptain(captain === selectedOccupant ? null : selectedOccupant); setDirty(true); }}>
            {captain === selectedOccupant ? 'Remove captain' : 'Make captain'}
          </Button>
          <Button variant="danger-link" size="sm" onClick={() => clearSlot(selectedSlot)}>Remove from pitch</Button>
        </div>
      )}

      {notice && (
        <div role="status" className={`mx-4 mt-2 rounded px-3 py-2 text-sm ${
          notice.kind === 'error' ? 'bg-red-50 text-red-700' : notice.kind === 'warn' ? 'bg-amber-50 text-amber-800' : 'bg-blue-50 text-blue-800'
        }`}>{notice.text}</div>
      )}
      {warnings.length > 0 && (
        <ul className="mx-4 mt-2 rounded bg-amber-50 px-3 py-2 text-sm text-amber-800">
          {warnings.map((w) => <li key={w}>{w}</li>)}
        </ul>
      )}

      <div className="mt-4 px-4">
        <h2 className="text-xs font-bold uppercase tracking-wide text-gray-500">Bench ({benchPlayers.length})</h2>
        <div className="mt-2 space-y-1">
          {benchPlayers.length === 0 && <p className="text-sm text-gray-500">Nobody on the bench.</p>}
          {benchPlayers.map((p) => playerChip(p, true))}
        </div>
      </div>

      {notDressed.length > 0 && (
        <div className="mt-4 px-4">
          <h2 className="text-xs font-bold uppercase tracking-wide text-gray-500">Not on the sheet ({notDressed.length})</h2>
          <div className="mt-2 space-y-1">{notDressed.map((p) => playerChip(p, false))}</div>
        </div>
      )}

      <div className="fixed inset-x-0 bottom-0 z-30 border-t border-gray-200 bg-white p-3">
        <div className="mx-auto flex max-w-lg flex-wrap gap-2">
          {eventId && data.last_game && (
            <Button variant="secondary" disabled={busy} onClick={() => copyFrom('last')}>Use last game</Button>
          )}
          {eventId && data.has_template && (
            <Button variant="secondary" disabled={busy} onClick={() => copyFrom('template')}>Use default</Button>
          )}
          <Button variant="secondary" disabled={busy} onClick={() => save(true)}>Save as default</Button>
          {eventId && (
            <Button disabled={busy} onClick={() => save(false)}>
              {dirty ? 'Save' : 'Saved'}
            </Button>
          )}
          {savedForGame && (
            <Button variant={published ? 'secondary' : 'primary'} disabled={busy} onClick={() => publish(!published)}>
              {published ? 'Unpublish' : 'Publish to crew'}
            </Button>
          )}
        </div>
      </div>
    </div>
  );
};

export default LineupBuilder;
