import React, { useState, useEffect, useCallback } from 'react';
import { TournamentMatch } from '../types';
import ScoreEntry from './ScoreEntry';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface Props {
  divisionId: number;
  isAdmin: boolean;
}

const BracketView: React.FC<Props> = ({ divisionId, isAdmin }) => {
  const [matches, setMatches] = useState<TournamentMatch[]>([]);
  const [loading, setLoading] = useState(true);
  const [generating, setGenerating] = useState(false);
  const [scoringMatchId, setScoringMatchId] = useState<number | null>(null);

  const token = localStorage.getItem('auth_token');
  const headers: HeadersInit = { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` };

  const fetchMatches = useCallback(async () => {
    setLoading(true);
    try {
      // Fetch non-group-stage matches
      const res = await fetch(
        `${API_URL}/api/tournament-gateway.php?action=matches-list&division_id=${divisionId}`,
        { headers }
      );
      const data = await res.json();
      const knockoutMatches = (data.matches || []).filter((m: TournamentMatch) => m.round !== 'Group Stage');
      setMatches(knockoutMatches);
    } catch (err) { console.error(err); }
    finally { setLoading(false); }
  }, [divisionId]);

  useEffect(() => { fetchMatches(); }, [fetchMatches]);

  const handleGenerate = async () => {
    if (!window.confirm('Generate knockout bracket?')) return;
    setGenerating(true);
    try {
      const res = await fetch(
        `${API_URL}/api/tournament-gateway.php?action=generate-knockout-bracket&division_id=${divisionId}`,
        { method: 'POST', headers, body: JSON.stringify({ include_third_place: true, field_ids: [] }) }
      );
      if (!res.ok) { const err = await res.json(); alert(err.error); return; }
      fetchMatches();
    } catch (err) { alert('Failed to generate bracket'); }
    finally { setGenerating(false); }
  };

  const handleSlotWinners = async () => {
    try {
      await fetch(`${API_URL}/api/tournament-gateway.php?action=slot-group-winners&division_id=${divisionId}`, {
        method: 'POST', headers, body: '{}',
      });
      fetchMatches();
    } catch (err) { alert('Failed to slot group winners'); }
  };

  // Group by round
  const rounds = matches.reduce<Record<string, TournamentMatch[]>>((acc, m) => {
    if (!acc[m.round]) acc[m.round] = [];
    acc[m.round].push(m);
    return acc;
  }, {});

  const roundOrder = ['Round of 16', 'Quarterfinal', 'Semifinal', '3rd Place', 'Final'];

  return (
    <div>
      <div className="flex justify-between items-center mb-4">
        <h4 className="font-semibold text-gray-900">Knockout Bracket</h4>
        {isAdmin && (
          <div className="flex space-x-2">
            <button onClick={handleSlotWinners}
              className="px-3 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
              Slot Group Winners
            </button>
            <button onClick={handleGenerate} disabled={generating}
              className="px-3 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-brand-primary hover:bg-brand-primary-hover disabled:opacity-50">
              {generating ? 'Generating...' : 'Generate Bracket'}
            </button>
          </div>
        )}
      </div>

      {loading ? (
        <div className="text-center py-6 text-gray-500">Loading bracket...</div>
      ) : matches.length === 0 ? (
        <div className="text-center py-8 bg-gray-50 rounded-lg border-2 border-dashed border-gray-300">
          <p className="text-gray-500">No knockout bracket yet. Generate it after group stage is set up.</p>
        </div>
      ) : (
        <div className="flex space-x-6 overflow-x-auto pb-4">
          {roundOrder
            .filter((r) => rounds[r])
            .map((roundName) => (
              <div key={roundName} className="flex-shrink-0 w-64">
                <h5 className="text-xs font-semibold text-gray-500 uppercase mb-3 text-center">{roundName}</h5>
                <div className="space-y-3">
                  {rounds[roundName].map((match) => (
                    <div key={match.id} className={`border rounded-lg p-3 ${
                      match.status === 'completed' ? 'border-green-200 bg-green-50' : 'border-gray-200 bg-white'
                    }`}>
                      <div className="text-xs text-gray-400 mb-1">Match #{match.match_number}</div>

                      {/* Home team */}
                      <div className={`flex justify-between items-center py-1 ${
                        match.winner_registration_id && match.winner_registration_id === match.home_registration_id
                          ? 'font-bold' : ''
                      }`}>
                        <span className="text-sm text-gray-900 truncate w-36">
                          {match.home_team_name || match.home_placeholder || 'TBD'}
                        </span>
                        {match.status === 'completed' && (
                          <span className="text-sm font-mono">{match.home_score}
                            {match.home_penalty_score !== null && <sup className="text-xs text-orange-600">({match.home_penalty_score})</sup>}
                          </span>
                        )}
                      </div>

                      <div className="border-t border-gray-100 my-1" />

                      {/* Away team */}
                      <div className={`flex justify-between items-center py-1 ${
                        match.winner_registration_id && match.winner_registration_id === match.away_registration_id
                          ? 'font-bold' : ''
                      }`}>
                        <span className="text-sm text-gray-900 truncate w-36">
                          {match.away_team_name || match.away_placeholder || 'TBD'}
                        </span>
                        {match.status === 'completed' && (
                          <span className="text-sm font-mono">{match.away_score}
                            {match.away_penalty_score !== null && <sup className="text-xs text-orange-600">({match.away_penalty_score})</sup>}
                          </span>
                        )}
                      </div>

                      {/* Score entry */}
                      {match.status === 'scheduled' && match.home_registration_id && match.away_registration_id && isAdmin && (
                        <div className="mt-2 pt-2 border-t border-gray-100">
                          {scoringMatchId === match.id ? (
                            <ScoreEntry match={match} isKnockout onScored={() => { setScoringMatchId(null); fetchMatches(); }} />
                          ) : (
                            <button onClick={() => setScoringMatchId(match.id)}
                              className="text-xs text-brand-primary hover:underline">
                              Enter Score
                            </button>
                          )}
                        </div>
                      )}
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

export default BracketView;
