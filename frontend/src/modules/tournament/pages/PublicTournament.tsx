import React, { useState, useEffect, useCallback } from 'react';
import { useParams } from 'react-router-dom';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface PublicTournamentData {
  id: number;
  name: string;
  description: string | null;
  sport: string;
  start_date: string;
  end_date: string;
  venue_id: number | null;
  venue_name: string | null;
  venue_address: string | null;
  venue_city: string | null;
  venue_state: string | null;
  venue_zip: string | null;
  location_name: string | null;
  location_address: string | null;
  location_city: string | null;
  location_state: string | null;
  status: string;
  contact_name: string | null;
  contact_email: string | null;
  club_name: string;
  club_logo_url: string | null;
  primary_color: string | null;
  secondary_color: string | null;
  divisions: { id: number; name: string; age_group: string; gender: string; format: string; sport_rule_notes: string[] | null }[];
}

interface PublicMatch {
  match_number: number;
  round: string;
  home_team: string | null;
  away_team: string | null;
  home_placeholder: string | null;
  away_placeholder: string | null;
  home_score: number | null;
  away_score: number | null;
  home_penalty_score: number | null;
  away_penalty_score: number | null;
  status: string;
  scheduled_time: string | null;
  field_name: string | null;
  group_name: string | null;
}

interface StandingEntry {
  position: number;
  team_name: string;
  played: number;
  won: number;
  drawn: number;
  lost: number;
  goals_for: number;
  goals_against: number;
  goal_difference: number;
  points: number;
}

function formatDate(d: string): string {
  return new Date(d).toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
}

