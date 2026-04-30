import React, { useState, useEffect, useCallback } from 'react';
import { TournamentMatch } from '../types';
import MatchCenterModal from './MatchCenterModal';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface DisciplinaryEvent {
  id: number;
  match_id: number;
  registration_id: number;
  event_type: 'yellow_card' | 'red_card' | 'second_yellow';
  minute: number | null;
  athlete_id: number | null;
  details: { player_name?: string; notes?: string } | null;
  match_number: number;
  round: string;
  division_id: number;
  division_name: string;
  age_group: string;
  team_name: string;
  home_team: string;
  away_team: string;
  player_label: string;
  created_at: string;
}

interface AccumulationEntry {
  team_name: string;
  division_name: string;
  player_label: string;
  yellow_count: number;
  red_count: number;
  second_yellow_count: number;
  suspended: boolean;
}

interface Totals {
  cards: number;
  yellows: number;
  reds: number;
  second_yellows: number;
  suspended_players: number;
}

interface Props {
  tournamentId: number;
  divisions: { id: number; name: string }[];
  isAdmin: boolean;
}

const CARD_LABELS: Record<DisciplinaryEvent['event_type'], string> = {
  yellow_card:   'Yellow',
  red_card:      'Red',
  second_yellow: '2nd Yellow',
};

/**
 * Disciplinary tab — comprehensive view of cards across the tournament.
 * Lists every yellow/red card from the Match Center referee reports,
 * computes per-player accumulation, and flags players who have crossed
 * the suspension threshold (2 yellows in tournament OR any red).
 *
 * Read-only listing for now: card data is created via Match Center, edited
 * via Match Center. This view is the central place to scan + filter.
 *
 * Auto-suspension enforcement (locking suspended players from future match
 * roster cards) is deferred to a follow-up slice.
 */
