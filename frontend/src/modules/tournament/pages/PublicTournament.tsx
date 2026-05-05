import React, { useState, useEffect, useCallback } from 'react';
import { useParams } from 'react-router-dom';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';

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
  contact_phone: string | null;
  rules_document_url: string | null;
  faq_markdown: string | null;
  governing_body: string | null;
  sanction_number: string | null;
  state_association: string | null;
  host_name: string | null;
  // Venue microsite extras (joined from venues, see tournament-by-slug)
  venue_map_url?: string | null;
  venue_notes?: string | null;
  venue_parking_notes?: string | null;
  venue_gate_code?: string | null;
  venue_latitude?: number | string | null;
  venue_longitude?: number | string | null;
  venue_contact_name?: string | null;
  venue_contact_phone?: string | null;
  venue_contact_email?: string | null;
  medical_coordinator_name?: string | null;
  medical_coordinator_phone?: string | null;
  medical_coordinator_email?: string | null;
  medical_station_notes?: string | null;
  club_name: string;
  club_logo_url: string | null;
  primary_color: string | null;
  secondary_color: string | null;
  divisions: {
    id: number;
    name: string;
    age_group: string;
    gender: string;
    format: string;
    sport_rule_notes: string[] | null;
    game_duration_minutes?: number;
    half_duration_minutes?: number;
    overtime_rules?: {
      mode: 'none' | 'pks_only' | 'overtime_then_pks';
      ot_periods?: number;
      ot_minutes_per_period?: number;
      golden_goal?: boolean;
    } | null;
    competitive_level?: string | null;
  }[];
  sponsors?: { id: number; name: string; website: string | null; logo_data: string | null }[];
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

/**
 * Build a Google Maps directions URL for a destination address.
 * Works on desktop (opens maps.google.com) and mobile (iOS/Android route to
 * the OS's default maps app via Google's universal handler).
 */
