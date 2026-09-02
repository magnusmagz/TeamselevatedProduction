import React, { useState, useEffect, useCallback, useMemo } from 'react';
import PlayerCard from './PlayerCard';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface RosterPlayer {
  athlete_id: number;
  first_name: string;
  last_name: string;
  date_of_birth: string | null;
  age: number | null;
  photo_url: string | null;
  jersey_number: string | null;
  position: string | null;
  checked_in: boolean;
}

interface Props {
  matchId: number;
  registrationId: number;
  teamName?: string;
}

const RosterCheckIn: React.FC<Props> = ({ matchId, registrationId, teamName }) => {
  const [roster, setRoster] = useState<RosterPlayer[]>([]);
  const [teamDisplayName, setTeamDisplayName] = useState(teamName || '');
  const [checkedInCount, setCheckedInCount] = useState(0);
  const [rosterCount, setRosterCount] = useState(0);
  const [minRosterSize, setMinRosterSize] = useState(0);
  const [loading, setLoading] = useState(true);
  const [togglingId, setTogglingId] = useState<number | null>(null);

  const token = localStorage.getItem('auth_token');
  // Stable across renders so the fetch effects/callbacks below can depend on
  // it without re-firing on every render.
  const headers: HeadersInit = useMemo(() => ({ 'Content-Type': 'application/json', Authorization: `Bearer ${token}` }), [token]);

  const fetchRoster = useCallback(async () => {
    try {
      const res = await fetch(
        `${API_URL}/api/tournament-gateway.php?action=checkin-roster&match_id=${matchId}&registration_id=${registrationId}`,
        { headers }
      );
      const data = await res.json();
      setRoster(data.roster || []);
      setTeamDisplayName(data.team_name || teamName || '');
      setCheckedInCount(data.checked_in_count || 0);
      setRosterCount(data.roster_count || 0);
      setMinRosterSize(data.min_roster_size || 0);
    } catch (err) { console.error(err); }
    finally { setLoading(false); }
  }, [matchId, registrationId, headers]);

  useEffect(() => { fetchRoster(); }, [fetchRoster]);

  const toggleCheckIn = async (athleteId: number, currentlyCheckedIn: boolean) => {
    setTogglingId(athleteId);
    try {
      await fetch(
        `${API_URL}/api/tournament-gateway.php?action=checkin-player&match_id=${matchId}&athlete_id=${athleteId}`,
        {
          method: 'PUT', headers,
          body: JSON.stringify({ registration_id: registrationId, checked_in: !currentlyCheckedIn }),
        }
      );
      // Optimistic update
      setRoster((prev) =>
        prev.map((p) =>
          p.athlete_id === athleteId ? { ...p, checked_in: !currentlyCheckedIn } : p
        )
      );
      setCheckedInCount((prev) => currentlyCheckedIn ? prev - 1 : prev + 1);
    } catch (err) { alert('Failed to update check-in'); }
    finally { setTogglingId(null); }
  };

  const belowMinimum = checkedInCount < minRosterSize && rosterCount > 0;

  if (loading) return <div className="text-sm text-gray-500 py-4">Loading roster...</div>;

  return (
    <div>
      <div className="flex justify-between items-center mb-3">
        <h4 className="font-semibold text-gray-900">{teamDisplayName}</h4>
        <div className="text-sm">
          <span className={`font-medium ${belowMinimum ? 'text-red-600' : 'text-green-600'}`}>
            {checkedInCount}/{rosterCount} checked in
          </span>
          {minRosterSize > 0 && (
            <span className="text-gray-400 ml-1">(min {minRosterSize})</span>
          )}
        </div>
      </div>

      {belowMinimum && (
        <div className="bg-red-50 border border-red-200 rounded-md p-2 mb-3 text-sm text-red-700">
          Below minimum roster size ({checkedInCount}/{minRosterSize}). Need {minRosterSize - checkedInCount} more players.
        </div>
      )}

      <div className="space-y-2">
        {roster.map((player) => (
          <button
            key={player.athlete_id}
            onClick={() => toggleCheckIn(player.athlete_id, player.checked_in)}
            disabled={togglingId === player.athlete_id}
            className="w-full text-left disabled:opacity-50"
          >
            <PlayerCard
              firstName={player.first_name}
              lastName={player.last_name}
              dateOfBirth={player.date_of_birth}
              age={player.age}
              jerseyNumber={player.jersey_number}
              position={player.position}
              photoUrl={player.photo_url}
              checkedIn={player.checked_in}
            />
          </button>
        ))}
      </div>
    </div>
  );
};

export default RosterCheckIn;
