import React, { useState, useEffect, useCallback, useMemo } from 'react';
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

// `null` source = team came from the unassigned pool.
type DragPayload = { registrationId: number; sourceGroupId: number | null };

const GroupManager: React.FC<Props> = ({ divisionId, isAdmin }) => {
  const [groups, setGroups] = useState<GroupData[]>([]);
  const [unassigned, setUnassigned] = useState<GroupTeam[]>([]);
  const [loading, setLoading] = useState(true);
  const [autoAssigning, setAutoAssigning] = useState(false);
  const [dragging, setDragging] = useState<DragPayload | null>(null);
  const [hoverTarget, setHoverTarget] = useState<number | 'unassigned' | null>(null);

  const token = localStorage.getItem('auth_token');
  // Stable across renders so the fetch effects/callbacks below can depend on
  // it without re-firing on every render.
  const headers: HeadersInit = useMemo(() => ({ 'Content-Type': 'application/json', Authorization: `Bearer ${token}` }), [token]);

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
  }, [divisionId, headers]);

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

  // Replace a group's roster in one call. group-assign-teams nulls the
  // group's current members then assigns the provided list — moving a
  // team's group_id implicitly evicts it from any other group.
  const writeGroupRoster = async (groupId: number, registrationIds: number[]) => {
    const res = await fetch(`${API_URL}/api/tournament-gateway.php?action=group-assign-teams&group_id=${groupId}`, {
      method: 'PUT', headers,
      body: JSON.stringify({ registration_ids: registrationIds }),
    });
    if (!res.ok) {
      const err = await res.json();
      throw new Error(err.error || 'Failed to update group');
    }
  };

  // Drop on a target group: append the dragged team to that group's roster.
  // The backend reassignment removes it from the source automatically.
  const handleDropOnGroup = async (targetGroupId: number) => {
    if (!dragging) return;
    if (dragging.sourceGroupId === targetGroupId) {
      setDragging(null); setHoverTarget(null);
      return;
    }
    const target = groups.find((g) => g.id === targetGroupId);
    if (!target) return;
    const ids = [...target.teams.map((t) => t.registration_id), dragging.registrationId];
    try {
      await writeGroupRoster(targetGroupId, ids);
      await fetchGroups();
    } catch (err: any) {
      alert(err.message || 'Failed to move team');
    } finally {
      setDragging(null);
      setHoverTarget(null);
    }
  };

  // Drop on the unassigned pool: rewrite the source group without the
  // dragged team. group_id falls to NULL because the backend nulls all
  // current members of the source group before re-applying the new list.
  const handleDropOnUnassigned = async () => {
    if (!dragging || dragging.sourceGroupId === null) {
      setDragging(null); setHoverTarget(null);
      return;
    }
    const source = groups.find((g) => g.id === dragging.sourceGroupId);
    if (!source) return;
    const ids = source.teams
      .map((t) => t.registration_id)
      .filter((id) => id !== dragging.registrationId);
    try {
      await writeGroupRoster(source.id, ids);
      await fetchGroups();
    } catch (err: any) {
      alert(err.message || 'Failed to remove team from group');
    } finally {
      setDragging(null);
      setHoverTarget(null);
    }
  };

  const handleDragStart = (registrationId: number, sourceGroupId: number | null) => (e: React.DragEvent) => {
    setDragging({ registrationId, sourceGroupId });
    e.dataTransfer.effectAllowed = 'move';
    // Required by Firefox to actually start the drag.
    e.dataTransfer.setData('text/plain', String(registrationId));
  };

  const handleDragOver = (target: number | 'unassigned') => (e: React.DragEvent) => {
    // Always preventDefault so the browser accepts the drop. Gating on
    // `dragging` here was the original bug: the closure captured the
    // pre-dragstart state, so the first dragover after a drag started
    // would skip preventDefault and the browser would refuse the drop.
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    if (hoverTarget !== target) setHoverTarget(target);
  };

  const handleDragLeave = () => {
    setHoverTarget(null);
  };

  // Pre-existing fallback for non-drag flows (e.g., keyboard users who
  // can't drag): keep the "Move to..." select on unassigned chips.
  const handleMoveTeamViaSelect = async (registrationId: number, targetGroupId: number) => {
    const target = groups.find((g) => g.id === targetGroupId);
    const currentIds = (target?.teams || []).map((t) => t.registration_id);
    try {
      await writeGroupRoster(targetGroupId, [...currentIds, registrationId]);
      fetchGroups();
    } catch (err) {
      alert('Failed to move team');
    }
  };

  if (loading) {
    return <div className="text-center py-8 text-gray-500">Loading groups...</div>;
  }

  const totalTeams = groups.reduce((sum, g) => sum + (g.teams?.length || 0), 0) + unassigned.length;
  const isHover = (target: number | 'unassigned') => hoverTarget === target;

  const teamChip = (
    team: GroupTeam,
    sourceGroupId: number | null,
    extraClasses = ''
  ) => {
    const isDraggingThis = dragging?.registrationId === team.registration_id;
    return (
      <div
        key={team.registration_id}
        draggable={isAdmin}
        onDragStart={handleDragStart(team.registration_id, sourceGroupId)}
        onDragEnd={() => { setDragging(null); setHoverTarget(null); }}
        className={`flex items-center justify-between rounded px-3 py-2 ${
          isAdmin ? 'cursor-grab active:cursor-grabbing' : ''
        } ${isDraggingThis ? 'opacity-40' : ''} ${extraClasses}`}
        title={isAdmin ? 'Drag to another group' : undefined}
      >
        <div className="flex items-center space-x-2 min-w-0">
          {isAdmin && (
            <span className="text-gray-400 text-xs flex-shrink-0" aria-hidden>⋮⋮</span>
          )}
          {team.seed && <span className="text-xs bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded flex-shrink-0">#{team.seed}</span>}
          <span className="text-sm text-gray-900 truncate">{team.display_name}</span>
        </div>
      </div>
    );
  };

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

      {isAdmin && groups.length > 0 && (
        <p className="text-xs text-gray-500 mb-3">
          Drag teams between groups, or back to the unassigned pool, to rearrange the bracket.
        </p>
      )}

      {/* Unassigned teams */}
      {(unassigned.length > 0 || (dragging && dragging.sourceGroupId !== null)) && (
        <div
          onDragEnter={(e) => e.preventDefault()}
          onDragOver={handleDragOver('unassigned')}
          onDragLeave={handleDragLeave}
          onDrop={handleDropOnUnassigned}
          className={`mb-4 rounded-lg p-4 transition-colors ${
            isHover('unassigned')
              ? 'bg-yellow-100 border-2 border-yellow-400 border-dashed'
              : 'bg-yellow-50 border border-yellow-200'
          }`}
        >
          <h4 className="text-sm font-semibold text-yellow-800 mb-2">
            Unassigned Teams ({unassigned.length})
          </h4>
          {unassigned.length === 0 ? (
            <p className="text-xs text-yellow-700 italic">Drop here to remove from a group</p>
          ) : (
            <div className="flex flex-wrap gap-2">
              {unassigned.map((team) => {
                const isDraggingThis = dragging?.registrationId === team.registration_id;
                return (
                  <div
                    key={team.registration_id}
                    draggable={isAdmin}
                    onDragStart={handleDragStart(team.registration_id, null)}
                    onDragEnd={() => { setDragging(null); setHoverTarget(null); }}
                    className={`inline-flex items-center bg-white border border-yellow-300 rounded-md px-3 py-1.5 text-sm select-none ${
                      isAdmin ? 'cursor-grab active:cursor-grabbing' : ''
                    } ${isDraggingThis ? 'opacity-40' : ''}`}
                    title={isAdmin ? 'Drag to a group' : undefined}
                  >
                    {isAdmin && <span className="text-gray-400 text-xs mr-1.5" aria-hidden>⋮⋮</span>}
                    {team.seed && <span className="text-xs text-gray-400 mr-1.5">#{team.seed}</span>}
                    <span className="text-gray-900">{team.display_name}</span>
                    {isAdmin && groups.length > 0 && (
                      <select
                        defaultValue=""
                        onChange={(e) => e.target.value && handleMoveTeamViaSelect(team.registration_id, Number(e.target.value))}
                        className="ml-2 border-0 bg-transparent text-xs text-brand-primary cursor-pointer"
                      >
                        <option value="">Move to...</option>
                        {groups.map(g => <option key={g.id} value={g.id}>{g.name}</option>)}
                      </select>
                    )}
                  </div>
                );
              })}
            </div>
          )}
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
            <div
              key={group.id}
              onDragEnter={(e) => e.preventDefault()}
              onDragOver={handleDragOver(group.id)}
              onDragLeave={handleDragLeave}
              onDrop={() => handleDropOnGroup(group.id)}
              className={`bg-white border rounded-lg p-4 transition-colors ${
                isHover(group.id) ? 'border-brand-primary border-2 bg-brand-primary/5' : 'border-gray-200'
              }`}
            >
              <h4 className="font-semibold text-gray-900 mb-3">{group.name}</h4>
              {(!group.teams || group.teams.length === 0) ? (
                <p className="text-sm text-gray-400 italic">
                  {dragging ? 'Drop here' : 'No teams assigned'}
                </p>
              ) : (
                <div className="space-y-1.5">
                  {group.teams.map((team, idx) => (
                    <div key={team.registration_id} className="flex items-center">
                      <span className="text-xs text-gray-400 w-6 flex-shrink-0">{idx + 1}.</span>
                      <div className="flex-1">
                        {teamChip(team, group.id, 'bg-gray-50 hover:bg-gray-100')}
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