function getDirectionsUrl(parts: { name?: string | null; address?: string | null; city?: string | null; state?: string | null; zip?: string | null; lat?: number | string | null; lng?: number | string | null }): string | null {
  // GPS coordinates beat address strings — Google interprets lat,lng as a
  // pin instead of fuzzy-matching "Soccer Park, City" which can route to the
  // wrong venue.
  if (parts.lat != null && parts.lng != null && parts.lat !== '' && parts.lng !== '') {
    return `https://www.google.com/maps/dir/?api=1&destination=${parts.lat},${parts.lng}`;
  }
  const segments = [parts.name, parts.address, parts.city, parts.state, parts.zip].filter(Boolean);
  if (segments.length === 0) return null;
  const destination = segments.join(', ');
  return `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(destination)}`;
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
  const [activeTab, setActiveTab] = useState<'schedule' | 'standings' | 'bracket' | 'info'>('schedule');
  const [activeDivision, setActiveDivision] = useState<number | null>(null);

  // Data states
  const [matches, setMatches] = useState<PublicMatch[]>([]);
  const [standingsGroups, setStandingsGroups] = useState<{ name: string; standings: StandingEntry[] }[]>([]);
  const [standingsTiebreakers, setStandingsTiebreakers] = useState<string[] | null>(null);
  const [standingsAdvancing, setStandingsAdvancing] = useState<number>(0);
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
      setStandingsTiebreakers(data.tiebreaker_rules || null);
      setStandingsAdvancing(data.teams_advancing_per_group || 0);
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
  const venueName = tournament.venue_name || tournament.location_name;
  const venueCity = tournament.venue_city || tournament.location_city;
  const venueState = tournament.venue_state || tournament.location_state;
  const directionsUrl = getDirectionsUrl({
    name: venueName,
    address: tournament.venue_address || tournament.location_address,
    lat: tournament.venue_latitude,
    lng: tournament.venue_longitude,
    city: venueCity,
    state: venueState,
    zip: tournament.venue_zip,
  });

  return (
    <div className="min-h-screen bg-gray-50">
      {/* Hero Header */}
      <header className="bg-white border-b-4" style={{ borderBottomColor: brandColor }}>
        <div className="max-w-5xl mx-auto px-4 sm:px-6 py-8">
          <div className="flex items-start gap-4">
            {tournament.club_logo_url && (
              <img src={tournament.club_logo_url} alt="" className="w-16 h-16 sm:w-20 sm:h-20 object-contain flex-shrink-0" />
            )}
            <div className="flex-1 min-w-0">
              <p className="text-xs font-semibold uppercase tracking-wide" style={{ color: brandColor }}>
                {tournament.host_name
                  ? <>Hosted by · {tournament.host_name}</>
                  : tournament.club_name}
              </p>
              <h1 className="text-2xl sm:text-4xl font-extrabold text-gray-900 mt-1 leading-tight">
                {tournament.name}
              </h1>
              {tournament.description && (
                <p className="text-sm sm:text-base text-gray-600 mt-2 max-w-2xl">
                  {tournament.description}
                </p>
              )}

              {/* Meta row */}
              <div className="flex flex-wrap items-center gap-x-4 gap-y-1 mt-4 text-sm text-gray-700">
                <span className="inline-flex items-center gap-1">
                  <svg className="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                  </svg>
                  {formatDate(tournament.start_date)} – {formatDate(tournament.end_date)}
                </span>
                {venueName && (
                  <span className="inline-flex items-center gap-1">
                    <svg className="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    {venueName}
                    {venueCity && (
                      <span className="text-gray-400 ml-1">
                        · {venueCity}{venueState ? `, ${venueState}` : ''}
                      </span>
                    )}
                    {directionsUrl && (
                      <a
                        href={directionsUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="ml-2 inline-flex items-center gap-1 text-xs font-medium px-2 py-0.5 rounded border border-gray-200 hover:bg-gray-50"
                        title="Open in Google Maps"
                      >
                        🧭 Directions
                      </a>
                    )}
                  </span>
                )}
                <span className="capitalize text-gray-500">{tournament.sport}</span>
              </div>

              {/* Sanctioning badge — visible compliance signal for cup orgs */}
              {tournament.governing_body && tournament.governing_body !== 'unaffiliated' && (
                <div className="mt-3">
                  <span className="inline-flex items-center gap-1.5 text-xs font-medium px-2.5 py-1 rounded-full border border-gray-200 bg-gray-50 text-gray-700">
                    🛡️
                    <span>
                      Sanctioned by <strong className="text-gray-900">{({
                        usys: 'USYS',
                        us_club: 'US Club Soccer',
                        ayso: 'AYSO',
                        aau: 'AAU',
                        state: tournament.state_association || 'state association',
                      } as Record<string, string>)[tournament.governing_body] || tournament.governing_body}</strong>
                      {tournament.sanction_number && <> · #{tournament.sanction_number}</>}
                      {tournament.state_association && tournament.governing_body !== 'state' && (
                        <span className="text-gray-500"> · {tournament.state_association}</span>
                      )}
                    </span>
                  </span>
                </div>
              )}

              {/* Contact + rules + live row */}
              <div className="flex flex-wrap items-center gap-x-4 gap-y-1 mt-3 text-xs text-gray-500">
                <a
                  href={`/tournament/${slug}/live`}
                  className="inline-flex items-center gap-1 px-2 py-0.5 rounded font-semibold text-white"
                  style={{ backgroundColor: brandColor }}
                >
                  <span className="inline-block w-1.5 h-1.5 rounded-full bg-white animate-pulse" />
                  Live Scoreboard
                </a>
                {tournament.contact_name && <span>Contact: <span className="text-gray-700 font-medium">{tournament.contact_name}</span></span>}
                {tournament.contact_email && (
                  <a href={`mailto:${tournament.contact_email}`} className="hover:underline" style={{ color: brandColor }}>
                    ✉ {tournament.contact_email}
                  </a>
                )}
                {tournament.contact_phone && (
                  <a href={`tel:${tournament.contact_phone}`} className="hover:underline" style={{ color: brandColor }}>
                    ☎ {tournament.contact_phone}
                  </a>
                )}
                {tournament.rules_document_url && (
                  <a href={tournament.rules_document_url} target="_blank" rel="noopener noreferrer" className="hover:underline" style={{ color: brandColor }}>
                    📄 Rules & policies
                  </a>
                )}
              </div>
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
          {(['schedule', 'standings', 'bracket', ...((tournament.faq_markdown || tournament.divisions.length > 0) ? ['info'] : [])] as const).map((tab) => (
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
              matches.map((m, i) => {
                const fieldDirUrl = m.field_name && (tournament.venue_address || tournament.location_address)
                  ? getDirectionsUrl({
                      name: `${tournament.venue_name || tournament.location_name || ''} ${m.field_name}`.trim(),
                      address: tournament.venue_address || tournament.location_address,
                      city: tournament.venue_city || tournament.location_city,
                      state: tournament.venue_state || tournament.location_state,
                      zip: tournament.venue_zip,
                    })
                  : null;
                return (
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
                      {m.field_name && (
                        <div className="inline-flex items-center gap-1">
                          <span>{m.field_name}</span>
                          {fieldDirUrl && (
                            <a
                              href={fieldDirUrl}
                              target="_blank"
                              rel="noopener noreferrer"
                              onClick={(e) => e.stopPropagation()}
                              className="text-gray-400 hover:text-gray-700"
                              title="Get directions"
                              aria-label={`Get directions to ${m.field_name}`}
                            >
                              🧭
                            </a>
                          )}
                        </div>
                      )}
                      {m.group_name && <div className="text-gray-400">{m.group_name}</div>}
                    </div>
                  </div>
                );
              })
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
                        {g.standings.map((s) => {
                          const advancing = standingsAdvancing > 0 && s.position <= standingsAdvancing;
                          return (
                            <tr key={s.team_name} className={advancing ? 'bg-green-50' : ''}>
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
                          );
                        })}
                      </tbody>
                    </table>
                  </div>
                  {(standingsAdvancing > 0 || (standingsTiebreakers && standingsTiebreakers.length > 0)) && (
                    <p className="text-xs text-gray-500 mt-2">
                      {standingsAdvancing > 0 && (
                        <>
                          <span className="inline-block w-2 h-2 bg-green-200 rounded-sm mr-1 align-middle" />
                          Top {standingsAdvancing} advance
                        </>
                      )}
                      {standingsAdvancing > 0 && standingsTiebreakers && standingsTiebreakers.length > 0 && (
                        <span className="text-gray-400"> · </span>
                      )}
                      {standingsTiebreakers && standingsTiebreakers.length > 0 && (
                        <span title="Order applied when teams are tied">
                          Tiebreakers: {formatTiebreakerChain(standingsTiebreakers)}
                        </span>
                      )}
                    </p>
                  )}
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

        {/* Info / FAQ */}
        {activeTab === 'info' && (
          <div className="space-y-6 max-w-3xl">
            {/* Venue & Parking — public-facing venue extras */}
            {(tournament.venue_parking_notes || tournament.venue_gate_code || tournament.venue_notes
              || tournament.venue_contact_name || tournament.venue_map_url) && (
              <div className="bg-white rounded-lg border border-gray-200 p-6">
                <h3 className="text-lg font-semibold text-gray-900 mb-3">Venue & Parking</h3>
                <dl className="space-y-3 text-sm text-gray-700">
                  {tournament.venue_gate_code && (
                    <div>
                      <dt className="text-xs uppercase tracking-wide text-gray-500">Gate code</dt>
                      <dd className="font-mono font-medium text-gray-900 mt-0.5">{tournament.venue_gate_code}</dd>
                    </div>
                  )}
                  {tournament.venue_parking_notes && (
                    <div>
                      <dt className="text-xs uppercase tracking-wide text-gray-500">Parking</dt>
                      <dd className="mt-0.5 whitespace-pre-line">{tournament.venue_parking_notes}</dd>
                    </div>
                  )}
                  {tournament.venue_notes && (
                    <div>
                      <dt className="text-xs uppercase tracking-wide text-gray-500">Notes</dt>
                      <dd className="mt-0.5 whitespace-pre-line">{tournament.venue_notes}</dd>
                    </div>
                  )}
                  {tournament.venue_contact_name && (
                    <div>
                      <dt className="text-xs uppercase tracking-wide text-gray-500">On-site contact</dt>
                      <dd className="mt-0.5">
                        {tournament.venue_contact_name}
                        {tournament.venue_contact_phone && (
                          <> · <a href={`tel:${tournament.venue_contact_phone}`} className="hover:underline" style={{ color: brandColor }}>{tournament.venue_contact_phone}</a></>
                        )}
                      </dd>
                    </div>
                  )}
                </dl>
                {tournament.venue_map_url && (
                  <div className="mt-4">
                    <p className="text-xs uppercase tracking-wide text-gray-500 mb-2">Field layout</p>
                    <img
                      src={tournament.venue_map_url}
                      alt={`${venueName || 'Venue'} field layout`}
                      className="max-w-full h-auto rounded border border-gray-200"
                    />
                  </div>
                )}
              </div>
            )}

            {/* Medical / Emergency — on-site safety info */}
            {(tournament.medical_coordinator_name || tournament.medical_station_notes) && (
              <div className="bg-white rounded-lg border border-red-200 p-6">
                <h3 className="text-lg font-semibold text-gray-900 mb-3">
                  <span className="text-red-600">⚕</span> On-site Medical
                </h3>
                <dl className="space-y-3 text-sm text-gray-700">
                  {tournament.medical_coordinator_name && (
                    <div>
                      <dt className="text-xs uppercase tracking-wide text-gray-500">Medical coordinator</dt>
                      <dd className="mt-0.5">
                        <strong className="text-gray-900">{tournament.medical_coordinator_name}</strong>
                        {tournament.medical_coordinator_phone && (
                          <> · <a href={`tel:${tournament.medical_coordinator_phone}`} className="hover:underline" style={{ color: brandColor }}>{tournament.medical_coordinator_phone}</a></>
                        )}
                      </dd>
                    </div>
                  )}
                  {tournament.medical_station_notes && (
                    <div>
                      <dt className="text-xs uppercase tracking-wide text-gray-500">First-aid station</dt>
                      <dd className="mt-0.5 whitespace-pre-line">{tournament.medical_station_notes}</dd>
                    </div>
                  )}
                </dl>
              </div>
            )}

            {/* Format Rules — game length + overtime per division */}
            {tournament.divisions.length > 0 && (
              <div className="bg-white rounded-lg border border-gray-200 p-6">
                <h3 className="text-lg font-semibold text-gray-900 mb-3">Format Rules</h3>
                <div className="space-y-4">
                  {tournament.divisions.map((d) => {
                    const ot = d.overtime_rules;
                    const otText = !ot || ot.mode === 'none'
                      ? 'No overtime — ties stand'
                      : ot.mode === 'pks_only'
                        ? 'Penalty shootout if tied at full time'
                        : ot.mode === 'overtime_then_pks'
                          ? `${ot.ot_periods ?? 2} × ${ot.ot_minutes_per_period ?? 5} min ${ot.golden_goal ? 'golden-goal ' : ''}overtime, then PKs if still tied`
                          : '';
                    const gameLength = d.half_duration_minutes
                      ? `2 × ${d.half_duration_minutes} min`
                      : (d.game_duration_minutes ? `${d.game_duration_minutes} min` : null);
                    return (
                      <div key={d.id} className="border-b border-gray-100 last:border-b-0 pb-3 last:pb-0">
                        <div className="text-sm font-semibold text-gray-900">
                          {d.name}
                          {d.competitive_level && (
                            <span className="ml-2 text-xs font-normal text-gray-500 capitalize">{d.competitive_level}</span>
                          )}
                        </div>
                        <dl className="mt-1 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 text-xs text-gray-600">
                          {gameLength && (
                            <div><dt className="inline text-gray-500">Game length:</dt> <dd className="inline font-medium text-gray-800">{gameLength}</dd></div>
                          )}
                          <div><dt className="inline text-gray-500">Overtime:</dt> <dd className="inline font-medium text-gray-800">{otText}</dd></div>
                        </dl>
                      </div>
                    );
                  })}
                </div>
              </div>
            )}

            {tournament.faq_markdown && (
              <div className="bg-white rounded-lg border border-gray-200 p-6">
                <article className="prose prose-sm max-w-none text-gray-800">
                  <ReactMarkdown remarkPlugins={[remarkGfm]}>
                    {tournament.faq_markdown}
                  </ReactMarkdown>
                </article>
              </div>
            )}
          </div>
        )}
      </div>

      {/* Sponsor strip (footer) */}
      {tournament.sponsors && tournament.sponsors.length > 0 && (
        <footer className="border-t border-gray-200 bg-white mt-8">
          <div className="max-w-5xl mx-auto px-4 sm:px-6 py-6">
            <p className="text-xs font-semibold uppercase tracking-wide text-gray-400 text-center mb-4">
              Tournament Sponsors
            </p>
            <div className="flex flex-wrap items-center justify-center gap-x-8 gap-y-4">
              {tournament.sponsors.map((s) => {
                const inner = s.logo_data ? (
                  <img
                    src={s.logo_data}
                    alt={s.name}
                    className="h-12 sm:h-14 max-w-[160px] object-contain grayscale hover:grayscale-0 transition"
                  />
                ) : (
                  <span className="text-sm font-medium text-gray-600">{s.name}</span>
                );
                return s.website ? (
                  <a
                    key={s.id}
                    href={s.website}
                    target="_blank"
                    rel="noopener noreferrer"
                    title={s.name}
                    className="opacity-80 hover:opacity-100 transition"
                  >
                    {inner}
                  </a>
                ) : (
                  <div key={s.id} title={s.name} className="opacity-80">
                    {inner}
                  </div>
                );
              })}
            </div>
          </div>
        </footer>
      )}
    </div>
  );
};

export default PublicTournament;
