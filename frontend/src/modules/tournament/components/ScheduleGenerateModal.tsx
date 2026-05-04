import React, { useState, useEffect } from 'react';
import { Tournament, TournamentDivision } from '../types';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface VenueField {
  id: number;
  name: string;
  venue_id: number;
  venue_name: string;
}

interface Props {
  tournament: Tournament;
  division: TournamentDivision;
  onClose: () => void;
  onGenerated: (summary: GenerationSummary) => void;
}

interface GenerationSummary {
  matches_created: number;
  field_ids_used: number[];
  first_kickoff: string | null;
  last_kickoff: string | null;
}

const ScheduleGenerateModal: React.FC<Props> = ({ tournament, division, onClose, onGenerated }) => {
  const [startDate, setStartDate] = useState<string>(tournament.start_date || '');
  const [startTime, setStartTime] = useState<string>((tournament.daily_start_time || '08:00:00').slice(0, 5));
  const [gameInterval, setGameInterval] = useState<number>(division.game_duration_minutes + 20);
  const [minRest, setMinRest] = useState<number>(120);
  const [fields, setFields] = useState<VenueField[]>([]);
  const [selectedFieldIds, setSelectedFieldIds] = useState<number[]>([]);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');

  const token = localStorage.getItem('auth_token');
  const headers: HeadersInit = { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` };

  useEffect(() => {
    fetch(`${API_URL}/api/fields.php`, { headers })
      .then((r) => r.json())
      .then((data: VenueField[] | { fields: VenueField[] }) => {
        const all = Array.isArray(data) ? data : (data.fields || []);
        const venueFields = tournament.venue_id
          ? all.filter((f) => f.venue_id === tournament.venue_id)
          : all;
        setFields(venueFields);
        // Default: all venue fields selected.
        setSelectedFieldIds(venueFields.map((f) => f.id));
      })
      .catch(() => {})
      .finally(() => setLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tournament.venue_id]);

  const toggleField = (id: number) => {
    setSelectedFieldIds((prev) => prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!startDate) { setError('Pick a start date'); return; }
    if (selectedFieldIds.length === 0) { setError('Pick at least one field'); return; }
    if (gameInterval < division.game_duration_minutes) {
      setError(`Game interval must be at least the game duration (${division.game_duration_minutes} min)`);
      return;
    }

    if (!window.confirm(
      'Generate round-robin schedule? This replaces any existing unplayed Group Stage matches in this division. ' +
      'Other divisions\' bookings on the selected fields will be respected.'
    )) return;

    setSubmitting(true);
    setError('');
    try {
      const startTimeIso = `${startDate} ${startTime}:00`;
      const res = await fetch(
        `${API_URL}/api/tournament-gateway.php?action=generate-group-schedule&division_id=${division.id}`,
        {
          method: 'POST', headers,
          body: JSON.stringify({
            start_time: startTimeIso,
            game_interval_minutes: gameInterval,
            min_rest_minutes: minRest,
            field_ids: selectedFieldIds,
          }),
        }
      );
      if (!res.ok) {
        const err = await res.json();
        throw new Error(err.error || 'Failed to generate schedule');
      }
      const data = await res.json();
      const matches = data.matches || [];
      const summary: GenerationSummary = {
        matches_created: matches.length,
        field_ids_used: Array.from(new Set(matches.map((m: any) => m.field_id).filter(Boolean))) as number[],
        first_kickoff: matches[0]?.scheduled_time ?? null,
        last_kickoff: matches[matches.length - 1]?.scheduled_time ?? null,
      };
      onGenerated(summary);
      onClose();
    } catch (e: any) {
      setError(e.message || 'Failed to generate');
    } finally {
      setSubmitting(false);
    }
  };

  const allSelected = selectedFieldIds.length === fields.length;

  return (
    <div className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4">
      <div className="bg-white rounded-xl shadow-2xl w-full max-w-xl max-h-[92vh] overflow-y-auto">
        <div className="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
          <div>
            <h3 className="text-lg font-semibold text-gray-900">Generate Schedule — {division.name}</h3>
            {tournament.venue_name && (
              <p className="text-xs text-gray-500 mt-0.5">Fields shown are at {tournament.venue_name}</p>
            )}
          </div>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        {loading ? (
          <div className="p-6 text-center text-gray-500">Loading…</div>
        ) : (
          <form onSubmit={handleSubmit} className="p-5 space-y-4">
            {error && <div className="bg-red-50 border border-red-200 rounded-md p-3 text-red-700 text-sm">{error}</div>}

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">First match — date</label>
                <input
                  type="date"
                  value={startDate}
                  min={tournament.start_date || undefined}
                  max={tournament.end_date || undefined}
                  onChange={(e) => setStartDate(e.target.value)}
                  className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">First match — time</label>
                <input
                  type="time"
                  value={startTime}
                  onChange={(e) => setStartTime(e.target.value)}
                  className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Game interval (min)</label>
                <input
                  type="number"
                  min={division.game_duration_minutes}
                  value={gameInterval}
                  onChange={(e) => setGameInterval(parseInt(e.target.value, 10) || division.game_duration_minutes)}
                  className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                />
                <p className="text-xs text-gray-400 mt-1">Game length ({division.game_duration_minutes}) + buffer for changeover</p>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Min rest between games (min)</label>
                <input
                  type="number"
                  min="0"
                  value={minRest}
                  onChange={(e) => setMinRest(parseInt(e.target.value, 10) || 0)}
                  className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                />
                <p className="text-xs text-gray-400 mt-1">Per team — applies to consecutive matches</p>
              </div>
            </div>

            <div>
              <div className="flex items-baseline justify-between mb-1">
                <label className="block text-sm font-medium text-gray-700">
                  Fields <span className="text-xs text-gray-500 font-normal">({selectedFieldIds.length} of {fields.length})</span>
                </label>
                {fields.length > 0 && (
                  <button
                    type="button"
                    onClick={() => setSelectedFieldIds(allSelected ? [] : fields.map((f) => f.id))}
                    className="text-xs text-brand-primary hover:underline"
                  >
                    {allSelected ? 'Clear all' : 'Select all'}
                  </button>
                )}
              </div>
              {fields.length === 0 ? (
                <p className="text-sm text-gray-500 border border-gray-200 rounded p-2 bg-gray-50">
                  No fields available. Pick a tournament venue under Tournament Setup or add fields to the venue.
                </p>
              ) : (
                <div className="border border-gray-300 rounded-md max-h-48 overflow-y-auto divide-y divide-gray-100">
                  {fields.map((f) => {
                    const selected = selectedFieldIds.includes(f.id);
                    return (
                      <label key={f.id} className={`flex items-center gap-3 px-3 py-2 cursor-pointer hover:bg-gray-50 ${selected ? 'bg-brand-primary/5' : ''}`}>
                        <input
                          type="checkbox"
                          checked={selected}
                          onChange={() => toggleField(f.id)}
                          className="rounded border-gray-300 text-brand-primary focus:ring-brand-primary"
                        />
                        <span className="text-sm text-gray-900">{f.name}</span>
                      </label>
                    );
                  })}
                </div>
              )}
            </div>

            <div className="bg-blue-50 border border-blue-200 rounded p-3 text-xs text-blue-900">
              The generator respects matches already booked by <strong>other divisions</strong> at the selected fields,
              so it won't double-book Field 3 at 10am if another division already has it.
            </div>

            <div className="flex justify-end space-x-2 pt-2 border-t border-gray-200">
              <button type="button" onClick={onClose}
                className="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                Cancel
              </button>
              <button type="submit" disabled={submitting || selectedFieldIds.length === 0}
                className="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-brand-primary hover:bg-brand-primary-hover disabled:opacity-50">
                {submitting ? 'Generating…' : 'Generate Schedule'}
              </button>
            </div>
          </form>
        )}
      </div>
    </div>
  );
};

export default ScheduleGenerateModal;
export type { GenerationSummary };
