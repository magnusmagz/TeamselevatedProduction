import React, { useState, useEffect, useCallback } from 'react';
import { useParams } from 'react-router-dom';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface Match {
  id: number;
  match_number: number;
  round: string;
  status: string;
  scheduled_time: string | null;
  scheduled_end_time: string | null;
  home_score: number | null;
  away_score: number | null;
  home_penalty_score: number | null;
  away_penalty_score: number | null;
  scored_at: string | null;
  field_id: number;
  field_name: string;
  division_name: string;
  group_name: string | null;
  home_team: string;
  away_team: string;
}

interface FieldGroup {
  field_id: number;
  field_name: string;
  live: Match | null;
  upcoming: Match | null;
  recent: Match | null;
}

interface ScoreboardData {
  tournament: {
    id: number;
    name: string;
    start_date: string;
    end_date: string;
    club_name: string;
    club_logo_url: string | null;
    primary_color: string | null;
  };
  server_time: string;
  fields: FieldGroup[];
}

const REFRESH_INTERVAL_MS = 30_000;

function formatTime(ts: string | null): string {
  if (!ts) return 'TBD';
  return new Date(ts).toLocaleString('en-US', {
    weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
  });
}

function formatRelativeUpcoming(ts: string | null, serverTime: string): string {
  if (!ts) return '';
  const minutes = Math.round((new Date(ts).getTime() - new Date(serverTime).getTime()) / 60000);
  if (minutes < 0) return 'starting soon';
  if (minutes < 60) return `in ${minutes} min`;
  if (minutes < 60 * 24) return `in ${Math.round(minutes / 60)} hr`;
  const days = Math.round(minutes / 60 / 24);
  return `in ${days} day${days === 1 ? '' : 's'}`;
}

const PublicLiveScoreboard: React.FC = () => {
  const { slug } = useParams<{ slug: string }>();
  const [data, setData] = useState<ScoreboardData | null>(null);
  const [loading, setLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [lastRefresh, setLastRefresh] = useState<Date>(new Date());

  const fetchData = useCallback(async () => {
    if (!slug) return;
    try {
      const res = await fetch(`${API_URL}/api/tournament-public-gateway.php?action=public-live-scoreboard&slug=${slug}`);
      if (res.status === 404) { setNotFound(true); return; }
      const d = await res.json();
      setData(d);
      setLastRefresh(new Date());
    } catch (e) {
      // non-fatal — keep last good data on screen
    } finally {
      setLoading(false);
    }
  }, [slug]);

  useEffect(() => { fetchData(); }, [fetchData]);
  useEffect(() => {
    const id = setInterval(fetchData, REFRESH_INTERVAL_MS);
    return () => clearInterval(id);
  }, [fetchData]);

  if (loading) return <div className="min-h-screen flex items-center justify-center text-gray-400 bg-gray-900">Loading…</div>;
  if (notFound || !data) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-900 text-white">
        <div className="text-center">
          <h1 className="text-2xl font-bold">Tournament Not Found</h1>
          <p className="text-gray-400 mt-2">This tournament may not exist or is not yet public.</p>
        </div>
      </div>
    );
  }

  const brand = data.tournament.primary_color || '#22c55e';

  return (
    <div className="min-h-screen bg-gray-900 text-white">
      {/* Header */}
      <header className="bg-gray-800 border-b-2" style={{ borderBottomColor: brand }}>
        <div className="max-w-7xl mx-auto px-4 sm:px-6 py-4 flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-3">
            {data.tournament.club_logo_url && (
              <img src={data.tournament.club_logo_url} alt="" className="w-10 h-10 object-contain" />
            )}
            <div>
              <p className="text-xs uppercase tracking-widest" style={{ color: brand }}>
                Live Scoreboard
              </p>
              <h1 className="text-lg sm:text-2xl font-bold">{data.tournament.name}</h1>
            </div>
          </div>
          <div className="flex items-center gap-2 text-xs text-gray-400">
            <span className="inline-block w-2 h-2 rounded-full animate-pulse" style={{ backgroundColor: brand }} />
            Updated {lastRefresh.toLocaleTimeString()} · refreshes every 30s
          </div>
        </div>
      </header>

      {/* Field grid */}
      <div className="max-w-7xl mx-auto px-4 sm:px-6 py-6">
        {data.fields.length === 0 ? (
          <div className="text-center py-20 text-gray-500">
            <p className="text-lg">No fields with matches scheduled.</p>
          </div>
        ) : (
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            {data.fields.map((f) => (
              <FieldCard key={f.field_id} field={f} brandColor={brand} serverTime={data.server_time} />
            ))}
          </div>
        )}
      </div>
    </div>
  );
};

