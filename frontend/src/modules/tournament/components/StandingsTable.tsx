import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { TournamentStanding } from '../types';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface Props {
  groupId: number;
  groupName: string;
  advancingCount: number;
  tiebreakerRules?: string[] | null;
}

// Map raw tiebreaker keys to friendly labels for the caption.
const TIEBREAKER_LABELS: Record<string, string> = {
  points:           'Points',
  head_to_head:     'Head-to-head',
  goal_difference:  'Goal difference',
  goals_for:        'Goals for',
  goals_against:    'Goals against',
  wins:             'Wins',
  coin_flip:        'Coin flip',
};

function formatTiebreakerChain(rules?: string[] | null): string {
  if (!rules || rules.length === 0) return '';
  return rules.map((r) => TIEBREAKER_LABELS[r] || r).join(' → ');
}

const StandingsTable: React.FC<Props> = ({ groupId, groupName, advancingCount, tiebreakerRules }) => {
  const [standings, setStandings] = useState<TournamentStanding[]>([]);
  const [loading, setLoading] = useState(true);

  const token = localStorage.getItem('auth_token');
  // Stable across renders so the fetch effects/callbacks below can depend on
  // it without re-firing on every render.
  const headers: HeadersInit = useMemo(() => ({ Authorization: `Bearer ${token}` }), [token]);

  const fetchStandings = useCallback(async () => {
    try {
      const res = await fetch(`${API_URL}/api/tournament-gateway.php?action=standings-get&group_id=${groupId}`, { headers });
      const data = await res.json();
      setStandings(data.standings || []);
    } catch (err) { console.error(err); }
    finally { setLoading(false); }
  }, [groupId, headers]);

  useEffect(() => { fetchStandings(); }, [fetchStandings]);

  if (loading) return <div className="text-sm text-gray-500">Loading...</div>;
  if (standings.length === 0) return <div className="text-sm text-gray-400 italic">No standings data yet</div>;

  return (
    <div className="mb-6">
      <h5 className="text-sm font-semibold text-gray-700 mb-2">{groupName}</h5>
      <div className="overflow-x-auto">
        <table className="min-w-full text-sm">
          <thead>
            <tr className="bg-gray-50 text-xs text-gray-500 uppercase">
              <th className="px-2 py-2 text-left w-8">Pos</th>
              <th className="px-2 py-2 text-left">Team</th>
              <th className="px-2 py-2 text-center w-8">P</th>
              <th className="px-2 py-2 text-center w-8">W</th>
              <th className="px-2 py-2 text-center w-8">D</th>
              <th className="px-2 py-2 text-center w-8">L</th>
              <th className="px-2 py-2 text-center w-10">GF</th>
              <th className="px-2 py-2 text-center w-10">GA</th>
              <th className="px-2 py-2 text-center w-10">GD</th>
              <th className="px-2 py-2 text-center w-10 font-bold">Pts</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {standings.map((s) => {
              const isAdvancing = s.position <= advancingCount;
              return (
                <tr key={s.registration_id} className={isAdvancing ? 'bg-green-50' : ''}>
                  <td className="px-2 py-2 text-gray-500">{s.position}</td>
                  <td className="px-2 py-2 font-medium text-gray-900">{s.team_name}</td>
                  <td className="px-2 py-2 text-center text-gray-600">{s.played}</td>
                  <td className="px-2 py-2 text-center text-gray-600">{s.won}</td>
                  <td className="px-2 py-2 text-center text-gray-600">{s.drawn}</td>
                  <td className="px-2 py-2 text-center text-gray-600">{s.lost}</td>
                  <td className="px-2 py-2 text-center text-gray-600">{s.goals_for}</td>
                  <td className="px-2 py-2 text-center text-gray-600">{s.goals_against}</td>
                  <td className="px-2 py-2 text-center text-gray-600">
                    {s.goal_difference > 0 ? `+${s.goal_difference}` : s.goal_difference}
                  </td>
                  <td className="px-2 py-2 text-center font-bold text-gray-900">{s.points}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      <p className="text-xs text-gray-500 mt-2">
        <span className="inline-block w-2 h-2 bg-green-200 rounded-sm mr-1 align-middle" />
        Top {advancingCount} advance
        {tiebreakerRules && tiebreakerRules.length > 0 && (
          <>
            {' '}<span className="text-gray-400">·</span>{' '}
            <span title="Order applied when teams are tied">
              Tiebreakers: {formatTiebreakerChain(tiebreakerRules)}
            </span>
          </>
        )}
      </p>
    </div>
  );
};

export default StandingsTable;
