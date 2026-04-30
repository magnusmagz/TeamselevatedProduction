import React, { useState, useEffect, useCallback } from 'react';
import { TournamentMatch } from '../types';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface Props {
  match: TournamentMatch;
  isKnockout: boolean;
  onClose: () => void;
  onSaved: () => void;
}

interface MatchEvent {
  id: number;
  registration_id: number;
  event_type: string;
  minute: number | null;
  athlete_id: number | null;
  details: { player_name?: string; notes?: string } | null;
  team_name?: string;
  athlete_name?: string;
  free_text_player?: string;
}

type Tab = 'score' | 'report' | 'notes';

/**
 * Match Center modal — replaces the inline ScoreEntry. Three tabs:
 *   - Score: home/away (and PKs for knockout)
 *   - Referee Report: cards (yellow/red), conditions, incident, photo
 *   - Notes: director-only free text on tournament_matches.notes
 *
 * Cards persist immediately via match-event-add / match-event-delete.
 * Score persists on Save Score (PUT match-score or match-score-knockout).
 * Notes/conditions/incident/photo persist on Save Report or Save Notes
 * (PUT match-update). All four save paths can be used independently.
 */
const MatchCenterModal: React.FC<Props> = ({ match, isKnockout, onClose, onSaved }) => {
  const [tab, setTab] = useState<Tab>('score');

  // Score tab state
  const [homeScore, setHomeScore] = useState<string>(match.home_score?.toString() ?? '');
  const [awayScore, setAwayScore] = useState<string>(match.away_score?.toString() ?? '');
  const [homePK, setHomePK] = useState<string>(match.home_penalty_score?.toString() ?? '');
  const [awayPK, setAwayPK] = useState<string>(match.away_penalty_score?.toString() ?? '');

  // Report tab state
  const [events, setEvents] = useState<MatchEvent[]>([]);
  const [conditions, setConditions] = useState<string>((match as any).field_conditions ?? '');
  const [incident, setIncident] = useState<string>((match as any).incident_report ?? '');
  const [photoUrl, setPhotoUrl] = useState<string | null>((match as any).match_card_photo_url ?? null);

  // Notes tab state
  const [notes, setNotes] = useState<string>(match.notes ?? '');

  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string>('');

  const token = typeof window !== 'undefined' ? localStorage.getItem('auth_token') : null;
  const headers: HeadersInit = { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` };

  // ---------- Events (cards) ----------

  const fetchEvents = useCallback(async () => {
    try {
      const res = await fetch(`${API_URL}/api/tournament-gateway.php?action=match-events-list&match_id=${match.id}`, { headers });
      const data = await res.json();
      setEvents(data.events || []);
    } catch (e) { /* non-fatal */ }
  }, [match.id]);

  useEffect(() => { fetchEvents(); }, [fetchEvents]);

  const isDraw = homeScore !== '' && awayScore !== '' && homeScore === awayScore;
  const showPKs = isKnockout && isDraw;

  // ---------- Save handlers ----------

  const handleSaveScore = async () => {
    setError('');
    if (homeScore === '' || awayScore === '') { setError('Enter both scores'); return; }
    const hs = parseInt(homeScore, 10);
    const as = parseInt(awayScore, 10);
    if (hs < 0 || as < 0) { setError('Scores cannot be negative'); return; }

    const action = isKnockout ? 'match-score-knockout' : 'match-score';
    const body: any = { home_score: hs, away_score: as };
    if (isKnockout && hs === as) {
      if (homePK === '' || awayPK === '') { setError('Enter penalty scores for the shootout'); return; }
      body.home_penalty_score = parseInt(homePK, 10);
      body.away_penalty_score = parseInt(awayPK, 10);
    }

    setSaving(true);
    try {
      const res = await fetch(`${API_URL}/api/tournament-gateway.php?action=${action}&id=${match.id}`, {
        method: 'PUT', headers, body: JSON.stringify(body),
      });
      if (!res.ok) { const err = await res.json(); throw new Error(err.error || 'Save failed'); }
      onSaved();
    } catch (e: any) {
      setError(e.message || 'Save failed');
    } finally {
      setSaving(false);
    }
  };

  const handleSaveReport = async () => {
    setError('');
    setSaving(true);
    try {
      const res = await fetch(`${API_URL}/api/tournament-gateway.php?action=match-update&id=${match.id}`, {
        method: 'PUT', headers, body: JSON.stringify({
          field_conditions: conditions,
          incident_report: incident,
          match_card_photo_url: photoUrl,
        }),
      });
      if (!res.ok) { const err = await res.json(); throw new Error(err.error || 'Save failed'); }
      onSaved();
    } catch (e: any) {
      setError(e.message || 'Save failed');
    } finally {
      setSaving(false);
    }
  };

  const handleSaveNotes = async () => {
    setError('');
    setSaving(true);
    try {
      const res = await fetch(`${API_URL}/api/tournament-gateway.php?action=match-update&id=${match.id}`, {
        method: 'PUT', headers, body: JSON.stringify({ notes }),
      });
      if (!res.ok) { const err = await res.json(); throw new Error(err.error || 'Save failed'); }
      onSaved();
    } catch (e: any) {
      setError(e.message || 'Save failed');
    } finally {
      setSaving(false);
    }
  };

  const handleAddCard = async (type: 'yellow_card' | 'red_card', side: 'home' | 'away') => {
    const playerName = window.prompt(`Player name (${side === 'home' ? match.home_team_name : match.away_team_name}):`);
    if (!playerName) return;
    const minuteRaw = window.prompt('Minute (1-90+, optional):', '');
    const registrationId = side === 'home' ? match.home_registration_id : match.away_registration_id;
    if (!registrationId) { setError('Cannot add a card: this match has no team slotted on that side'); return; }

    try {
      const res = await fetch(`${API_URL}/api/tournament-gateway.php?action=match-event-add&match_id=${match.id}`, {
        method: 'POST', headers, body: JSON.stringify({
          event_type: type,
          registration_id: registrationId,
          minute: minuteRaw ? parseInt(minuteRaw, 10) : null,
          player_name: playerName,
        }),
      });
      if (!res.ok) { const err = await res.json(); throw new Error(err.error || 'Add card failed'); }
      fetchEvents();
    } catch (e: any) {
      setError(e.message || 'Add card failed');
    }
  };

  const handleDeleteEvent = async (eventId: number) => {
    if (!window.confirm('Remove this event?')) return;
    try {
      await fetch(`${API_URL}/api/tournament-gateway.php?action=match-event-delete&id=${eventId}`, {
        method: 'DELETE', headers,
      });
      fetchEvents();
    } catch (e) { /* non-fatal */ }
  };

  const handlePhotoUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setError('');
    setSaving(true);
    try {
      const formData = new FormData();
      formData.append('file', file);
      formData.append('type', 'match-cards');
      const res = await fetch(`${API_URL}/api/upload.php`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}` },
        body: formData,
      });
      if (!res.ok) { const err = await res.json(); throw new Error(err.error || 'Upload failed'); }
      const data = await res.json();
      const url = data.url || data.path || null;
      if (url) setPhotoUrl(url);
    } catch (e: any) {
      setError(e.message || 'Upload failed');
    } finally {
      setSaving(false);
    }
  };

  const yellowCards = events.filter((e) => e.event_type === 'yellow_card' || e.event_type === 'second_yellow');
  const redCards = events.filter((e) => e.event_type === 'red_card');

  const matchTitle = `${match.home_team_name || match.home_placeholder || 'TBD'} vs ${match.away_team_name || match.away_placeholder || 'TBD'}`;

  return (
    <div className="fixed inset-0 z-50 bg-black bg-opacity-60 flex items-center justify-center p-4">
      <div className="bg-white rounded-xl shadow-2xl w-full max-w-2xl max-h-[92vh] overflow-y-auto">
        <div className="px-6 py-4 border-b border-gray-200">
          <div className="text-xs uppercase tracking-wide text-gray-500 font-semibold">
            {match.round} · Match #{match.match_number}
          </div>
          <h2 className="text-xl font-bold text-gray-900 mt-1">{matchTitle}</h2>
        </div>

        {/* Tabs */}
        <div className="px-6 pt-4 flex gap-1 bg-gray-50 border-b border-gray-200">
          {(['score', 'report', 'notes'] as const).map((t) => (
            <button
              key={t}
              onClick={() => setTab(t)}
              className={`px-4 py-2 text-sm font-medium rounded-t-md ${
                tab === t ? 'bg-white text-gray-900 border border-gray-200 border-b-white' : 'text-gray-500 hover:text-gray-700'
              }`}
            >
              {t === 'score' ? 'Score' : t === 'report' ? 'Referee Report' : 'Notes'}
            </button>
          ))}
        </div>

        {error && (
          <div className="mx-6 mt-4 p-3 bg-red-50 border border-red-200 rounded-md text-sm text-red-700">{error}</div>
        )}

        {/* Score tab */}
        {tab === 'score' && (
          <div className="p-6 space-y-6">
            <div className="flex items-center justify-center gap-6">
              <div className="text-center flex-1">
                <div className="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2 truncate">
                  {match.home_team_name || match.home_placeholder || 'Home'}
                </div>
                <input type="number" min="0" value={homeScore} onChange={(e) => setHomeScore(e.target.value)}
                  className="w-24 h-20 text-5xl font-extrabold text-center border-2 border-gray-300 rounded-lg focus:border-brand-primary focus:outline-none"
                  placeholder="0" />
              </div>
              <div className="text-2xl font-bold text-gray-300">VS</div>
              <div className="text-center flex-1">
                <div className="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2 truncate">
                  {match.away_team_name || match.away_placeholder || 'Away'}
                </div>
                <input type="number" min="0" value={awayScore} onChange={(e) => setAwayScore(e.target.value)}
                  className="w-24 h-20 text-5xl font-extrabold text-center border-2 border-gray-300 rounded-lg focus:border-brand-primary focus:outline-none"
                  placeholder="0" />
              </div>
            </div>

            {showPKs && (
              <div className="border-t border-gray-200 pt-4">
                <div className="text-xs font-semibold uppercase tracking-wide text-orange-600 mb-3 text-center">
                  Penalty Shootout
                </div>
                <div className="flex items-center justify-center gap-4">
                  <input type="number" min="0" value={homePK} onChange={(e) => setHomePK(e.target.value)}
                    className="w-16 h-12 text-2xl font-bold text-center border-2 border-orange-300 rounded-lg" placeholder="0" />
                  <span className="text-gray-400">–</span>
                  <input type="number" min="0" value={awayPK} onChange={(e) => setAwayPK(e.target.value)}
                    className="w-16 h-12 text-2xl font-bold text-center border-2 border-orange-300 rounded-lg" placeholder="0" />
                </div>
              </div>
            )}

            <div className="flex justify-end gap-2">
              <button onClick={onClose} className="px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-md">Cancel</button>
              <button onClick={handleSaveScore} disabled={saving}
                className="px-5 py-2 text-sm font-medium text-white bg-brand-primary hover:bg-brand-primary-hover rounded-md disabled:opacity-50">
                {saving ? 'Saving…' : 'Save Score'}
              </button>
            </div>
          </div>
        )}

        {/* Referee Report tab */}
        {tab === 'report' && (
          <div className="p-6 space-y-6">
            {/* Cards */}
            <div className="grid grid-cols-2 gap-4">
              <div>
                <div className="flex items-center justify-between mb-2">
                  <h4 className="text-sm font-semibold text-gray-700 flex items-center gap-2">
                    <span className="w-3 h-4 bg-yellow-400 rounded-sm inline-block" />
                    Yellow Cards
                  </h4>
                </div>
                <div className="space-y-1 max-h-40 overflow-y-auto">
                  {yellowCards.length === 0 && <p className="text-xs text-gray-400 italic">None</p>}
                  {yellowCards.map((e) => (
                    <div key={e.id} className="flex items-center justify-between bg-yellow-50 border border-yellow-200 rounded px-2 py-1 text-xs">
                      <div className="flex-1 truncate">
                        <span className="font-semibold">{e.details?.player_name || e.athlete_name || 'Unknown'}</span>
                        {' · '}<span className="text-gray-600">{e.team_name}</span>
                        {e.minute != null && <span className="text-gray-500"> · {e.minute}'</span>}
                      </div>
                      <button onClick={() => handleDeleteEvent(e.id)} className="text-red-500 hover:text-red-700 ml-2">×</button>
                    </div>
                  ))}
                </div>
                <div className="flex gap-1 mt-2">
                  {match.home_registration_id && (
                    <button onClick={() => handleAddCard('yellow_card', 'home')}
                      className="text-xs px-2 py-1 bg-yellow-100 hover:bg-yellow-200 rounded">+ Home</button>
                  )}
                  {match.away_registration_id && (
                    <button onClick={() => handleAddCard('yellow_card', 'away')}
                      className="text-xs px-2 py-1 bg-yellow-100 hover:bg-yellow-200 rounded">+ Away</button>
                  )}
                </div>
              </div>
              <div>
                <div className="flex items-center justify-between mb-2">
                  <h4 className="text-sm font-semibold text-gray-700 flex items-center gap-2">
                    <span className="w-3 h-4 bg-red-500 rounded-sm inline-block" />
                    Red Cards
                  </h4>
                </div>
                <div className="space-y-1 max-h-40 overflow-y-auto">
                  {redCards.length === 0 && <p className="text-xs text-gray-400 italic">None</p>}
                  {redCards.map((e) => (
                    <div key={e.id} className="flex items-center justify-between bg-red-50 border border-red-200 rounded px-2 py-1 text-xs">
                      <div className="flex-1 truncate">
                        <span className="font-semibold">{e.details?.player_name || e.athlete_name || 'Unknown'}</span>
                        {' · '}<span className="text-gray-600">{e.team_name}</span>
                        {e.minute != null && <span className="text-gray-500"> · {e.minute}'</span>}
                      </div>
                      <button onClick={() => handleDeleteEvent(e.id)} className="text-red-500 hover:text-red-700 ml-2">×</button>
                    </div>
                  ))}
                </div>
                <div className="flex gap-1 mt-2">
                  {match.home_registration_id && (
                    <button onClick={() => handleAddCard('red_card', 'home')}
                      className="text-xs px-2 py-1 bg-red-100 hover:bg-red-200 rounded">+ Home</button>
                  )}
                  {match.away_registration_id && (
                    <button onClick={() => handleAddCard('red_card', 'away')}
                      className="text-xs px-2 py-1 bg-red-100 hover:bg-red-200 rounded">+ Away</button>
                  )}
                </div>
              </div>
            </div>

            {/* Conditions */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Field Conditions</label>
              <textarea rows={2} value={conditions} onChange={(e) => setConditions(e.target.value)}
                placeholder="Turf, weather, visibility, anything notable…"
                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm" />
            </div>

            {/* Incident */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Incident Report</label>
              <textarea rows={3} value={incident} onChange={(e) => setIncident(e.target.value)}
                placeholder="Injuries, confrontations, anything that needs director attention…"
                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm" />
            </div>

            {/* Photo */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Match Card Photo</label>
              {photoUrl ? (
                <div className="flex items-start gap-3">
                  <img src={photoUrl.startsWith('http') ? photoUrl : `${API_URL}${photoUrl}`}
                    alt="Match card" className="max-h-40 rounded-md border border-gray-200" />
                  <button onClick={() => setPhotoUrl(null)}
                    className="text-xs text-red-600 hover:underline">Remove</button>
                </div>
              ) : (
                <label className="block border-2 border-dashed border-gray-300 rounded-lg p-4 text-center cursor-pointer hover:border-brand-primary">
                  <span className="text-sm text-gray-500">📷 Tap to take a photo or upload</span>
                  <input type="file" accept="image/*" capture="environment" onChange={handlePhotoUpload} className="hidden" />
                </label>
              )}
            </div>

            <div className="flex justify-end gap-2 border-t border-gray-200 pt-4">
              <button onClick={onClose} className="px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-md">Cancel</button>
              <button onClick={handleSaveReport} disabled={saving}
                className="px-5 py-2 text-sm font-medium text-white bg-brand-primary hover:bg-brand-primary-hover rounded-md disabled:opacity-50">
                {saving ? 'Saving…' : 'Save Report'}
              </button>
            </div>
          </div>
        )}

        {/* Notes tab */}
        {tab === 'notes' && (
          <div className="p-6 space-y-4">
            <p className="text-xs text-gray-500">Director-only notes. Not shown to teams or on the public page.</p>
            <textarea rows={8} value={notes} onChange={(e) => setNotes(e.target.value)}
              placeholder="Any private notes about this match…"
              className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm" />
            <div className="flex justify-end gap-2">
              <button onClick={onClose} className="px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-md">Cancel</button>
              <button onClick={handleSaveNotes} disabled={saving}
                className="px-5 py-2 text-sm font-medium text-white bg-brand-primary hover:bg-brand-primary-hover rounded-md disabled:opacity-50">
                {saving ? 'Saving…' : 'Save Notes'}
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

export default MatchCenterModal;
