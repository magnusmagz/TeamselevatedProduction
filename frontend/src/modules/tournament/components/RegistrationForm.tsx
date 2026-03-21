import React, { useState, useEffect } from 'react';
import { TournamentDivision } from '../types';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface Props {
  tournamentId: number;
  divisions: TournamentDivision[];
  clubId: number;
  isAdmin: boolean;
  onSave: () => void;
  onCancel: () => void;
}

interface ClubTeam {
  id: number;
  name: string;
  age_group: string;
}

const RegistrationForm: React.FC<Props> = ({ tournamentId, divisions, clubId, isAdmin, onSave, onCancel }) => {
  const [isGuest, setIsGuest] = useState(false);
  const [divisionId, setDivisionId] = useState<number | ''>(divisions.length === 1 ? divisions[0].id : '');
  const [teamId, setTeamId] = useState<number | ''>('');
  const [teamNameOverride, setTeamNameOverride] = useState('');
  const [clubNameOverride, setClubNameOverride] = useState('');
  const [notes, setNotes] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [teams, setTeams] = useState<ClubTeam[]>([]);
  const [teamsLoading, setTeamsLoading] = useState(true);

  const token = localStorage.getItem('auth_token');
  const headers: HeadersInit = { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` };

  // Fetch club teams
  useEffect(() => {
    setTeamsLoading(true);
    fetch(`${API_URL}/api/teams.php?club_profile_id=${clubId}`, { headers })
      .then((r) => r.json())
      .then((data) => setTeams(Array.isArray(data) ? data : data.teams || []))
      .catch(() => {})
      .finally(() => setTeamsLoading(false));
  }, [clubId]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!divisionId) {
      setError('Please select a division');
      return;
    }
    if (!isGuest && !teamId) {
      setError('Please select a team');
      return;
    }
    if (isGuest && !teamNameOverride.trim()) {
      setError('Please enter the guest team name');
      return;
    }

    setSaving(true);
    setError('');

    try {
      const body: any = {
        tournament_id: tournamentId,
        division_id: divisionId,
        notes: notes || null,
      };

      if (isGuest) {
        body.team_name_override = teamNameOverride.trim();
        body.club_name_override = clubNameOverride.trim() || null;
      } else {
        body.team_id = teamId;
      }

      const res = await fetch(`${API_URL}/api/tournament-gateway.php?action=registration-create`, {
        method: 'POST',
        headers,
        body: JSON.stringify(body),
      });

      if (!res.ok) {
        const err = await res.json();
        throw new Error(err.error || 'Failed to register team');
      }

      onSave();
    } catch (err: any) {
      setError(err.message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-6">
      <div className="flex justify-between items-center">
        <h3 className="text-lg font-semibold text-gray-900">Register Team</h3>
        <button type="button" onClick={onCancel} className="text-sm text-gray-500 hover:text-gray-700">Cancel</button>
      </div>

      {error && (
        <div className="bg-red-50 border border-red-200 rounded-md p-3 text-red-700 text-sm">{error}</div>
      )}

      <div className="bg-white border border-gray-200 rounded-lg p-4 space-y-4">
        {/* Division */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Division *</label>
          <select
            value={divisionId}
            onChange={(e) => setDivisionId(e.target.value ? Number(e.target.value) : '')}
            className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
          >
            <option value="">Select division...</option>
            {divisions.map((d) => (
              <option key={d.id} value={d.id}>{d.name} ({d.age_group} {d.gender})</option>
            ))}
          </select>
        </div>

        {/* Guest team toggle */}
        {isAdmin && (
          <div className="flex items-center space-x-2">
            <input
              type="checkbox"
              id="guest-toggle"
              checked={isGuest}
              onChange={(e) => { setIsGuest(e.target.checked); setTeamId(''); }}
              className="rounded border-gray-300"
            />
            <label htmlFor="guest-toggle" className="text-sm text-gray-700">
              Register a guest team (not in our club)
            </label>
          </div>
        )}

        {/* Team selection or guest fields */}
        {isGuest ? (
          <div className="space-y-3">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Team Name *</label>
              <input
                type="text"
                value={teamNameOverride}
                onChange={(e) => setTeamNameOverride(e.target.value)}
                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                placeholder="Visiting FC U12"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Club Name</label>
              <input
                type="text"
                value={clubNameOverride}
                onChange={(e) => setClubNameOverride(e.target.value)}
                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                placeholder="Visiting FC"
              />
            </div>
          </div>
        ) : (
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Team *</label>
            {teamsLoading ? (
              <p className="text-sm text-gray-500">Loading teams...</p>
            ) : (
              <select
                value={teamId}
                onChange={(e) => setTeamId(e.target.value ? Number(e.target.value) : '')}
                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
              >
                <option value="">Select team...</option>
                {teams.map((t) => (
                  <option key={t.id} value={t.id}>{t.name} {t.age_group ? `(${t.age_group})` : ''}</option>
                ))}
              </select>
            )}
          </div>
        )}

        {/* Notes */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Notes</label>
          <textarea
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            rows={2}
            className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
            placeholder="Optional notes..."
          />
        </div>
      </div>

      <div className="flex justify-end space-x-3">
        <button type="button" onClick={onCancel} className="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
          Cancel
        </button>
        <button type="submit" disabled={saving} className="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-brand-primary hover:bg-brand-primary-hover disabled:opacity-50">
          {saving ? 'Registering...' : 'Register Team'}
        </button>
      </div>
    </form>
  );
};

export default RegistrationForm;
