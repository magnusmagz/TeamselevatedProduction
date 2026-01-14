import React, { useState, useEffect, useRef } from 'react';
import { ChatScope } from './ChatWidget';

interface RoleContext {
  role: string;
  scope_type: string;
  scope_id: number;
  scope_name: string;
}

interface Props {
  currentScope: ChatScope | null;
  onScopeChange: (scope: ChatScope) => void;
  currentClubId: number | null;
  activeContext: RoleContext | null;
}

interface Team {
  id: number;
  name: string;
}

export default function ChatScopeSelector({
  currentScope,
  onScopeChange,
  currentClubId,
  activeContext
}: Props) {
  const [isOpen, setIsOpen] = useState(false);
  const [teams, setTeams] = useState<Team[]>([]);
  const [loading, setLoading] = useState(false);
  const dropdownRef = useRef<HTMLDivElement>(null);

  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

  // Fetch teams when dropdown opens
  useEffect(() => {
    if (isOpen && currentClubId && teams.length === 0) {
      fetchTeams();
    }
  }, [isOpen, currentClubId]);

  // Close dropdown when clicking outside
  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target as Node)) {
        setIsOpen(false);
      }
    }
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const fetchTeams = async () => {
    setLoading(true);
    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/legacy/teams-gateway.php?club_id=${currentClubId}`, {
        headers: {
          'Authorization': `Bearer ${token}`
        }
      });
      const data = await response.json();
      if (Array.isArray(data)) {
        setTeams(data.map((t: any) => ({ id: t.id, name: t.name })));
      }
    } catch (error) {
      console.error('Error fetching teams:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleSelectClub = () => {
    if (currentClubId && activeContext) {
      onScopeChange({
        type: 'club',
        id: currentClubId,
        name: activeContext.scope_name
      });
      setIsOpen(false);
    }
  };

  const handleSelectTeam = (team: Team) => {
    onScopeChange({
      type: 'team',
      id: team.id,
      name: team.name
    });
    setIsOpen(false);
  };

  const getScopeIcon = () => {
    if (currentScope?.type === 'club') {
      return (
        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
        </svg>
      );
    }
    return (
      <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
      </svg>
    );
  };

  return (
    <div className="border-b border-brand-secondary bg-brand-secondary" ref={dropdownRef}>
      <button
        onClick={() => setIsOpen(!isOpen)}
        className="w-full px-4 py-2 flex items-center justify-between hover:bg-brand-secondary transition-colors"
      >
        <div className="flex items-center gap-2 text-sm">
          {getScopeIcon()}
          <span className="font-medium text-brand-primary">
            {currentScope?.name || 'Select chat scope'}
          </span>
          <span className="text-xs text-brand-primary bg-brand-secondary px-1.5 py-0.5 rounded">
            {currentScope?.type === 'club' ? 'Club' : 'Team'}
          </span>
        </div>
        <svg
          className={`w-4 h-4 text-brand-primary transition-transform ${isOpen ? 'rotate-180' : ''}`}
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
        </svg>
      </button>

      {/* Dropdown */}
      {isOpen && (
        <div className="absolute left-0 right-0 bg-white border-b border-brand-secondary shadow-lg z-10 max-h-64 overflow-y-auto">
          {/* Club option */}
          {currentClubId && (
            <button
              onClick={handleSelectClub}
              className={`w-full px-4 py-2.5 flex items-center gap-2 hover:bg-brand-secondary text-left ${
                currentScope?.type === 'club' ? 'bg-brand-secondary' : ''
              }`}
            >
              <svg className="w-4 h-4 text-brand-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
              </svg>
              <div>
                <div className="text-sm font-medium text-brand-primary">
                  {activeContext?.scope_name} (Club Chat)
                </div>
                <div className="text-xs text-brand-muted">
                  All members in this club
                </div>
              </div>
            </button>
          )}

          {/* Divider */}
          {teams.length > 0 && (
            <div className="px-4 py-1.5 bg-gray-50 border-y border-gray-100">
              <span className="text-xs font-medium text-gray-500 uppercase">Teams</span>
            </div>
          )}

          {/* Teams */}
          {loading ? (
            <div className="px-4 py-3 text-sm text-gray-500 text-center">
              Loading teams...
            </div>
          ) : (
            teams.map(team => (
              <button
                key={team.id}
                onClick={() => handleSelectTeam(team)}
                className={`w-full px-4 py-2.5 flex items-center gap-2 hover:bg-brand-secondary text-left ${
                  currentScope?.type === 'team' && currentScope?.id === team.id ? 'bg-brand-secondary' : ''
                }`}
              >
                <svg className="w-4 h-4 text-brand-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                <div className="text-sm font-medium text-brand-primary">
                  {team.name}
                </div>
              </button>
            ))
          )}

          {!loading && teams.length === 0 && (
            <div className="px-4 py-3 text-sm text-gray-500 text-center">
              No teams available
            </div>
          )}
        </div>
      )}
    </div>
  );
}
