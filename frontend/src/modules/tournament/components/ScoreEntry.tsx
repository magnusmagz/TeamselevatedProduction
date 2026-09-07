import React, { useState } from 'react';
import { TournamentMatch } from '../types';
import Button from '../../../components/ui/Button';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface Props {
  match: TournamentMatch;
  isKnockout?: boolean;
  onScored: () => void;
}

const ScoreEntry: React.FC<Props> = ({ match, isKnockout = false, onScored }) => {
  const [homeScore, setHomeScore] = useState<string>(match.home_score?.toString() || '');
  const [awayScore, setAwayScore] = useState<string>(match.away_score?.toString() || '');
  const [homePK, setHomePK] = useState<string>(match.home_penalty_score?.toString() || '');
  const [awayPK, setAwayPK] = useState<string>(match.away_penalty_score?.toString() || '');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const isDraw = homeScore !== '' && awayScore !== '' && homeScore === awayScore;
  const showPenalties = isKnockout && isDraw;

  const handleSubmit = async () => {
    if (homeScore === '' || awayScore === '') { setError('Enter both scores'); return; }
    const hs = parseInt(homeScore, 10);
    const as = parseInt(awayScore, 10);
    if (hs < 0 || as < 0) { setError('Scores cannot be negative'); return; }

    setSaving(true);
    setError('');

    const token = localStorage.getItem('auth_token');
    const action = isKnockout ? 'match-score-knockout' : 'match-score';
    const body: any = { home_score: hs, away_score: as };

    if (isKnockout && isDraw) {
      if (homePK === '' || awayPK === '') { setError('Enter penalty scores'); setSaving(false); return; }
      body.home_penalty_score = parseInt(homePK, 10);
      body.away_penalty_score = parseInt(awayPK, 10);
    }

    try {
      const res = await fetch(`${API_URL}/api/tournament-gateway.php?action=${action}&id=${match.id}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
        body: JSON.stringify(body),
      });
      if (!res.ok) { const err = await res.json(); throw new Error(err.error); }
      onScored();
    } catch (err: any) {
      setError(err.message || 'Failed to save score');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="inline-flex items-center space-x-2">
      <input type="number" min="0" value={homeScore} onChange={(e) => setHomeScore(e.target.value)}
        className="w-12 border border-gray-300 rounded px-2 py-1 text-center text-sm" placeholder="0" />
      <span className="text-gray-400">–</span>
      <input type="number" min="0" value={awayScore} onChange={(e) => setAwayScore(e.target.value)}
        className="w-12 border border-gray-300 rounded px-2 py-1 text-center text-sm" placeholder="0" />

      {showPenalties && (
        <>
          <span className="text-xs text-gray-400 ml-2">PKs:</span>
          <input type="number" min="0" value={homePK} onChange={(e) => setHomePK(e.target.value)}
            className="w-10 border border-orange-300 rounded px-1 py-1 text-center text-xs" placeholder="0" />
          <span className="text-gray-400 text-xs">–</span>
          <input type="number" min="0" value={awayPK} onChange={(e) => setAwayPK(e.target.value)}
            className="w-10 border border-orange-300 rounded px-1 py-1 text-center text-xs" placeholder="0" />
        </>
      )}

      <Button size="sm" onClick={handleSubmit} loading={saving}>
        Save
      </Button>

      {error && <span className="text-xs text-red-500 ml-1">{error}</span>}
    </div>
  );
};

export default ScoreEntry;