interface CardProps {
  field: FieldGroup;
  brandColor: string;
  serverTime: string;
}

const FieldCard: React.FC<CardProps> = ({ field, brandColor, serverTime }) => {
  // Status: live > upcoming > recent > idle
  const live = field.live;
  const upcoming = field.upcoming;
  const recent = field.recent;

  const statusBadge = live
    ? { label: 'LIVE', cls: 'bg-red-500 text-white animate-pulse' }
    : upcoming
    ? { label: 'UP NEXT', cls: 'text-gray-900', style: { backgroundColor: brandColor } }
    : recent
    ? { label: 'JUST FINISHED', cls: 'bg-gray-700 text-gray-200' }
    : { label: 'IDLE', cls: 'bg-gray-800 text-gray-400' };

  return (
    <div className="bg-gray-800 border border-gray-700 rounded-xl overflow-hidden flex flex-col">
      {/* Header */}
      <div className="px-4 py-3 flex items-center justify-between bg-gray-850" style={{ backgroundColor: '#1a1f2e' }}>
        <h3 className="text-sm font-bold tracking-wide">{field.field_name}</h3>
        <span
          className={`text-[10px] font-bold uppercase tracking-widest px-2 py-0.5 rounded ${statusBadge.cls}`}
          style={(statusBadge as any).style || {}}
        >
          {statusBadge.label}
        </span>
      </div>

      {/* Body — show whichever match has highest priority */}
      <div className="flex-1 px-4 py-4">
        {live ? (
          <MatchDisplay match={live} liveFlag />
        ) : upcoming ? (
          <MatchDisplay match={upcoming} upcomingHint={formatRelativeUpcoming(upcoming.scheduled_time, serverTime)} />
        ) : recent ? (
          <MatchDisplay match={recent} finalFlag />
        ) : (
          <p className="text-gray-500 text-sm py-6 text-center">No matches</p>
        )}
      </div>

      {/* Recent (when there's also a live or upcoming above) */}
      {(live || upcoming) && recent && (
        <div className="border-t border-gray-700 px-4 py-2 bg-gray-900 text-xs">
          <div className="flex items-center justify-between text-gray-500">
            <span className="uppercase tracking-wider">Last final</span>
            <span className="font-medium text-gray-400">
              {recent.home_team} {recent.home_score}–{recent.away_score} {recent.away_team}
            </span>
          </div>
        </div>
      )}
    </div>
  );
};

const MatchDisplay: React.FC<{ match: Match; liveFlag?: boolean; finalFlag?: boolean; upcomingHint?: string }> = ({
  match,
  liveFlag,
  finalFlag,
  upcomingHint,
}) => {
  const showScore = liveFlag || finalFlag;
  const showPK =
    finalFlag &&
    match.home_penalty_score !== null &&
    match.away_penalty_score !== null;

  return (
    <div className="space-y-3">
      <div className="flex items-baseline justify-between gap-2">
        <span className="text-base sm:text-lg font-bold truncate flex-1 text-right">{match.home_team}</span>
        {showScore ? (
          <span className="text-3xl sm:text-4xl font-extrabold tabular-nums" style={{ minWidth: '2.5rem', textAlign: 'center' }}>
            {match.home_score ?? 0}
          </span>
        ) : (
          <span className="text-xs uppercase tracking-widest text-gray-500 px-2">vs</span>
        )}
      </div>
      <div className="flex items-baseline justify-between gap-2">
        <span className="text-base sm:text-lg font-bold truncate flex-1 text-right">{match.away_team}</span>
        {showScore ? (
          <span className="text-3xl sm:text-4xl font-extrabold tabular-nums" style={{ minWidth: '2.5rem', textAlign: 'center' }}>
            {match.away_score ?? 0}
          </span>
        ) : (
          <span className="text-xs invisible">vs</span>
        )}
      </div>

      {showPK && (
        <div className="text-center text-xs text-orange-400 mt-1">
          PKs {match.home_penalty_score}–{match.away_penalty_score}
        </div>
      )}

      <div className="text-xs text-gray-500 flex flex-wrap items-center gap-x-2 gap-y-0.5 pt-1">
        <span>{match.division_name}</span>
        <span>·</span>
        <span>{match.round}{match.group_name ? ` · ${match.group_name}` : ''}</span>
        <span>·</span>
        <span>#{match.match_number}</span>
      </div>

      {upcomingHint && (
        <div className="text-xs font-semibold text-gray-300 pt-1">
          {formatTime(match.scheduled_time)} <span className="text-gray-500">({upcomingHint})</span>
        </div>
      )}
    </div>
  );
};

export default PublicLiveScoreboard;
