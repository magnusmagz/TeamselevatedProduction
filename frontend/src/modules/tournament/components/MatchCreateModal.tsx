import React, { useState, useEffect } from 'react';
import { TournamentDivision, Tournament, TournamentRegistration } from '../types';
import Button from '../../../components/ui/Button';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface VenueField {
  id: number;
  name: string;
  venue_id: number;
  venue_name: string;
}

interface GroupOption {
  id: number;
  name: string;
}

interface Props {
  tournament: Tournament;
  division: TournamentDivision;
  onClose: () => void;
  onCreated: () => void;
}

const ROUND_OPTIONS = [
  'Group Stage',
  'Round of 16',
  'Quarterfinal',
  'Semifinal',
  'Final',
  'Third Place',
  'Friendly',
  'Showcase',
];

// Default each match block to the day's first slot in the tournament's
// daily window, using the tournament start date as the day. Director can
// override either field.
function defaultStart(t: Tournament): string {
  const date = t.start_date || new Date().toISOString().slice(0, 10);
  const time = (t.daily_start_time || '08:00:00').slice(0, 5);
  return `${date}T${time}`;
}

function addMinutesIso(iso: string, minutes: number): string {
  if (!iso) return '';
  const d = new Date(iso);
  d.setMinutes(d.getMinutes() + minutes);
  // Match the datetime-local input format
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

const MatchCreateModal: React.FC<Props> = ({ tournament, division, onClose, onCreated }) => {
  const [round, setRound] = useState('Group Stage');
  const [groupId, setGroupId] = useState<number | ''>('');
  const [homeRegId, setHomeRegId] = useState<number | ''>('');
  const [awayRegId, setAwayRegId] = useState<number | ''>('');
  const [homePlaceholder, setHomePlaceholder] = useState('');
  const [awayPlaceholder, setAwayPlaceholder] = useState('');
  const [fieldId, setFieldId] = useState<number | ''>('');
  const [start, setStart] = useState(defaultStart(tournament));
  const [end, setEnd] = useState(addMinutesIso(defaultStart(tournament), division.game_duration_minutes || 60));
  const [notes, setNotes] = useState('');

  const [fields, setFields] = useState<VenueField[]>([]);
  const [groups, setGroups] = useState<GroupOption[]>([]);
  const [registrations, setRegistrations] = useState<TournamentRegistration[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const token = localStorage.getItem('auth_token');
  const headers: HeadersInit = { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` };

  useEffect(() => {
    let cancelled = false;
    Promise.all([
      // Fields — filter to tournament venue. Endpoint returns all fields
      // with venue info; no need for a new server-side filter.
      fetch(`${API_URL}/api/fields.php`, { headers }).then((r) => r.json()).catch(() => []),
      fetch(`${API_URL}/api/tournament-gateway.php?action=groups-list&division_id=${division.id}`, { headers })
        .then((r) => r.json()).catch(() => ({ groups: [] })),
      fetch(`${API_URL}/api/tournament-gateway.php?action=registrations-list&tournament_id=${tournament.id}`, { headers })
        .then((r) => r.json()).catch(() => ({ registrations: [] })),
    ]).then(([fieldsData, groupsData, regData]) => {
      if (cancelled) return;
      const allFields: VenueField[] = Array.isArray(fieldsData) ? fieldsData : (fieldsData.fields || []);
      const venueFields = tournament.venue_id
        ? allFields.filter((f) => f.venue_id === tournament.venue_id)
        : allFields;
      setFields(venueFields);
      setGroups((groupsData.groups || []).map((g: any) => ({ id: g.id, name: g.name })));
      const accepted = (regData.registrations || []).filter((r: TournamentRegistration) =>
        r.division_id === division.id && r.status === 'accepted'
      );
      setRegistrations(accepted);
      setLoading(false);
    }).catch(() => { if (!cancelled) setLoading(false); });

    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [division.id, tournament.id, tournament.venue_id]);

  // Keep end-time in sync when the user shifts the start (until they
  // manually override the end).
  const [endManuallyEdited, setEndManuallyEdited] = useState(false);
  const handleStartChange = (v: string) => {
    setStart(v);
    if (!endManuallyEdited) {
      setEnd(addMinutesIso(v, division.game_duration_minutes || 60));
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!start) { setError('Start time is required'); return; }
    if (homeRegId && awayRegId && homeRegId === awayRegId) {
      setError('Home and away cannot be the same team');
      return;
    }

    setSaving(true);
    setError('');
    try {
      const body = {
        round,
        group_id: round === 'Group Stage' ? (groupId || null) : null,
        home_registration_id: homeRegId || null,
        away_registration_id: awayRegId || null,
        home_placeholder: !homeRegId ? (homePlaceholder.trim() || null) : null,
        away_placeholder: !awayRegId ? (awayPlaceholder.trim() || null) : null,
        field_id: fieldId || null,
        scheduled_time: start ? start.replace('T', ' ') + ':00' : null,
        scheduled_end_time: end ? end.replace('T', ' ') + ':00' : null,
        notes: notes.trim() || null,
      };

      const res = await fetch(
        `${API_URL}/api/tournament-gateway.php?action=match-create&division_id=${division.id}`,
        { method: 'POST', headers, body: JSON.stringify(body) }
      );
      if (!res.ok) {
        const err = await res.json();
        throw new Error(err.error || 'Failed to create match');
      }
      onCreated();
      onClose();
    } catch (err: any) {
      setError(err.message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div className="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <div className="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
          <div>
            <h3 className="text-lg font-semibold text-gray-900">Add Match — {division.name}</h3>
            {tournament.venue_name && (
              <p className="text-xs text-gray-500 mt-0.5">Fields shown are at {tournament.venue_name}</p>
            )}
          </div>
          <Button variant="ghost" size="icon" aria-label="Close" onClick={onClose}>✕</Button>
        </div>

        {loading ? (
          <div className="p-6 text-center text-gray-500">Loading…</div>
        ) : (
          <form onSubmit={handleSubmit} className="p-6 space-y-4">
            {error && <div className="bg-red-50 border border-red-200 rounded-md p-3 text-red-700 text-sm">{error}</div>}

            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Round</label>
                <select
                  value={round}
                  onChange={(e) => setRound(e.target.value)}
                  className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                >
                  {ROUND_OPTIONS.map((r) => <option key={r} value={r}>{r}</option>)}
                </select>
              </div>

              {round === 'Group Stage' && (
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Group</label>
                  <select
                    value={groupId}
                    onChange={(e) => setGroupId(e.target.value ? Number(e.target.value) : '')}
                    className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                  >
                    <option value="">— No group —</option>
                    {groups.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
                  </select>
                </div>
              )}
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Home team</label>
                <select
                  value={homeRegId}
                  onChange={(e) => setHomeRegId(e.target.value ? Number(e.target.value) : '')}
                  className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                >
                  <option value="">— TBD —</option>
                  {registrations.map((r) => <option key={r.id} value={r.id}>{r.display_name}</option>)}
                </select>
                {!homeRegId && (
                  <input
                    type="text"
                    value={homePlaceholder}
                    onChange={(e) => setHomePlaceholder(e.target.value)}
                    placeholder='e.g. "Group A 1st"'
                    className="mt-1 w-full border border-gray-300 rounded-md px-3 py-1.5 text-xs"
                  />
                )}
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Away team</label>
                <select
                  value={awayRegId}
                  onChange={(e) => setAwayRegId(e.target.value ? Number(e.target.value) : '')}
                  className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                >
                  <option value="">— TBD —</option>
                  {registrations.map((r) => <option key={r.id} value={r.id}>{r.display_name}</option>)}
                </select>
                {!awayRegId && (
                  <input
                    type="text"
                    value={awayPlaceholder}
                    onChange={(e) => setAwayPlaceholder(e.target.value)}
                    placeholder='e.g. "Group B 2nd"'
                    className="mt-1 w-full border border-gray-300 rounded-md px-3 py-1.5 text-xs"
                  />
                )}
              </div>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Field</label>
              {fields.length === 0 ? (
                <p className="text-sm text-gray-500 border border-gray-200 rounded-md p-2 bg-gray-50">
                  {tournament.venue_id
                    ? 'No fields found at the tournament venue. Add fields under Venue management, or leave the field unassigned.'
                    : 'No tournament venue selected. Set one under Tournament Setup, or pick from all fields below.'}
                </p>
              ) : (
                <select
                  value={fieldId}
                  onChange={(e) => setFieldId(e.target.value ? Number(e.target.value) : '')}
                  className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                >
                  <option value="">— TBD —</option>
                  {fields.map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
                </select>
              )}
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Start *</label>
                <input
                  type="datetime-local"
                  value={start}
                  onChange={(e) => handleStartChange(e.target.value)}
                  className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">End</label>
                <input
                  type="datetime-local"
                  value={end}
                  onChange={(e) => { setEnd(e.target.value); setEndManuallyEdited(true); }}
                  className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                />
                <p className="text-xs text-gray-400 mt-1">Auto-calculated as start + {division.game_duration_minutes || 60} min</p>
              </div>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Notes</label>
              <textarea
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                rows={2}
                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                placeholder="Optional"
              />
            </div>

            <div className="flex justify-end space-x-3 pt-2">
              <Button variant="secondary" onClick={onClose}>
                Cancel
              </Button>
              <Button type="submit" loading={saving}>
                Add Match
              </Button>
            </div>
          </form>
        )}
      </div>
    </div>
  );
};

export default MatchCreateModal;
