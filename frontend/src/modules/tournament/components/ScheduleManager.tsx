import React, { useState, useEffect, useCallback } from 'react';
import { TournamentMatch, TournamentDivision, Tournament } from '../types';
import MatchCenterModal from './MatchCenterModal';
import MatchCreateModal from './MatchCreateModal';
import ScheduleGenerateModal, { GenerationSummary } from './ScheduleGenerateModal';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface Props {
  division: TournamentDivision;
  tournament: Tournament;
  isAdmin: boolean;
}

function formatTime(dateStr: string | null): string {
  if (!dateStr) return 'TBD';
  return new Date(dateStr).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

const ScheduleManager: React.FC<Props> = ({ division, tournament, isAdmin }) => {
  const [matches, setMatches] = useState<TournamentMatch[]>([]);
  const [loading, setLoading] = useState(true);
  const [openMatch, setOpenMatch] = useState<TournamentMatch | null>(null);
  const [showCreate, setShowCreate] = useState(false);
  const [showGenerate, setShowGenerate] = useState(false);
  const [lastSummary, setLastSummary] = useState<GenerationSummary | null>(null);

  const token = localStorage.getItem('auth_token');
  const headers: HeadersInit = { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` };

  const fetchMatches = useCallback(async () => {
    setLoading(true);
    try {
      const res = await fetch(`${API_URL}/api/tournament-gateway.php?action=matches-list&division_id=${division.id}`, { headers });
      const data = await res.json();
      setMatches(data.matches || []);
    } catch (err) { console.error(err); }
    finally { setLoading(false); }
  }, [division.id]);

  useEffect(() => { fetchMatches(); }, [fetchMatches]);

  // Modal handles params + collision-aware generation. Kept the
  // state shape (generating + lastSummary) so existing UI bits read
  // naturally; modal calls back with the summary on success.
  const handleGenerated = (summary: GenerationSummary) => {
    setLastSummary(summary);
    fetchMatches();
  };

  // Bucket by group_name when available (group-stage match), otherwise by
  // round (Quarterfinal / Semifinal / etc.) so manually-added knockout
  // matches don't all land in a single "Ungrouped" pile.
  const grouped = matches.reduce<Record<string, TournamentMatch[]>>((acc, m) => {
    const key = m.group_name || m.round || 'Other';
    if (!acc[key]) acc[key] = [];
    acc[key].push(m);
    return acc;
  }, {});

  return (
    <div>
      <div className="flex justify-between items-center mb-4">
        <h4 className="font-semibold text-gray-900">{division.name} — Schedule</h4>
        {isAdmin && (
          <div className="flex space-x-2">
            <button onClick={() => setShowCreate(true)}
              className="px-3 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
              + Add Match
            </button>
            <button onClick={() => setShowGenerate(true)}
              className="px-3 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-brand-primary hover:bg-brand-primary-hover">
              Generate Schedule…
            </button>
          </div>
        )}
      </div>

      {lastSummary && (
        <div className="mb-4 bg-green-50 border border-green-200 rounded-md p-3 flex items-start justify-between">
          <div className="text-sm text-green-900">
            <strong>{lastSummary.matches_created} matches scheduled</strong>
            {lastSummary.field_ids_used.length > 0 && (
              <> across {lastSummary.field_ids_used.length} field{lastSummary.field_ids_used.length === 1 ? '' : 's'}</>
            )}
            {lastSummary.first_kickoff && lastSummary.last_kickoff && (
              <> · {formatTime(lastSummary.first_kickoff)} – {formatTime(lastSummary.last_kickoff)}</>
            )}
          </div>
          <button onClick={() => setLastSummary(null)} className="text-green-700 hover:text-green-900 text-xs">Dismiss</button>
        </div>
      )}

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
                {groupMatches.map((match) => {
                  const canScore = isAdmin && match.home_registration_id && match.away_registration_id;
                  return (
                    <div
                      key={match.id}
                      onClick={canScore ? () => setOpenMatch(match) : undefined}
                      className={`bg-white border border-gray-200 rounded-md p-3 flex items-center justify-between ${canScore ? 'cursor-pointer hover:border-brand-primary hover:bg-gray-50' : ''}`}
                    >
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
                        {canScore && (
                          <div className="text-brand-primary font-medium">
                            {match.status === 'completed' ? 'Edit / Report' : 'Open Match Center'}
                          </div>
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          ))}
        </div>
      )}

      {openMatch && (
        <MatchCenterModal
          match={openMatch}
          isKnockout={false}
          onClose={() => setOpenMatch(null)}
          onSaved={() => { setOpenMatch(null); fetchMatches(); }}
        />
      )}

      {showCreate && (
        <MatchCreateModal
          tournament={tournament}
          division={division}
          onClose={() => setShowCreate(false)}
          onCreated={fetchMatches}
        />
      )}

      {showGenerate && (
        <ScheduleGenerateModal
          tournament={tournament}
          division={division}
          onClose={() => setShowGenerate(false)}
          onGenerated={handleGenerated}
        />
      )}
    </div>
  );
};

export default ScheduleManager;
