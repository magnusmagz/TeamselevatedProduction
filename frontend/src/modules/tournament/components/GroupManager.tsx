import React, { useState, useEffect, useCallback } from 'react';
const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface GroupTeam {
  registration_id: number;
  display_name: string;
  seed: number | null;
}

interface GroupData {
  id: number;
  name: string;
  sort_order: number;
  teams: GroupTeam[];
}

interface Props {
  divisionId: number;
  isAdmin: boolean;
}

const GroupManager: React.FC<Props> = ({ divisionId, isAdmin }) => {
  const [groups, setGroups] = useState<GroupData[]>([]);
  const [unassigned, setUnassigned] = useState<GroupTeam[]>([]);
  const [loading, setLoading] = useState(true);
  const [autoAssigning, setAutoAssigning] = useState(false);

  const token = localStorage.getItem('auth_token');
  const headers: HeadersInit = { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` };

  const fetchGroups = useCallback(async () => {
    setLoading(true);
    try {
      const res = await fetch(`${API_URL}/api/tournament-gateway.php?action=groups-list&division_id=${divisionId}`, { headers });
      const data = await res.json();
      setGroups(data.groups || []);
      setUnassigned(data.unassigned_teams || []);
    } catch (err) {
      console.error('Failed to fetch groups:', err);
    } finally {
      setLoading(false);
    }
  }, [divisionId]);

  useEffect(() => { fetchGroups(); }, [fetchGroups]);

  const handleCreateGroup = async () => {
    try {
      await fetch(`${API_URL}/api/tournament-gateway.php?action=group-create&division_id=${divisionId}`, {
        method: 'POST', headers, body: JSON.stringify({}),
      });
      fetchGroups();
    } catch (err) {
      alert('Failed to create group');
    }
  };

  const handleAutoAssign = async () => {
    setAutoAssigning(true);
    try {
      const res = await fetch(`${API_URL}/api/tournament-gateway.php?action=group-auto-assign&division_id=${divisionId}`, {
        method: 'POST', headers, body: JSON.stringify({ strategy: 'snake_seed' }),
      });
      if (!res.ok) {
        const err = await res.json();
        alert(err.error || 'Failed to auto-assign');
        return;
      }
      fetchGroups();
    } catch (err) {
      alert('Failed to auto-assign teams');
    } finally {
      setAutoAssigning(false);
    }
  };

  const handleMoveTeam = async (registrationId: number, targetGroupId: number) => {
    // Get current teams in target group
    const targetGroup = groups.find(g => g.id === targetGroupId);
    const currentIds = (targetGroup?.teams || []).map(t => t.registration_id);

    try {
      await fetch(`${API_URL}/api/tournament-gateway.php?action=group-assign-teams&group_id=${targetGroupId}`, {
        method: 'PUT', headers,
        body: JSON.stringify({ registration_ids: [...currentIds, registrationId] }),
      });
      fetchGroups();
    } catch (err) {
      alert('Failed to move team');
    }
  };

  if (loading) {
    return <div className="text-center py-8 text-gray-500">Loading groups...</div>;
  }

  const totalTeams = groups.reduce((sum, g) => sum + (g.teams?.length || 0), 0) + unassigned.length;

  return (
    <div>
      <div className="flex justify-between items-center mb-4">
        <h3 className="text-lg font-semibold text-gray-900">
          Groups ({groups.length})
        </h3>
        {isAdmin && (
          <div className="flex space-x-2">
            <button onClick={handleCreateGroup}
              className="px-3 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
              Add Group
            </button>
            {totalTeams > 0 && (
              <button onClick={handleAutoAssign} disabled={autoAssigning}
                className="px-3 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-brand-primary hover:bg-brand-primary-hover disabled:opacity-50">
                {autoAssigning ? 'Assigning...' : 'Auto-Assign (Snake Seed)'}
              </button>
            )}
          </div>
        )}
      </div>

      {/* Unassigned teams */}
      {unassigned.length > 0 && (
        <div className="mb-4 bg-yellow-50 border border-yellow-200 rounded-lg p-4">
          <h4 className="text-sm font-semibold text-yellow-800 mb-2">
            Unassigned Teams ({unassigned.length})
          </h4>
          <div className="flex flex-wrap gap-2">
            {unassigned.map((team) => (
              <div key={team.registration_id} className="inline-flex items-center bg-white border border-yellow-300 rounded-md px-3 py-1.5 text-sm">
                {team.seed && <span className="text-xs text-gray-400 mr-1.5">#{team.seed}</span>}
                <span className="text-gray-900">{team.display_name}</span>
                {isAdmin && groups.length > 0 && (
                  <select
                    defaultValue=""
                    onChange={(e) => e.target.value && handleMoveTeam(team.registration_id, Number(e.target.value))}
                    className="ml-2 border-0 bg-transparent text-xs text-brand-primary cursor-pointer"
                  >
                    <option value="">Move to...</option>
                    {groups.map(g => <option key={g.id} value={g.id}>{g.name}</option>)}
                  </select>
                )}
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Groups */}
      {groups.length === 0 ? (
        <div className="text-center py-12 bg-gray-50 rounded-lg border-2 border-dashed border-gray-300">
          <p className="text-gray-500">No groups created yet. Add groups or use auto-assign.</p>
        </div>
      ) : (
        <div className="grid gap-4 md:grid-cols-2">
          {groups.map((group) => (
            <div key={group.id} className="bg-white border border-gray-200 rounded-lg p-4">
              <h4 className="font-semibold text-gray-900 mb-3">{group.name}</h4>
              {(!group.teams || group.teams.length === 0) ? (
                <p className="text-sm text-gray-400 italic">No teams assigned</p>
              ) : (
                <div className="space-y-1.5">
                  {group.teams.map((team, idx) => (
                    <div key={team.registration_id} className="flex items-center justify-between bg-gray-50 rounded px-3 py-2">
                      <div className="flex items-center space-x-2">
                        <span className="text-xs text-gray-400 w-5">{idx + 1}.</span>
                        {team.seed && <span className="text-xs bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded">#{team.seed}</span>}
                        <span className="text-sm text-gray-900">{team.display_name}</span>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
};

export default GroupManager;
