import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { Tournament, TournamentMatch, TOURNAMENT_STATUS_CONFIG } from '../types';
import { updateTournamentStatus } from '../api/tournamentApi';
import MatchCenterModal from './MatchCenterModal';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface BoardMatch extends TournamentMatch {
  division_name?: string;
}

interface BoardData {
  tournament: { id: number; name: string; status: string };
  date: string;
  now: string;
  live: BoardMatch[];
  upcoming: BoardMatch[];
  recent: BoardMatch[];
}

interface Props {
  tournament: Tournament;
  isAdmin: boolean;
  onTournamentUpdated: () => void;
}

const REFRESH_INTERVAL_MS = 30_000;

function formatTime(iso: string | null | undefined): string {
  if (!iso) return 'TBD';
  return new Date(iso.replace(' ', 'T')).toLocaleTimeString('en-US', {
    hour: 'numeric', minute: '2-digit',
  });
}

function todayIso(): string {
  const d = new Date();
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

const GameDayBoard: React.FC<Props> = ({ tournament, isAdmin, onTournamentUpdated }) => {
  const [date, setDate] = useState<string>(todayIso());
  const [data, setData] = useState<BoardData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string>('');
  const [statusUpdating, setStatusUpdating] = useState(false);
  const [openMatch, setOpenMatch] = useState<BoardMatch | null>(null);

  const token = localStorage.getItem('auth_token');
  const headers: HeadersInit = { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` };

  const fetchBoard = useCallback(async () => {
    try {
      const res = await fetch(
        `${API_URL}/api/tournament-gateway.php?action=tournament-game-day&tournament_id=${tournament.id}&date=${date}`,
        { headers }
      );
      if (!res.ok) throw new Error('Failed to load game day board');
      const json = await res.json();
      setData(json);
      setError('');
    } catch (e: any) {
      setError(e.message || 'Failed to load board');
    } finally {
      setLoading(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tournament.id, date]);

  useEffect(() => {
    setLoading(true);
    fetchBoard();
    const id = setInterval(fetchBoard, REFRESH_INTERVAL_MS);
    return () => clearInterval(id);
  }, [fetchBoard]);

  // Tournament dates frame the date picker so a director can step through
  // each day. Falls back to today-only if the tournament has no dates.
  const dayOptions = useMemo(() => {
    if (!tournament.start_date || !tournament.end_date) return [todayIso()];
    const out: string[] = [];
    const start = new Date(tournament.start_date + 'T00:00:00');
    const end = new Date(tournament.end_date + 'T00:00:00');
    for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
      const pad = (n: number) => String(n).padStart(2, '0');
      out.push(`${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`);
    }
    return out;
  }, [tournament.start_date, tournament.end_date]);

  const handleToggleWeatherDelay = async () => {
    if (!isAdmin) return;
    const target = data?.tournament.status === 'weather_delay' ? 'in_progress' : 'weather_delay';
    if (target === 'weather_delay'
      && !window.confirm('Put the tournament into weather delay? Teams will see a delay banner on the public page.')) return;
    setStatusUpdating(true);
    try {
      await updateTournamentStatus(tournament.id, target as any);
      await fetchBoard();
      onTournamentUpdated();
    } catch (e: any) {
      setError(e.message || 'Failed to update tournament status');
    } finally {
      setStatusUpdating(false);
    }
  };

  const statusConfig = data ? TOURNAMENT_STATUS_CONFIG[data.tournament.status as keyof typeof TOURNAMENT_STATUS_CONFIG] : null;
  const isInProgressOrDelay = data && (data.tournament.status === 'in_progress' || data.tournament.status === 'weather_delay');

  if (loading && !data) {
    return <div className="text-center py-12 text-gray-500">Loading game day board…</div>;
  }

  return (
    <div>
      {error && (
        <div className="mb-4 bg-red-50 border border-red-200 rounded-md p-3 text-red-700 text-sm">{error}</div>
      )}

      {/* Top control bar */}
      <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-4 p-3 bg-white border border-gray-200 rounded-lg">
        <div className="flex items-center gap-3 flex-wrap">
          {statusConfig && (
            <span className={`text-xs font-semibold px-2 py-1 rounded ${statusConfig.color}`}>
              {statusConfig.label}
            </span>
          )}
          <select
            value={date}
            onChange={(e) => setDate(e.target.value)}
            className="border border-gray-300 rounded-md px-3 py-1.5 text-sm"
          >
            {dayOptions.map((d) => (
              <option key={d} value={d}>{new Date(d + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' })}</option>
            ))}
          </select>
          <span className="text-xs text-gray-400">Auto-refreshes every 30s</span>
        </div>
        <div className="flex items-center gap-2">
          {isAdmin && isInProgressOrDelay && (
            <button
              onClick={handleToggleWeatherDelay}
              disabled={statusUpdating}
              className={`px-3 py-1.5 rounded-md text-sm font-medium border ${
                data?.tournament.status === 'weather_delay'
                  ? 'bg-green-600 text-white border-green-600 hover:bg-green-700'
                  : 'bg-orange-100 text-orange-800 border-orange-300 hover:bg-orange-200'
              } disabled:opacity-50`}
            >
              {statusUpdating
                ? 'Saving…'
                : data?.tournament.status === 'weather_delay'
                  ? '☀️ Resume play'
                  : '⛈️ Weather delay'}
            </button>
          )}
          <button
            onClick={() => window.print()}
            className="px-3 py-1.5 rounded-md text-sm font-medium border border-gray-300 text-gray-700 hover:bg-gray-50"
            title="Print or project this view"
          >
            🖨️ Print / project
          </button>
        </div>
      </div>

      {/* Three lanes */}
      <div className="grid gap-4 md:grid-cols-3">
        <Lane
          title="Live now"
          accent="bg-red-50 border-red-200 text-red-800"
          dot="bg-red-500 animate-pulse"
          matches={data?.live || []}
          emptyMessage="No matches in play right now."
          renderTrailing={(m) => <ScorePill match={m} />}
          onClickMatch={(m) => setOpenMatch(m)}
          isAdmin={isAdmin}
        />
        <Lane
          title="Up next"
          accent="bg-blue-50 border-blue-200 text-blue-800"
          dot="bg-blue-500"
          matches={data?.upcoming || []}
          emptyMessage="No matches scheduled in the next few hours."
          renderTrailing={(m) => <span className="text-sm font-medium text-gray-900">{formatTime(m.scheduled_time)}</span>}
          onClickMatch={(m) => setOpenMatch(m)}
          isAdmin={isAdmin}
        />
        <Lane
          title="Just finished"
          accent="bg-gray-100 border-gray-200 text-gray-700"
          dot="bg-gray-400"
          matches={data?.recent || []}
          emptyMessage="No matches finished in the last two hours."
          renderTrailing={(m) => <ScorePill match={m} />}
          onClickMatch={(m) => setOpenMatch(m)}
          isAdmin={isAdmin}
        />
      </div>

      {openMatch && (
        <MatchCenterModal
          match={openMatch as TournamentMatch}
          isKnockout={(openMatch.round || '').toLowerCase() !== 'group stage'}
          onClose={() => setOpenMatch(null)}
          onSaved={() => { setOpenMatch(null); fetchBoard(); }}
        />
      )}
    </div>
  );
};

interface LaneProps {
  title: string;
  accent: string;
  dot: string;
  matches: BoardMatch[];
  emptyMessage: string;
  renderTrailing: (m: BoardMatch) => React.ReactNode;
  onClickMatch: (m: BoardMatch) => void;
  isAdmin: boolean;
}

const Lane: React.FC<LaneProps> = ({ title, accent, dot, matches, emptyMessage, renderTrailing, onClickMatch, isAdmin }) => (
  <section className={`border rounded-lg overflow-hidden ${accent}`}>
    <header className="px-4 py-2 flex items-center justify-between bg-white/50 border-b border-current/20">
      <div className="flex items-center gap-2">
        <span className={`w-2 h-2 rounded-full ${dot}`} />
        <h3 className="text-sm font-semibold uppercase tracking-wide">{title}</h3>
      </div>
      <span className="text-xs font-medium">{matches.length}</span>
    </header>
    <div className="bg-white">
      {matches.length === 0 ? (
        <p className="text-xs text-gray-400 italic px-4 py-6 text-center">{emptyMessage}</p>
      ) : (
        <ul className="divide-y divide-gray-100">
          {matches.map((m) => (
            <li
              key={m.id}
              onClick={() => isAdmin && onClickMatch(m)}
              className={`px-4 py-3 ${isAdmin ? 'cursor-pointer hover:bg-gray-50' : ''}`}
            >
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                  <div className="text-sm font-medium text-gray-900 truncate">
                    {m.home_team_name || m.home_placeholder || 'TBD'}
                    <span className="text-gray-400 mx-1">vs</span>
                    {m.away_team_name || m.away_placeholder || 'TBD'}
                  </div>
                  <div className="text-xs text-gray-500 mt-0.5 flex flex-wrap gap-x-2">
                    <span>{m.division_name}</span>
                    {m.group_name && <span>· {m.group_name}</span>}
                    {!m.group_name && m.round && m.round !== 'Group Stage' && <span>· {m.round}</span>}
                    {m.field_name && <span>· {m.field_name}</span>}
                  </div>
                </div>
                <div className="flex-shrink-0 text-right">
                  {renderTrailing(m)}
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  </section>
);

const ScorePill: React.FC<{ match: BoardMatch }> = ({ match }) => {
  const hs = match.home_score;
  const as = match.away_score;
  if (hs == null || as == null) {
    return <span className="text-xs text-gray-400">—</span>;
  }
  const showPK = match.home_penalty_score != null && match.away_penalty_score != null && hs === as;
  return (
    <div className="text-right">
      <div className="text-base font-bold text-gray-900 tabular-nums">{hs} – {as}</div>
      {showPK && (
        <div className="text-xs text-gray-500 tabular-nums">PKs {match.home_penalty_score}–{match.away_penalty_score}</div>
      )}
    </div>
  );
};

export default GameDayBoard;
