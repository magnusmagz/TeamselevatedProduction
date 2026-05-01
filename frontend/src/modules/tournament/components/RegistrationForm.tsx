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
  const [teamIds, setTeamIds] = useState<number[]>([]);
  const [teamNameOverride, setTeamNameOverride] = useState('');
  const [clubNameOverride, setClubNameOverride] = useState('');
  const [notes, setNotes] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [teamFilter, setTeamFilter] = useState('');
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

  const toggleTeam = (id: number) => {
    setTeamIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
  };

  const filteredTeams = teams.filter((t) => {
    if (!teamFilter.trim()) return true;
    const q = teamFilter.toLowerCase();
    return t.name.toLowerCase().includes(q) || (t.age_group || '').toLowerCase().includes(q);
  });

  const allFilteredSelected = filteredTeams.length > 0 && filteredTeams.every((t) => teamIds.includes(t.id));
  const toggleAllFiltered = () => {
    if (allFilteredSelected) {
      const filteredSet = new Set(filteredTeams.map((t) => t.id));
      setTeamIds((prev) => prev.filter((id) => !filteredSet.has(id)));
    } else {
      const merged = new Set([...teamIds, ...filteredTeams.map((t) => t.id)]);
      setTeamIds(Array.from(merged));
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!divisionId) {
      setError('Please select a division');
      return;
    }
    if (!isGuest && teamIds.length === 0) {
      setError('Please select at least one team');
      return;
    }
    if (isGuest && !teamNameOverride.trim()) {
      setError('Please enter the guest team name');
      return;
    }

    setSaving(true);
    setError('');

    try {
      if (isGuest) {
        const res = await fetch(`${API_URL}/api/tournament-gateway.php?action=registration-create`, {
          method: 'POST',
          headers,
          body: JSON.stringify({
            tournament_id: tournamentId,
            division_id: divisionId,
            notes: notes || null,
            team_name_override: teamNameOverride.trim(),
            club_name_override: clubNameOverride.trim() || null,
          }),
        });
        if (!res.ok) {
          const err = await res.json();
          throw new Error(err.error || 'Failed to register team');
        }
        onSave();
        return;
      }

      // Bulk-register selected club teams. Run sequentially so a partial
      // failure leaves the user with a clear "X of Y registered" message
      // rather than half-applied parallel results.
      const failures: { name: string; error: string }[] = [];
      let successes = 0;
      for (const id of teamIds) {
        const team = teams.find((t) => t.id === id);
        try {
          const res = await fetch(`${API_URL}/api/tournament-gateway.php?action=registration-create`, {
            method: 'POST',
            headers,
            body: JSON.stringify({
              tournament_id: tournamentId,
              division_id: divisionId,
              notes: notes || null,
              team_id: id,
            }),
          });
          if (!res.ok) {
            const err = await res.json();
            failures.push({ name: team?.name || `Team #${id}`, error: err.error || 'Failed' });
          } else {
            successes++;
          }
        } catch (err: any) {
          failures.push({ name: team?.name || `Team #${id}`, error: err.message || 'Network error' });
        }
      }

      if (failures.length === 0) {
        onSave();
      } else if (successes === 0) {
        setError(`No teams registered. ${failures.map((f) => `${f.name}: ${f.error}`).join('; ')}`);
      } else {
        setError(`Registered ${successes} of ${teamIds.length}. Failed: ${failures.map((f) => `${f.name} (${f.error})`).join('; ')}`);
        // Refresh parent list so the successes are visible even though we stay on the form
        onSave();
      }
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
              onChange={(e) => { setIsGuest(e.target.checked); setTeamIds([]); }}
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
            <div className="flex items-baseline justify-between mb-1">
              <label className="block text-sm font-medium text-gray-700">
                Teams * <span className="text-xs text-gray-500 font-normal">(select one or more)</span>
              </label>
              {teamIds.length > 0 && (
                <span className="text-xs text-brand-primary font-medium">{teamIds.length} selected</span>
              )}
            </div>
            {teamsLoading ? (
              <p className="text-sm text-gray-500">Loading teams...</p>
            ) : teams.length === 0 ? (
              <p className="text-sm text-gray-500 border border-gray-200 rounded-md p-3 bg-gray-50">No teams in this club yet.</p>
            ) : (
              <div className="border border-gray-300 rounded-md overflow-hidden">
                <div className="flex items-center gap-2 px-3 py-2 border-b border-gray-200 bg-gray-50">
                  <input
                    type="text"
                    value={teamFilter}
                    onChange={(e) => setTeamFilter(e.target.value)}
                    placeholder="Filter teams..."
                    className="flex-1 text-sm bg-white border border-gray-300 rounded px-2 py-1"
                  />
                  {filteredTeams.length > 0 && (
                    <button
                      type="button"
                      onClick={toggleAllFiltered}
                      className="text-xs text-brand-primary hover:underline whitespace-nowrap"
                    >
                      {allFilteredSelected ? 'Clear shown' : 'Select all shown'}
                    </button>
                  )}
                </div>
                <div className="max-h-60 overflow-y-auto">
                  {filteredTeams.length === 0 ? (
                    <p className="text-sm text-gray-500 px-3 py-3">No teams match "{teamFilter}".</p>
                  ) : (
                    filteredTeams.map((t) => {
                      const selected = teamIds.includes(t.id);
                      return (
                        <label
                          key={t.id}
                          className={`flex items-center gap-3 px-3 py-2 cursor-pointer hover:bg-gray-50 border-b border-gray-100 last:border-b-0 ${
                            selected ? 'bg-brand-primary/5' : ''
                          }`}
                        >
                          <input
                            type="checkbox"
                            checked={selected}
                            onChange={() => toggleTeam(t.id)}
                            className="rounded border-gray-300 text-brand-primary focus:ring-brand-primary"
                          />
                          <span className="text-sm text-gray-900">{t.name}</span>
                          {t.age_group && (
                            <span className="text-xs text-gray-500 ml-auto">{t.age_group}</span>
                          )}
                        </label>
                      );
                    })
                  )}
                </div>
              </div>
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
          {saving
            ? 'Registering...'
            : isGuest
              ? 'Register Team'
              : teamIds.length <= 1
                ? 'Register Team'
                : `Register ${teamIds.length} Teams`}
        </button>
      </div>
    </form>
  );
};

export default RegistrationForm;
