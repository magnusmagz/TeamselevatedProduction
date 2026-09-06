import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { formatDateOnly } from '../../utils/dateFormat';

/**
 * The team page's schedule list of games with a Lineup link each (the second
 * entry point in the spec). Staff only: `action=games` answers 403 to a family,
 * and this card renders nothing on anything but a 200.
 */
interface Game {
  id: number;
  name: string;
  event_date: string;
  start_time: string | null;
  opponent_name: string | null;
  has_lineup: boolean;
  published: boolean;
}

interface Props {
  teamId: number;
  apiUrl: string;
}

const TeamLineupsCard: React.FC<Props> = ({ teamId, apiUrl }) => {
  const [games, setGames] = useState<Game[] | null>(null);
  const [available, setAvailable] = useState(true);

  useEffect(() => {
    let cancelled = false;
    const token = localStorage.getItem('auth_token');
    fetch(`${apiUrl}/api/lineups.php?action=games&team_id=${teamId}`, { headers: { Authorization: `Bearer ${token}` } })
      .then(async (res) => {
        if (!res.ok) return;
        const body = await res.json();
        if (!cancelled && body?.success) {
          setGames(body.games as Game[]);
          setAvailable(body.available !== false);
        }
      })
      .catch(() => { /* not staff, or nothing to show */ });
    return () => { cancelled = true; };
  }, [apiUrl, teamId]);

  if (games === null) return null;

  return (
    <div className="bg-white border border-brand-secondary rounded-lg mb-6">
      <div className="px-6 py-4 border-b border-brand-secondary flex justify-between items-center">
        <h2 className="text-lg font-bold text-brand-primary uppercase tracking-wide">Lineups</h2>
        <Link to={`/teams/${teamId}/lineup`} className="text-sm text-brand-primary hover:underline uppercase font-medium">
          Default lineup
        </Link>
      </div>
      {!available && (
        <p className="px-6 py-3 text-sm text-amber-800 bg-amber-50">The lineup builder is not switched on yet.</p>
      )}
      {games.length === 0 ? (
        <p className="px-6 py-6 text-sm text-gray-500">No games on the schedule yet.</p>
      ) : (
        <ul className="divide-y divide-gray-100">
          {games.map((g) => (
            <li key={g.id} className="px-6 py-3 flex items-center gap-3">
              <div className="flex-1 min-w-0">
                <div className="font-medium text-gray-900 truncate">{g.opponent_name ? `vs ${g.opponent_name}` : g.name}</div>
                <div className="text-xs text-gray-500">
                  {formatDateOnly(g.event_date, { weekday: 'short', month: 'short', day: 'numeric' })}
                  {g.start_time ? ` · ${g.start_time.slice(0, 5)}` : ''}
                  {g.has_lineup ? (g.published ? ' · published' : ' · lineup saved') : ''}
                </div>
              </div>
              <Link to={`/teams/${teamId}/lineup?event=${g.id}`} className="text-sm font-semibold uppercase text-brand-primary hover:underline">
                {g.has_lineup ? 'Lineup' : 'Set lineup'}
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
};

export default TeamLineupsCard;
