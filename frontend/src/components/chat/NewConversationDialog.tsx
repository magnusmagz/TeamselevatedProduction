import React, { useState, useEffect, useCallback } from 'react';
import { useOrg } from '../../contexts/OrgContext';
import { chatSocket } from './chatSocket';
import type { TeamMember } from './types';

interface Props {
  onClose: () => void;
  onCreate: (participantIds: number[]) => void;
}

interface Team {
  id: number;
  name: string;
}

export default function NewConversationDialog({ onClose, onCreate }: Props) {
  const { currentClubId } = useOrg();
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

  const [teams, setTeams] = useState<Team[]>([]);
  const [selectedTeamId, setSelectedTeamId] = useState<number | null>(null);
  const [members, setMembers] = useState<TeamMember[]>([]);
  const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());
  const [searchQuery, setSearchQuery] = useState('');
  const [loadingTeams, setLoadingTeams] = useState(true);
  const [loadingMembers, setLoadingMembers] = useState(false);

  // Fetch teams
  useEffect(() => {
    if (!currentClubId) return;

    const fetchTeams = async () => {
      try {
        const token = localStorage.getItem('auth_token');
        const response = await fetch(`${API_URL}/legacy/teams-gateway.php?club_id=${currentClubId}`, {
          headers: { 'Authorization': `Bearer ${token}` }
        });
        const data = await response.json();
        const teamsList = Array.isArray(data) ? data : (data.teams || []);
        setTeams(teamsList.map((t: any) => ({ id: t.id, name: t.name })));
      } catch (error) {
        console.error('Error fetching teams:', error);
      } finally {
        setLoadingTeams(false);
      }
    };

    fetchTeams();
  }, [currentClubId, API_URL]);

  // Listen for team members response
  useEffect(() => {
    const socket = chatSocket.getSocket();
    if (!socket) return;

    const handleTeamMembers = (data: { teamId: number; members: TeamMember[] }) => {
      if (data.teamId === selectedTeamId) {
        setMembers(data.members);
        setLoadingMembers(false);
      }
    };

    socket.on('teamMembers', handleTeamMembers);
    return () => {
      socket.off('teamMembers', handleTeamMembers);
    };
  }, [selectedTeamId]);

  // Load team members when team is selected
  const handleTeamSelect = useCallback((teamId: number) => {
    setSelectedTeamId(teamId);
    setMembers([]);
    setSelectedIds(new Set());
    setSearchQuery('');
    setLoadingMembers(true);
    chatSocket.getTeamMembers(teamId);
  }, []);

  const toggleMember = (userId: number) => {
    setSelectedIds(prev => {
      const next = new Set(prev);
      if (next.has(userId)) {
        next.delete(userId);
      } else {
        next.add(userId);
      }
      return next;
    });
  };

  const handleCreate = () => {
    const ids = Array.from(selectedIds);
    if (ids.length > 0) {
      onCreate(ids);
    }
  };

  const filteredMembers = members.filter(m =>
    m.displayName?.toLowerCase().includes(searchQuery.toLowerCase())
  );

  return (
    <div className="flex flex-col h-full">
      {/* Header */}
      <div className="bg-brand-primary text-white px-4 py-3 flex items-center gap-2 flex-shrink-0">
        <button onClick={onClose} className="p-1 hover:bg-white/20 rounded transition-colors">
          <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
          </svg>
        </button>
        <span className="font-medium">New Message</span>
      </div>

      <div className="flex-1 overflow-y-auto">
        {/* Team Selector */}
        <div className="px-4 py-3 border-b border-gray-100">
          <label className="text-xs font-medium text-gray-500 uppercase tracking-wider">Select Team</label>
          {loadingTeams ? (
            <div className="flex items-center justify-center py-4">
              <div className="animate-spin rounded-full h-6 w-6 border-b-2 border-brand-primary" />
            </div>
          ) : (
            <div className="flex gap-2 flex-wrap mt-2">
              {teams.map(team => (
                <button
                  key={team.id}
                  onClick={() => handleTeamSelect(team.id)}
                  className={`px-3 py-1.5 rounded-full text-xs font-medium transition-colors ${
                    selectedTeamId === team.id
                      ? 'bg-brand-primary text-white'
                      : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                  }`}
                >
                  {team.name}
                </button>
              ))}
              {teams.length === 0 && (
                <p className="text-sm text-gray-400">No teams available</p>
              )}
            </div>
          )}
        </div>

        {/* Members list */}
        {selectedTeamId && (
          <div>
            {/* Search */}
            <div className="px-4 py-2 border-b border-gray-100">
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Search members..."
                className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-accent focus:border-transparent"
              />
            </div>

            {loadingMembers ? (
              <div className="flex items-center justify-center py-8">
                <div className="animate-spin rounded-full h-6 w-6 border-b-2 border-brand-primary" />
              </div>
            ) : (
              <div>
                {filteredMembers.length === 0 && (
                  <div className="text-center text-gray-400 text-sm py-8">
                    No members found
                  </div>
                )}

                {filteredMembers.map(member => (
                  <button
                    key={member.userId}
                    onClick={() => toggleMember(member.userId)}
                    className="w-full px-4 py-3 flex items-center gap-3 hover:bg-gray-50 transition-colors text-left"
                  >
                    {/* Checkbox */}
                    <div className={`w-5 h-5 rounded border-2 flex items-center justify-center flex-shrink-0 transition-colors ${
                      selectedIds.has(member.userId)
                        ? 'bg-brand-primary border-brand-primary'
                        : 'border-gray-300'
                    }`}>
                      {selectedIds.has(member.userId) && (
                        <svg className="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={3} d="M5 13l4 4L19 7" />
                        </svg>
                      )}
                    </div>

                    {/* Name and role */}
                    <div className="flex-1 min-w-0">
                      <span className="text-sm font-medium text-gray-800 truncate block">
                        {member.displayName}
                      </span>
                      <span className="text-xs text-gray-400 capitalize">{member.role}</span>
                    </div>
                  </button>
                ))}
              </div>
            )}
          </div>
        )}
      </div>

      {/* Footer */}
      {selectedIds.size > 0 && (
        <div className="px-4 py-3 border-t border-gray-100 flex-shrink-0">
          <button
            onClick={handleCreate}
            className="w-full py-2.5 bg-brand-primary text-white text-sm font-medium rounded-lg hover:bg-brand-primary/90 transition-colors"
          >
            Start Chat ({selectedIds.size} {selectedIds.size === 1 ? 'person' : 'people'})
          </button>
        </div>
      )}
    </div>
  );
}
