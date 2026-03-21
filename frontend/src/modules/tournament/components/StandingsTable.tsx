import React, { useState, useEffect, useCallback } from 'react';
import { TournamentStanding } from '../types';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface Props {
  groupId: number;
  groupName: string;
  advancingCount: number;
}

const StandingsTable: React.FC<Props> = ({ groupId, groupName, advancingCount }) => {
  const [standings, setStandings] = useState<TournamentStanding[]>([]);
  const [loading, setLoading] = useState(true);

  const token = localStorage.getItem('auth_token');
  const headers: HeadersInit = { Authorization: `Bearer ${token}` };

  const fetchStandings = useCallback(async () => {
    try {
      const res = await fetch(`${API_URL}/api/tournament-gateway.php?action=standings-get&group_id=${groupId}`, { headers });
      const data = await res.json();
      setStandings(data.standings || []);
    } catch (err) { console.error(err); }
    finally { setLoading(false); }
  }, [groupId]);

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
    </div>
  );
};

export default StandingsTable;