function formatTime(d: string | null): string {
  if (!d) return 'TBD';
  return new Date(d).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

const PublicTournament: React.FC = () => {
  const { slug } = useParams<{ slug: string }>();
  const [tournament, setTournament] = useState<PublicTournamentData | null>(null);
  const [loading, setLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [activeTab, setActiveTab] = useState<'schedule' | 'standings' | 'bracket'>('schedule');
  const [activeDivision, setActiveDivision] = useState<number | null>(null);

  // Data states
  const [matches, setMatches] = useState<PublicMatch[]>([]);
  const [standingsGroups, setStandingsGroups] = useState<{ name: string; standings: StandingEntry[] }[]>([]);
  const [bracketRounds, setBracketRounds] = useState<{ name: string; matches: any[] }[]>([]);

  useEffect(() => {
    if (!slug) return;
    fetch(`${API_URL}/api/tournament-public-gateway.php?action=tournament-by-slug&slug=${slug}`)
      .then((r) => { if (r.status === 404) { setNotFound(true); return null; } return r.json(); })
      .then((data) => {
        if (data) {
          setTournament(data);
          if (data.divisions?.length > 0) setActiveDivision(data.divisions[0].id);
        }
      })
      .catch(() => setNotFound(true))
      .finally(() => setLoading(false));
  }, [slug]);

  // Fetch tab data
  const fetchTabData = useCallback(async () => {
    if (!tournament) return;

    if (activeTab === 'schedule') {
      const url = `${API_URL}/api/tournament-public-gateway.php?action=public-schedule&tournament_id=${tournament.id}${activeDivision ? `&division_id=${activeDivision}` : ''}`;
      const res = await fetch(url);
      const data = await res.json();
      setMatches(data.matches || []);
    } else if (activeTab === 'standings' && activeDivision) {
      const res = await fetch(`${API_URL}/api/tournament-public-gateway.php?action=public-standings&division_id=${activeDivision}`);
      const data = await res.json();
      setStandingsGroups(data.groups || []);
    } else if (activeTab === 'bracket' && activeDivision) {
      const res = await fetch(`${API_URL}/api/tournament-public-gateway.php?action=public-bracket&division_id=${activeDivision}`);
      const data = await res.json();
      setBracketRounds(data.rounds || []);
    }
  }, [tournament, activeTab, activeDivision]);

  useEffect(() => { fetchTabData(); }, [fetchTabData]);

  // Auto-refresh every 60s when in_progress
  useEffect(() => {
    if (!tournament || tournament.status !== 'in_progress') return;
    const interval = setInterval(fetchTabData, 60000);
    return () => clearInterval(interval);
  }, [tournament, fetchTabData]);

  if (loading) return <div className="min-h-screen flex items-center justify-center text-gray-500">Loading...</div>;
  if (notFound || !tournament) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <div className="text-center">
          <h1 className="text-2xl font-bold text-gray-900">Tournament Not Found</h1>
          <p className="text-gray-500 mt-2">This tournament may not exist or is not yet public.</p>
        </div>
      </div>
    );
  }

  const brandColor = tournament.primary_color || '#1a56db';

  return (
    <div className="min-h-screen bg-gray-50">
      {/* Header */}
      <header className="bg-white border-b" style={{ borderBottomColor: brandColor }}>
        <div className="max-w-5xl mx-auto px-4 py-6">
          <div className="flex items-center space-x-4">
            {tournament.club_logo_url && (
              <img src={tournament.club_logo_url} alt="" className="w-12 h-12 object-contain" />
            )}
            <div>
              <h1 className="text-2xl font-bold text-gray-900">{tournament.name}</h1>
              <div className="text-sm text-gray-500 mt-1 space-x-3">
                <span>{formatDate(tournament.start_date)} – {formatDate(tournament.end_date)}</span>
                {(tournament.venue_name || tournament.location_name) && (
                  <span>| {tournament.venue_name || tournament.location_name}
                    {(tournament.venue_city || tournament.location_city) && (
                      <span className="text-gray-400">
                        {' '}({tournament.venue_city || tournament.location_city}{(tournament.venue_state || tournament.location_state) ? `, ${tournament.venue_state || tournament.location_state}` : ''})
                      </span>
                    )}
                  </span>
                )}
                <span className="capitalize">| {tournament.sport}</span>
              </div>
              <p className="text-xs text-gray-400 mt-0.5">{tournament.club_name}</p>
            </div>
          </div>
        </div>
      </header>

      <div className="max-w-5xl mx-auto px-4 py-6">
        {/* Division selector */}
        {tournament.divisions.length > 1 && (
          <div className="flex space-x-2 mb-4 overflow-x-auto">
            {tournament.divisions.map((d) => (
              <button key={d.id}
                onClick={() => setActiveDivision(d.id)}
                className={`px-3 py-1.5 rounded-full text-sm font-medium whitespace-nowrap ${
                  activeDivision === d.id
                    ? 'text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                }`}
                style={activeDivision === d.id ? { backgroundColor: brandColor } : {}}
              >
                {d.name}
              </button>
            ))}
          </div>
        )}

        {/* Tabs */}
        <div className="flex space-x-4 border-b border-gray-200 mb-6">
          {['schedule', 'standings', 'bracket'].map((tab) => (
            <button key={tab}
              onClick={() => setActiveTab(tab as any)}
              className={`py-2 px-3 text-sm font-medium capitalize border-b-2 ${
                activeTab === tab
                  ? 'border-current' : 'border-transparent text-gray-500 hover:text-gray-700'
              }`}
              style={activeTab === tab ? { color: brandColor, borderColor: brandColor } : {}}
            >
              {tab}
            </button>
          ))}
        </div>

        {/* Schedule */}
        {activeTab === 'schedule' && (
          <div className="space-y-2">
            {matches.length === 0 ? (
              <p className="text-center text-gray-400 py-8">No matches scheduled yet.</p>
            ) : (
              matches.map((m, i) => (
                <div key={i} className="bg-white rounded-lg border border-gray-200 p-3 flex items-center justify-between">
                  <div className="flex items-center space-x-3 flex-1">
                    <span className="text-xs text-gray-400 w-6">#{m.match_number}</span>
                    <span className="text-sm font-medium text-right w-36 truncate">{m.home_team || m.home_placeholder || 'TBD'}</span>
                    <div className="w-16 text-center">
                      {m.status === 'completed' ? (
                        <span className="font-bold">{m.home_score} – {m.away_score}</span>
                      ) : (
                        <span className="text-xs text-gray-400">vs</span>
                      )}
                    </div>
                    <span className="text-sm font-medium w-36 truncate">{m.away_team || m.away_placeholder || 'TBD'}</span>
                  </div>
                  <div className="text-right text-xs text-gray-500 ml-4">
                    <div>{formatTime(m.scheduled_time)}</div>
                    {m.field_name && <div>{m.field_name}</div>}
                    {m.group_name && <div className="text-gray-400">{m.group_name}</div>}
                  </div>
                </div>
              ))
            )}
          </div>
        )}

        {/* Standings */}
        {activeTab === 'standings' && (
          <div className="space-y-6">
            {standingsGroups.length === 0 ? (
              <p className="text-center text-gray-400 py-8">No standings available yet.</p>
            ) : (
              standingsGroups.map((g) => (
                <div key={g.name}>
                  <h4 className="text-sm font-semibold text-gray-700 mb-2">{g.name}</h4>
                  <div className="overflow-x-auto">
                    <table className="min-w-full text-sm bg-white rounded-lg border">
                      <thead>
                        <tr className="bg-gray-50 text-xs text-gray-500 uppercase">
                          <th className="px-2 py-2 text-left w-8">#</th>
                          <th className="px-2 py-2 text-left">Team</th>
                          <th className="px-2 py-2 text-center">P</th>
                          <th className="px-2 py-2 text-center">W</th>
                          <th className="px-2 py-2 text-center">D</th>
                          <th className="px-2 py-2 text-center">L</th>
                          <th className="px-2 py-2 text-center">GF</th>
                          <th className="px-2 py-2 text-center">GA</th>
                          <th className="px-2 py-2 text-center">GD</th>
                          <th className="px-2 py-2 text-center font-bold">Pts</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y">
                        {g.standings.map((s) => (
                          <tr key={s.team_name}>
                            <td className="px-2 py-2 text-gray-400">{s.position}</td>
                            <td className="px-2 py-2 font-medium">{s.team_name}</td>
                            <td className="px-2 py-2 text-center">{s.played}</td>
                            <td className="px-2 py-2 text-center">{s.won}</td>
                            <td className="px-2 py-2 text-center">{s.drawn}</td>
                            <td className="px-2 py-2 text-center">{s.lost}</td>
                            <td className="px-2 py-2 text-center">{s.goals_for}</td>
                            <td className="px-2 py-2 text-center">{s.goals_against}</td>
                            <td className="px-2 py-2 text-center">{s.goal_difference > 0 ? '+' : ''}{s.goal_difference}</td>
                            <td className="px-2 py-2 text-center font-bold">{s.points}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>
              ))
            )}
          </div>
        )}

        {/* Bracket */}
        {activeTab === 'bracket' && (
          <div className="overflow-x-auto">
            {bracketRounds.length === 0 ? (
              <p className="text-center text-gray-400 py-8">No bracket available yet.</p>
            ) : (
              <div className="flex space-x-6 pb-4">
                {bracketRounds.map((round) => (
                  <div key={round.name} className="flex-shrink-0 w-60">
                    <h5 className="text-xs font-semibold text-gray-500 uppercase mb-3 text-center">{round.name}</h5>
                    <div className="space-y-3">
                      {round.matches.map((m: any, i: number) => (
                        <div key={i} className={`border rounded-lg p-2.5 ${m.status === 'completed' ? 'border-green-200 bg-green-50' : 'border-gray-200 bg-white'}`}>
                          <div className="flex justify-between py-0.5">
                            <span className="text-sm truncate w-40">{m.home_team || m.home_placeholder || 'TBD'}</span>
                            {m.status === 'completed' && <span className="text-sm font-mono">{m.home_score}</span>}
                          </div>
                          <div className="border-t border-gray-100 my-0.5" />
                          <div className="flex justify-between py-0.5">
                            <span className="text-sm truncate w-40">{m.away_team || m.away_placeholder || 'TBD'}</span>
                            {m.status === 'completed' && <span className="text-sm font-mono">{m.away_score}</span>}
                          </div>
                        </div>
                      ))}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  );
};

export default PublicTournament;
