import React, { useState, useEffect, useRef } from 'react';
import { ChatScope } from './ChatWidget';

interface RoleContext {
  role: string;
  scope_type: string;
  scope_id: number;
  scope_name: string;
  league_id?: number;
}

interface Props {
  currentScope: ChatScope | null;
  onScopeChange: (scope: ChatScope) => void;
  currentLeagueId: number | null;
  activeContext: RoleContext | null;
}

interface Team {
  id: number;
  name: string;
}

export default function ChatScopeSelector({
  currentScope,
  onScopeChange,
  currentLeagueId,
  activeContext
}: Props) {
  const [isOpen, setIsOpen] = useState(false);
  const [teams, setTeams] = useState<Team[]>([]);
  const [loading, setLoading] = useState(false);
  const dropdownRef = useRef<HTMLDivElement>(null);

  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

  // Fetch teams when dropdown opens
  useEffect(() => {
    if (isOpen && currentLeagueId && teams.length === 0) {
      fetchTeams();
    }
  }, [isOpen, currentLeagueId]);

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
      const response = await fetch(`${API_URL}/legacy/teams-gateway.php?league_id=${currentLeagueId}`, {
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

  const handleSelectLeague = () => {
    if (currentLeagueId && activeContext) {
      onScopeChange({
        type: 'league',
        id: currentLeagueId,
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
    if (currentScope?.type === 'league') {
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
    <div className="border-b border-forest-100 bg-forest-50" ref={dropdownRef}>
      <button
        onClick={() => setIsOpen(!isOpen)}
        className="w-full px-4 py-2 flex items-center justify-between hover:bg-forest-100 transition-colors"
      >
        <div className="flex items-center gap-2 text-sm">
          {getScopeIcon()}
          <span className="font-medium text-forest-800">
            {currentScope?.name || 'Select chat scope'}
          </span>
          <span className="text-xs text-forest-600 bg-forest-200 px-1.5 py-0.5 rounded">
            {currentScope?.type === 'league' ? 'League' : 'Team'}
          </span>
        </div>
        <svg
          className={`w-4 h-4 text-forest-600 transition-transform ${isOpen ? 'rotate-180' : ''}`}
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
        </svg>
      </button>

      {/* Dropdown */}
      {isOpen && (
        <div className="absolute left-0 right-0 bg-white border-b border-forest-200 shadow-lg z-10 max-h-64 overflow-y-auto">
          {/* League option */}
          {currentLeagueId && (
            <button
              onClick={handleSelectLeague}
              className={`w-full px-4 py-2.5 flex items-center gap-2 hover:bg-forest-50 text-left ${
                currentScope?.type === 'league' ? 'bg-forest-100' : ''
              }`}
            >
              <svg className="w-4 h-4 text-forest-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
              </svg>
              <div>
                <div className="text-sm font-medium text-forest-800">
                  {activeContext?.scope_name} (League Chat)
                </div>
                <div className="text-xs text-forest-500">
                  All members in this league
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
                className={`w-full px-4 py-2.5 flex items-center gap-2 hover:bg-forest-50 text-left ${
                  currentScope?.type === 'team' && currentScope?.id === team.id ? 'bg-forest-100' : ''
                }`}
              >
                <svg className="w-4 h-4 text-forest-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                <div className="text-sm font-medium text-forest-800">
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
