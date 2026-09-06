import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { TournamentStanding } from '../types';
import DataTable, { DataTableColumn } from '../../../components/ui/DataTable';

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

  const stat = (key: keyof TournamentStanding, header: string, width: string): DataTableColumn<TournamentStanding> => ({
    key,
    header,
    align: 'center',
    width,
    render: (s) => <span className="text-gray-600">{s[key] as React.ReactNode}</span>,
  });

  const columns: DataTableColumn<TournamentStanding>[] = [
    { key: 'position', header: 'Pos', width: '2rem', render: (s) => <span className="text-gray-500">{s.position}</span> },
    { key: 'team_name', header: 'Team', render: (s) => <span className="font-medium text-gray-900">{s.team_name}</span> },
    stat('played', 'P', '2rem'),
    stat('won', 'W', '2rem'),
    stat('drawn', 'D', '2rem'),
    stat('lost', 'L', '2rem'),
    stat('goals_for', 'GF', '2.5rem'),
    stat('goals_against', 'GA', '2.5rem'),
    {
      key: 'goal_difference',
      header: 'GD',
      align: 'center',
      width: '2.5rem',
      render: (s) => (
        <span className="text-gray-600">{s.goal_difference > 0 ? `+${s.goal_difference}` : s.goal_difference}</span>
      ),
    },
    {
      key: 'points',
      header: 'Pts',
      align: 'center',
      width: '2.5rem',
      className: 'font-bold',
      render: (s) => <span className="font-bold text-gray-900">{s.points}</span>,
    },
  ];

  return (
    <div className="mb-6">
      <h5 className="text-sm font-semibold text-gray-700 mb-2">{groupName}</h5>
      <DataTable<TournamentStanding>
        columns={columns}
        rows={standings}
        rowKey={(s) => s.registration_id}
        rowClassName={(s) => (s.position <= advancingCount ? 'bg-green-50' : '')}
      />
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