const DisciplinaryView: React.FC<Props> = ({ tournamentId, divisions, isAdmin }) => {
  const [events, setEvents] = useState<DisciplinaryEvent[]>([]);
  const [accumulation, setAccumulation] = useState<AccumulationEntry[]>([]);
  const [totals, setTotals] = useState<Totals>({ cards: 0, yellows: 0, reds: 0, second_yellows: 0, suspended_players: 0 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string>('');

  // Filters
  const [filterDivision, setFilterDivision] = useState<number | 'all'>('all');
  const [filterType, setFilterType] = useState<'all' | 'yellow_card' | 'red_card' | 'second_yellow'>('all');
  const [filterTeam, setFilterTeam] = useState<string>('');

  // Match Center modal
  const [openMatch, setOpenMatch] = useState<TournamentMatch | null>(null);

  const token = typeof window !== 'undefined' ? localStorage.getItem('auth_token') : null;
  const headers: HeadersInit = { Authorization: `Bearer ${token}` };

  const fetchData = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const res = await fetch(
        `${API_URL}/api/tournament-gateway.php?action=tournament-disciplinary-list&tournament_id=${tournamentId}`,
        { headers }
      );
      if (!res.ok) { const err = await res.json(); throw new Error(err.error || 'Load failed'); }
      const data = await res.json();
      setEvents(data.events || []);
      setAccumulation(data.accumulation || []);
      setTotals(data.totals || { cards: 0, yellows: 0, reds: 0, second_yellows: 0, suspended_players: 0 });
    } catch (e: any) {
      setError(e.message || 'Failed to load disciplinary data');
    } finally {
      setLoading(false);
    }
  }, [tournamentId]);

  useEffect(() => { fetchData(); }, [fetchData]);

  // Open the Match Center for a row's match (loads minimal match shape)
  const handleViewMatch = async (matchId: number) => {
    const res = await fetch(
      `${API_URL}/api/tournament-gateway.php?action=matches-list&division_id=${events.find((e) => e.match_id === matchId)?.division_id}`,
      { headers }
    );
    const data = await res.json();
    const m = (data.matches || []).find((x: TournamentMatch) => x.id === matchId);
    if (m) setOpenMatch(m);
  };

  // Apply filters
  const filtered = events.filter((e) => {
    if (filterDivision !== 'all' && e.division_id !== filterDivision) return false;
    if (filterType !== 'all' && e.event_type !== filterType) return false;
    if (filterTeam && !e.team_name.toLowerCase().includes(filterTeam.toLowerCase())) return false;
    return true;
  });

  if (loading) return <div className="text-sm text-gray-500 py-6">Loading disciplinary data...</div>;
  if (error) return <div className="bg-red-50 border border-red-200 text-red-700 text-sm rounded p-3">{error}</div>;

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h3 className="text-lg font-semibold text-gray-900">Disciplinary</h3>
          <p className="text-xs text-gray-500 mt-0.5">
            {totals.cards === 0
              ? 'No cards logged yet. Add cards via Match Center → Referee Report.'
              : `${totals.cards} card${totals.cards === 1 ? '' : 's'} this tournament`}
          </p>
        </div>
      </div>

      {/* Stat tiles */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div className="bg-white border border-gray-200 rounded-lg p-3">
          <div className="text-2xl font-bold text-gray-900">{totals.cards}</div>
          <div className="text-xs text-gray-500 uppercase tracking-wide mt-1">Total cards</div>
        </div>
        <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-3">
          <div className="text-2xl font-bold text-yellow-700">{totals.yellows}</div>
          <div className="text-xs text-yellow-700 uppercase tracking-wide mt-1">Yellows</div>
        </div>
        <div className="bg-red-50 border border-red-200 rounded-lg p-3">
          <div className="text-2xl font-bold text-red-700">{totals.reds}</div>
          <div className="text-xs text-red-700 uppercase tracking-wide mt-1">Reds</div>
        </div>
        <div className="bg-orange-50 border border-orange-200 rounded-lg p-3">
          <div className="text-2xl font-bold text-orange-700">{totals.suspended_players}</div>
          <div className="text-xs text-orange-700 uppercase tracking-wide mt-1">Suspended</div>
        </div>
      </div>

      {/* Accumulation rule + suspension list */}
      <div className="bg-blue-50 border border-blue-200 rounded-lg p-4 text-sm text-blue-900">
        <p className="font-medium">Accumulation rule</p>
        <p className="text-xs text-blue-800 mt-1">
          2 yellow cards in the same tournament = 1-match suspension. Any red card = minimum 1-match suspension.
          Serious misconduct reviewed by the director.
        </p>
      </div>

      {accumulation.filter((p) => p.suspended).length > 0 && (
        <div className="bg-white border border-gray-200 rounded-lg p-4">
          <h4 className="text-sm font-semibold text-gray-900 mb-2">Currently suspended</h4>
          <ul className="space-y-1.5">
            {accumulation.filter((p) => p.suspended).map((p, i) => (
              <li key={i} className="flex items-center justify-between text-sm">
                <div>
                  <span className="font-medium">{p.player_label}</span>
                  <span className="text-gray-500"> · {p.team_name}</span>
                  <span className="text-gray-400 ml-2 text-xs">({p.division_name})</span>
                </div>
                <div className="flex items-center gap-2 text-xs">
                  {p.yellow_count > 0 && (
                    <span className="px-2 py-0.5 bg-yellow-100 text-yellow-700 rounded">
                      {p.yellow_count}Y
                    </span>
                  )}
                  {p.red_count > 0 && (
                    <span className="px-2 py-0.5 bg-red-100 text-red-700 rounded">
                      {p.red_count}R
                    </span>
                  )}
                  {p.second_yellow_count > 0 && (
                    <span className="px-2 py-0.5 bg-orange-100 text-orange-700 rounded">
                      {p.second_yellow_count}×2Y
                    </span>
                  )}
                </div>
              </li>
            ))}
          </ul>
        </div>
      )}

      {/* Filters */}
      <div className="flex flex-wrap gap-2 items-end">
        <div>
          <label className="block text-xs text-gray-500 mb-1">Division</label>
          <select
            value={filterDivision}
            onChange={(e) => setFilterDivision(e.target.value === 'all' ? 'all' : Number(e.target.value))}
            className="border border-gray-300 rounded px-2 py-1 text-sm bg-white"
          >
            <option value="all">All divisions</option>
            {divisions.map((d) => (<option key={d.id} value={d.id}>{d.name}</option>))}
          </select>
        </div>
        <div>
          <label className="block text-xs text-gray-500 mb-1">Card type</label>
          <select
            value={filterType}
            onChange={(e) => setFilterType(e.target.value as any)}
            className="border border-gray-300 rounded px-2 py-1 text-sm bg-white"
          >
            <option value="all">All cards</option>
            <option value="yellow_card">Yellow only</option>
            <option value="red_card">Red only</option>
            <option value="second_yellow">2nd yellow</option>
          </select>
        </div>
        <div className="flex-1 min-w-[140px]">
          <label className="block text-xs text-gray-500 mb-1">Team contains</label>
          <input
            type="text"
            value={filterTeam}
            onChange={(e) => setFilterTeam(e.target.value)}
            placeholder="Filter by team name…"
            className="w-full border border-gray-300 rounded px-2 py-1 text-sm"
          />
        </div>
        {(filterDivision !== 'all' || filterType !== 'all' || filterTeam) && (
          <button
            onClick={() => { setFilterDivision('all'); setFilterType('all'); setFilterTeam(''); }}
            className="text-xs text-gray-500 hover:text-gray-700 underline pb-1"
          >
            Clear
          </button>
        )}
      </div>

      {/* Card table */}
      {filtered.length === 0 ? (
        <div className="text-center py-8 bg-gray-50 rounded-lg border-2 border-dashed border-gray-300">
          <p className="text-sm text-gray-500">
            {totals.cards === 0
              ? 'No cards logged yet. Add cards via Match Center → Referee Report.'
              : 'No cards match the current filters.'}
          </p>
        </div>
      ) : (
        <div className="overflow-x-auto bg-white border border-gray-200 rounded-lg">
          <table className="min-w-full text-sm">
            <thead>
              <tr className="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                <th className="px-3 py-2 text-left">Card</th>
                <th className="px-3 py-2 text-left">Division</th>
                <th className="px-3 py-2 text-left">Match</th>
                <th className="px-3 py-2 text-left">Player</th>
                <th className="px-3 py-2 text-left">Team</th>
                <th className="px-3 py-2 text-center w-12">Min</th>
                <th className="px-3 py-2 text-right w-20">{isAdmin ? '' : ''}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {filtered.map((e) => {
                const cardClass =
                  e.event_type === 'yellow_card'
                    ? 'bg-yellow-100 text-yellow-700'
                    : e.event_type === 'red_card'
                    ? 'bg-red-100 text-red-700'
                    : 'bg-orange-100 text-orange-700';
                return (
                  <tr key={e.id} className="hover:bg-gray-50">
                    <td className="px-3 py-2">
                      <span className={`text-xs font-semibold px-2 py-0.5 rounded ${cardClass}`}>
                        {CARD_LABELS[e.event_type]}
                      </span>
                    </td>
                    <td className="px-3 py-2 text-xs text-gray-600">{e.division_name}</td>
                    <td className="px-3 py-2 text-xs text-gray-700 truncate max-w-[200px]">
                      {e.home_team} vs {e.away_team}
                    </td>
                    <td className="px-3 py-2 font-medium text-gray-900">{e.player_label}</td>
                    <td className="px-3 py-2 text-xs text-gray-500">{e.team_name}</td>
                    <td className="px-3 py-2 text-center text-xs text-gray-500">
                      {e.minute != null ? `${e.minute}'` : '—'}
                    </td>
                    <td className="px-3 py-2 text-right">
                      {isAdmin && (
                        <button
                          onClick={() => handleViewMatch(e.match_id)}
                          className="text-xs text-brand-primary hover:underline"
                        >
                          View match
                        </button>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {openMatch && (
        <MatchCenterModal
          match={openMatch}
          isKnockout={openMatch.round !== 'Group Stage'}
          onClose={() => setOpenMatch(null)}
          onSaved={() => { setOpenMatch(null); fetchData(); }}
        />
      )}
    </div>
  );
};

export default DisciplinaryView;
