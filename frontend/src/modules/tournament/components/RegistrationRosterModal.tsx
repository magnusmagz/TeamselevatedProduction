import React, { useState, useEffect, useCallback } from 'react';
import { TournamentRegistration } from '../types';
import DataTable from '../../../components/ui/DataTable';
import Button from '../../../components/ui/Button';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface RosterPlayer {
  id: number;
  registration_id: number;
  athlete_id: number | null;
  player_name: string | null;
  jersey_number: number | null;
  position: string | null;
  is_guest: boolean;
  notes: string | null;
  first_name: string | null;
  last_name: string | null;
  created_at: string;
  updated_at: string;
}

interface Candidate {
  athlete_id: number;
  first_name: string;
  last_name: string;
  jersey_number: number | null;
  primary_position: string | null;
}

interface Props {
  registration: TournamentRegistration;
  onClose: () => void;
}

const RegistrationRosterModal: React.FC<Props> = ({ registration, onClose }) => {
  const [roster, setRoster] = useState<RosterPlayer[]>([]);
  const [candidates, setCandidates] = useState<Candidate[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [eligibilityWarning, setEligibilityWarning] = useState<string | null>(null);

  // Add-form state
  const [addMode, setAddMode] = useState<'team' | 'guest'>('team');
  const [pickAthleteId, setPickAthleteId] = useState<number | ''>('');
  const [guestName, setGuestName] = useState('');
  const [addJersey, setAddJersey] = useState('');
  const [addPosition, setAddPosition] = useState('');

  const token = localStorage.getItem('auth_token');
  const headers: HeadersInit = { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` };

  const refetch = useCallback(async () => {
    try {
      const [rosterRes, candidatesRes] = await Promise.all([
        fetch(`${API_URL}/api/tournament-gateway.php?action=tournament-roster-list&registration_id=${registration.id}`, { headers }),
        fetch(`${API_URL}/api/tournament-gateway.php?action=tournament-roster-team-candidates&registration_id=${registration.id}`, { headers }),
      ]);
      const rosterData = await rosterRes.json();
      const candidatesData = await candidatesRes.json();
      setRoster(rosterData.players || []);
      setCandidates(candidatesData.candidates || []);
    } catch (e: any) {
      setError(e.message || 'Failed to load roster');
    } finally {
      setLoading(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [registration.id]);

  useEffect(() => { refetch(); }, [refetch]);

  const handleImportFromTeam = async () => {
    if (!window.confirm('Copy all players from the team\'s regular roster into this tournament roster? Players already on the tournament roster will be skipped.')) return;
    setBusy(true);
    setError('');
    try {
      const res = await fetch(`${API_URL}/api/tournament-gateway.php?action=tournament-roster-import&registration_id=${registration.id}`, {
        method: 'POST', headers, body: '{}',
      });
      if (!res.ok) { const err = await res.json(); throw new Error(err.error || 'Import failed'); }
      const data = await res.json();
      if (data.imported === 0) {
        alert('No new players to import — every team player is already on the tournament roster.');
      }
      refetch();
    } catch (e: any) {
      setError(e.message || 'Import failed');
    } finally {
      setBusy(false);
    }
  };

  const handleAdd = async () => {
    setError('');
    if (addMode === 'team' && !pickAthleteId) { setError('Pick a player'); return; }
    if (addMode === 'guest' && !guestName.trim()) { setError('Enter a guest player name'); return; }

    setBusy(true);
    try {
      const body: any = {
        jersey_number: addJersey ? parseInt(addJersey, 10) : null,
        position: addPosition || null,
      };
      if (addMode === 'team') {
        body.athlete_id = pickAthleteId;
        // Default jersey to the team-roster jersey if user didn't override
        if (!addJersey) {
          const c = candidates.find((x) => x.athlete_id === pickAthleteId);
          if (c?.jersey_number != null) body.jersey_number = c.jersey_number;
        }
      } else {
        body.player_name = guestName.trim();
        body.is_guest = true;
      }
      const res = await fetch(`${API_URL}/api/tournament-gateway.php?action=tournament-roster-add&registration_id=${registration.id}`, {
        method: 'POST', headers, body: JSON.stringify(body),
      });
      if (!res.ok) { const err = await res.json(); throw new Error(err.error || 'Failed to add player'); }
      const result = await res.json();
      if (result.eligibility_warning) {
        // Soft warning — player WAS added; flag it so the director can
        // double-check or remove. Direction matches the rest of the
        // modal (no hard blocks, just visibility).
        setEligibilityWarning(result.eligibility_warning);
      } else {
        setEligibilityWarning(null);
      }
      // Reset add form
      setPickAthleteId('');
      setGuestName('');
      setAddJersey('');
      setAddPosition('');
      refetch();
    } catch (e: any) {
      setError(e.message || 'Failed to add player');
    } finally {
      setBusy(false);
    }
  };

  const handleRemove = async (rosterPlayerId: number) => {
    if (!window.confirm('Remove this player from the tournament roster?')) return;
    setBusy(true);
    try {
      await fetch(`${API_URL}/api/tournament-gateway.php?action=tournament-roster-remove&id=${rosterPlayerId}`, {
        method: 'DELETE', headers,
      });
      refetch();
    } catch (e: any) {
      setError(e.message || 'Failed to remove');
    } finally {
      setBusy(false);
    }
  };

  const handleEditJersey = async (rosterPlayerId: number, current: number | null) => {
    const next = window.prompt('Jersey number (blank to clear)', current?.toString() ?? '');
    if (next === null) return;
    const value = next.trim();
    setBusy(true);
    try {
      await fetch(`${API_URL}/api/tournament-gateway.php?action=tournament-roster-update&id=${rosterPlayerId}`, {
        method: 'PUT', headers,
        body: JSON.stringify({ jersey_number: value === '' ? null : parseInt(value, 10) }),
      });
      refetch();
    } catch (e: any) {
      setError(e.message || 'Failed to update');
    } finally {
      setBusy(false);
    }
  };

  const teamName = registration.display_name;

  return (
    <div className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4">
      <div className="bg-white rounded-xl shadow-2xl w-full max-w-3xl max-h-[92vh] overflow-y-auto">
        <div className="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
          <div>
            <h3 className="text-lg font-semibold text-gray-900">Tournament Roster — {teamName}</h3>
            <p className="text-xs text-gray-500 mt-0.5">{registration.division_name}</p>
          </div>
          <Button variant="ghost" size="icon" aria-label="Close" onClick={onClose}>✕</Button>
        </div>

        <div className="p-5 space-y-5">
          {error && (
            <div className="bg-red-50 border border-red-200 rounded-md p-3 text-red-700 text-sm">{error}</div>
          )}

          {eligibilityWarning && (
            <div className="bg-amber-50 border border-amber-200 rounded-md p-3 flex items-start gap-2">
              <span className="text-amber-600 text-lg leading-none">⚠️</span>
              <div className="flex-1 text-sm text-amber-900">
                <strong>Age eligibility warning:</strong> {eligibilityWarning}.
                Player was added — review or remove if this isn't an intentional play-up / guest registration.
              </div>
              <Button variant="link" size="sm" onClick={() => setEligibilityWarning(null)}>Dismiss</Button>
            </div>
          )}

          {/* Current roster */}
          <section>
            <div className="flex items-center justify-between mb-2">
              <h4 className="text-sm font-semibold text-gray-900">Roster ({roster.length})</h4>
              <Button
                variant="link"
                size="sm"
                onClick={handleImportFromTeam}
                disabled={busy}
                title="Copy the team's regular roster into this tournament roster"
              >
                ↪ Import from team
              </Button>
            </div>

            {loading ? (
              <p className="text-sm text-gray-500">Loading…</p>
            ) : (
              <DataTable<RosterPlayer>
                columns={[
                  {
                    key: 'jersey',
                    header: '#',
                    render: (p) => (
                      <span className="text-gray-700 tabular-nums">
                        {p.jersey_number !== null ? `#${p.jersey_number}` : <span className="text-gray-300">—</span>}
                      </span>
                    ),
                  },
                  {
                    key: 'player',
                    header: 'Player',
                    render: (p) => {
                      const name = p.athlete_id
                        ? `${p.first_name || ''} ${p.last_name || ''}`.trim()
                        : (p.player_name || '—');
                      return (
                        <span className="text-gray-900">
                          {name}
                          {p.is_guest && <span className="ml-2 text-xs bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded">Guest</span>}
                        </span>
                      );
                    },
                  },
                  {
                    key: 'position',
                    header: 'Position',
                    render: (p) => <span className="text-gray-500 text-xs">{p.position || ''}</span>,
                  },
                  {
                    key: 'actions',
                    header: 'Actions',
                    actions: true,
                    className: 'space-x-2',
                    render: (p) => (
                      <>
                        <Button variant="link" size="sm" onClick={() => handleEditJersey(p.id, p.jersey_number)} disabled={busy}>Jersey</Button>
                        <Button variant="danger-link" size="sm" onClick={() => handleRemove(p.id)} disabled={busy}>Remove</Button>
                      </>
                    ),
                  },
                ]}
                rows={roster}
                rowKey={(p) => p.id}
                emptyState={{
                  text: 'No players on the tournament roster yet.',
                  action: (
                    <Button variant="secondary" size="sm" onClick={handleImportFromTeam} disabled={busy}>
                      Import from team
                    </Button>
                  ),
                }}
              />
            )}
          </section>

          {/* Add form */}
          <section className="border-t border-gray-200 pt-4">
            <h4 className="text-sm font-semibold text-gray-900 mb-2">Add a player</h4>

            <div className="flex gap-2 mb-3 text-xs">
              <button
                type="button"
                onClick={() => setAddMode('team')}
                className={`px-3 py-1.5 rounded-md border ${addMode === 'team' ? 'bg-brand-primary text-white border-brand-primary' : 'bg-white text-gray-700 border-gray-300'}`}
              >
                From team roster
              </button>
              <button
                type="button"
                onClick={() => setAddMode('guest')}
                className={`px-3 py-1.5 rounded-md border ${addMode === 'guest' ? 'bg-brand-primary text-white border-brand-primary' : 'bg-white text-gray-700 border-gray-300'}`}
              >
                Guest player
              </button>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
              {addMode === 'team' ? (
                <div className="sm:col-span-3">
                  <label className="block text-xs text-gray-500 mb-1">Player</label>
                  {candidates.length === 0 ? (
                    <p className="text-sm text-gray-500 border border-gray-200 rounded p-2 bg-gray-50">
                      Every player on the team's regular roster is already on the tournament roster. Switch to "Guest player" to add anyone else.
                    </p>
                  ) : (
                    <select
                      value={pickAthleteId}
                      onChange={(e) => setPickAthleteId(e.target.value ? Number(e.target.value) : '')}
                      className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                    >
                      <option value="">Select a player…</option>
                      {candidates.map((c) => (
                        <option key={c.athlete_id} value={c.athlete_id}>
                          {c.jersey_number !== null ? `#${c.jersey_number} ` : ''}
                          {c.first_name} {c.last_name}
                          {c.primary_position ? ` · ${c.primary_position}` : ''}
                        </option>
                      ))}
                    </select>
                  )}
                </div>
              ) : (
                <div className="sm:col-span-3">
                  <label className="block text-xs text-gray-500 mb-1">Guest player name</label>
                  <input
                    type="text"
                    value={guestName}
                    onChange={(e) => setGuestName(e.target.value)}
                    placeholder="Last, First"
                    className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                  />
                </div>
              )}

              <div>
                <label className="block text-xs text-gray-500 mb-1">Jersey #</label>
                <input
                  type="number"
                  min="0"
                  max="99"
                  value={addJersey}
                  onChange={(e) => setAddJersey(e.target.value)}
                  placeholder={addMode === 'team' ? 'Defaults to team #' : ''}
                  className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                />
              </div>
              <div className="sm:col-span-2">
                <label className="block text-xs text-gray-500 mb-1">Position (optional)</label>
                <input
                  type="text"
                  value={addPosition}
                  onChange={(e) => setAddPosition(e.target.value)}
                  placeholder="e.g. F, MF, D, GK"
                  className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                />
              </div>
            </div>

            <div className="mt-3 text-right">
              <Button
                onClick={handleAdd}
                loading={busy}
                disabled={(addMode === 'team' && !pickAthleteId) || (addMode === 'guest' && !guestName.trim())}
              >
                Add to roster
              </Button>
            </div>
          </section>
        </div>

        <div className="px-5 py-3 border-t border-gray-200 text-right">
          <Button variant="secondary" onClick={onClose}>Done</Button>
        </div>
      </div>
    </div>
  );
};

export default RegistrationRosterModal;
