import React, { useEffect, useMemo, useState } from 'react';
import { useParams, useSearchParams } from 'react-router-dom';
import LineupPitch from '../components/lineup/LineupPitch';
import { LineupStaffResponse, PitchPlayer } from '../components/lineup/types';
import { BENCH } from '../utils/lineupFormations';
import { formatDateOnly } from '../utils/dateFormat';

/**
 * /teams/:teamId/lineup/print?event=:eventId — plain HTML sized for a phone
 * screenshot or one A4/Letter page. No PDF library; the same pitch drawing as
 * the builder, so the sheet on the touchline matches the screen.
 */
const LineupPrintPage: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { teamId } = useParams<{ teamId: string }>();
  const [params] = useSearchParams();
  const eventParam = params.get('event');
  const eventId = eventParam && /^\d+$/.test(eventParam) ? parseInt(eventParam, 10) : null;
  const [data, setData] = useState<LineupStaffResponse | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const token = localStorage.getItem('auth_token');
    const q = `team_id=${teamId}${eventId ? `&event_id=${eventId}` : ''}`;
    fetch(`${API_URL}/api/lineups.php?action=get&${q}`, { headers: { Authorization: `Bearer ${token}` } })
      .then(async (res) => {
        const body = await res.json();
        if (!res.ok || !body?.success || !body.can_edit) {
          setError(body?.error || `Could not load the lineup (${res.status})`);
          return;
        }
        setData(body as LineupStaffResponse);
      })
      .catch(() => setError('Could not load the lineup'));
  }, [API_URL, teamId, eventId]);

  const byId = useMemo(() => {
    const m: Record<number, LineupStaffResponse['roster'][number]> = {};
    data?.roster.forEach((p) => { m[p.athlete_id] = p; });
    return m;
  }, [data]);

  if (error) return <div className="p-6 text-red-700">{error}</div>;
  if (!data) return <div className="p-6">Loading…</div>;
  if (!data.lineup) return <div className="p-6">No lineup has been saved for this game yet.</div>;

  const { lineup } = data;
  const players: Record<string, PitchPlayer | undefined> = {};
  const bench: Array<{ id: number; name: string; jersey: number | null; note: string | null }> = [];
  for (const s of lineup.slots) {
    const p = byId[s.athlete_id];
    if (!p) continue;
    if (s.slot === BENCH) bench.push({ id: s.athlete_id, name: p.name, jersey: p.jersey_number, note: s.note });
    else players[s.slot] = { athlete_id: s.athlete_id, name: p.name, last_name: p.last_name, jersey_number: p.jersey_number, captain: s.captain };
  }
  const title = data.event
    ? `${data.event.opponent_name ? `vs ${data.event.opponent_name}` : data.event.name} — ${formatDateOnly(data.event.event_date, { weekday: 'short', month: 'short', day: 'numeric' })}${data.event.start_time ? ` ${data.event.start_time.slice(0, 5)}` : ''}`
    : 'Default lineup';

  return (
    <div style={{ maxWidth: 480, margin: '0 auto', padding: 16, fontFamily: 'system-ui, sans-serif', color: '#111' }}>
      <style>{`@media print { .no-print { display: none !important; } body { margin: 0; } @page { size: auto; margin: 10mm; } }`}</style>
      <div className="no-print" style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 8 }}>
        <button type="button" onClick={() => window.print()} style={{ padding: '8px 14px', fontWeight: 600 }}>Print</button>
      </div>
      <h1 style={{ fontSize: 18, margin: 0 }}>{data.team.name}</h1>
      <p style={{ margin: '2px 0 8px', fontSize: 14 }}>{title} · {lineup.formation} ({lineup.field_size})</p>
      <LineupPitch fieldSize={lineup.field_size} formation={lineup.formation} players={players} />
      <h2 style={{ fontSize: 14, margin: '12px 0 4px', textTransform: 'uppercase', letterSpacing: 1 }}>Bench</h2>
      {bench.length === 0 ? <p style={{ fontSize: 14 }}>—</p> : (
        <ol style={{ margin: 0, paddingLeft: 20, fontSize: 14, columns: 2 }}>
          {bench.map((b) => (
            <li key={b.id}>
              <strong>{b.jersey ?? '–'}</strong> {b.name}{b.note ? <span style={{ color: '#555' }}> — {b.note}</span> : null}
            </li>
          ))}
        </ol>
      )}
    </div>
  );
};

export default LineupPrintPage;
