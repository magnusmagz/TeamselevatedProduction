import React, { useEffect, useState } from 'react';
import LineupPitch from '../../components/lineup/LineupPitch';
import { LineupCrewResponse, PitchPlayer } from '../../components/lineup/types';

/**
 * Read-only lineup on the parent portal game page (decision 1). Rendered only
 * after the coach publishes: the server answers 403 until then, and this
 * component renders nothing on anything but a 200 — an unpublished lineup is
 * not an error to a family, it simply is not there yet.
 */
interface Props {
  apiUrl: string;
  teamId: number;
  eventId: number;
}

const PublishedLineup: React.FC<Props> = ({ apiUrl, teamId, eventId }) => {
  const [data, setData] = useState<LineupCrewResponse | null>(null);

  useEffect(() => {
    let cancelled = false;
    const token = localStorage.getItem('auth_token');
    fetch(`${apiUrl}/api/lineups.php?action=get&team_id=${teamId}&event_id=${eventId}`, {
      headers: { Authorization: `Bearer ${token}` },
    })
      .then(async (res) => {
        if (!res.ok) return;
        const body = await res.json();
        if (!cancelled && body?.success && body.lineup?.published_at) setData(body as LineupCrewResponse);
      })
      .catch(() => { /* nothing to show */ });
    return () => { cancelled = true; };
  }, [apiUrl, teamId, eventId]);

  if (!data) return null;

  const players: Record<string, PitchPlayer | undefined> = {};
  data.lineup.slots.forEach((s) => {
    players[s.slot] = { athlete_id: s.athlete_id, name: s.name, jersey_number: s.jersey_number, captain: s.captain };
  });
  const mine = new Set(data.my_athlete_ids);
  const myField = data.lineup.slots.filter((s) => mine.has(s.athlete_id));
  const myBench = data.lineup.bench.filter((b) => mine.has(b.athlete_id));

  return (
    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4" data-testid="published-lineup">
      <h2 className="font-semibold text-brand-primary mb-1">Lineup</h2>
      <p className="text-xs text-gray-500 mb-3">{data.lineup.formation} · {data.lineup.field_size}</p>
      {myField.map((s) => (
        <p key={s.athlete_id} className="text-sm text-gray-800 mb-1">
          <span className="font-semibold">{s.name}</span> starts at {s.slot === 'GK' ? 'goalkeeper' : s.slot}{s.captain ? ' (captain)' : ''}.
        </p>
      ))}
      {myBench.map((b) => (
        <p key={b.athlete_id} className="text-sm text-gray-800 mb-1"><span className="font-semibold">{b.name}</span> is on the bench.</p>
      ))}
      <div className="mt-2">
        <LineupPitch fieldSize={data.lineup.field_size} formation={data.lineup.formation} players={players} highlightAthleteIds={data.my_athlete_ids} />
      </div>
      {data.lineup.bench.length > 0 && (
        <p className="mt-3 text-sm text-gray-700">
          <span className="font-semibold">Bench:</span>{' '}
          {data.lineup.bench.map((b) => `${b.jersey_number != null ? `#${b.jersey_number} ` : ''}${b.name}`).join(', ')}
        </p>
      )}
    </div>
  );
};

export default PublishedLineup;
