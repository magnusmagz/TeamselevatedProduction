import React, { useState, useEffect, useCallback } from 'react';
import { TournamentMatch, TournamentDivision } from '../types';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface Props {
  division: TournamentDivision;
  isAdmin: boolean;
}

function formatTime(dateStr: string | null): string {
  if (!dateStr) return 'TBD';
  return new Date(dateStr).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

const ScheduleManager: React.FC<Props> = ({ division, isAdmin }) => {
  const [matches, setMatches] = useState<TournamentMatch[]>([]);
  const [loading, setLoading] = useState(true);
  const [generating, setGenerating] = useState(false);

  const token = localStorage.getItem('auth_token');
  const headers: HeadersInit = { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` };

  const fetchMatches = useCallback(async () => {
    setLoading(true);
    try {
      const res = await fetch(`${API_URL}/api/tournament-gateway.php?action=matches-list&division_id=${division.id}&round=Group Stage`, { headers });
      const data = await res.json();
      setMatches(data.matches || []);
    } catch (err) { console.error(err); }
    finally { setLoading(false); }
  }, [division.id]);

  useEffect(() => { fetchMatches(); }, [fetchMatches]);

  const handleGenerate = async () => {
    if (!window.confirm('Generate round-robin schedule? This will replace any existing unplayed group stage matches.')) return;
    setGenerating(true);
    try {
      const res = await fetch(`${API_URL}/api/tournament-gateway.php?action=generate-group-schedule&division_id=${division.id}`, {
        method: 'POST', headers,
        body: JSON.stringify({
          start_time: new Date().toISOString().slice(0, 16).replace('T', ' ') + ':00',
          game_interval_minutes: division.game_duration_minutes + 20,
          min_rest_minutes: 120,
          field_ids: [],
        }),
      });
      if (!res.ok) { const err = await res.json(); alert(err.error); return; }
      fetchMatches();
    } catch (err) { alert('Failed to generate schedule'); }
    finally { setGenerating(false); }
  };

  // Group matches by group_name
  const grouped = matches.reduce<Record<string, TournamentMatch[]>>((acc, m) => {
    const key = m.group_name || 'Ungrouped';
    if (!acc[key]) acc[key] = [];
    acc[key].push(m);
    return acc;
  }, {});

  return (
    <div>
      <div className="flex justify-between items-center mb-4">
        <h4 className="font-semibold text-gray-900">{division.name} — Schedule</h4>
        {isAdmin && (
          <button onClick={handleGenerate} disabled={generating}
            className="px-3 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-brand-primary hover:bg-brand-primary-hover disabled:opacity-50">
            {generating ? 'Generating...' : 'Generate Schedule'}
          </button>
        )}
      </div>

      {loading ? (
        <div className="text-center py-6 text-gray-500">Loading schedule...</div>
      ) : matches.length === 0 ? (
        <div className="text-center py-8 bg-gray-50 rounded-lg border-2 border-dashed border-gray-300">
          <p className="text-gray-500">No matches scheduled. Generate the schedule to create matches.</p>
        </div>
      ) : (
        <div className="space-y-6">
          {Object.entries(grouped).map(([groupName, groupMatches]) => (
            <div key={groupName}>
              <h5 className="text-sm font-medium text-gray-600 mb-2">{groupName}</h5>
              <div className="space-y-2">
                {groupMatches.map((match) => (
                  <div key={match.id} className="bg-white border border-gray-200 rounded-md p-3 flex items-center justify-between">
                    <div className="flex items-center space-x-4">
                      <span className="text-xs text-gray-400 w-8">#{match.match_number}</span>
                      <span className="text-sm font-medium text-gray-900 w-40 text-right truncate">
                        {match.home_team_name || match.home_placeholder || 'TBD'}
                      </span>
                      <div className="text-center w-20">
                        {match.status === 'completed' ? (
                          <span className="text-lg font-bold text-gray-900">
                            {match.home_score} – {match.away_score}
                          </span>
                        ) : (
                          <span className="text-xs text-gray-400">vs</span>
                        )}
                      </div>
                      <span className="text-sm font-medium text-gray-900 w-40 truncate">
                        {match.away_team_name || match.away_placeholder || 'TBD'}
                      </span>
                    </div>
                    <div className="text-right text-xs text-gray-500 space-y-0.5">
                      <div>{formatTime(match.scheduled_time)}</div>
                      {match.field_name && <div>{match.field_name}</div>}
                    </div>
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
};

export default ScheduleManager;
