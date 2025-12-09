import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { useOrg } from '../contexts/OrgContext';

const LeagueSelector: React.FC = () => {
  const navigate = useNavigate();
  const { user } = useAuth();
  const { activeContext, availableContexts, switchToContext, isLeagueAdmin, isClubAdmin } = useOrg();
  const [isOpen, setIsOpen] = useState(false);
  const [isLoading, setIsLoading] = useState(false);

  if (!user || !availableContexts || availableContexts.length === 0) {
    return null;
  }

  // Group contexts by league
  const groupedContexts = availableContexts.reduce((acc, ctx) => {
    if (ctx.scope_type === 'league') {
      if (!acc[ctx.scope_id]) {
        acc[ctx.scope_id] = {
          league: ctx,
          clubs: []
        };
      }
    } else if (ctx.scope_type === 'club' && ctx.league_id) {
      if (!acc[ctx.league_id]) {
        acc[ctx.league_id] = {
          league: null,
          clubs: []
        };
      }
      acc[ctx.league_id].clubs.push(ctx);
    }
    return acc;
  }, {} as Record<number, { league: any; clubs: any[] }>);

  const handleContextSwitch = async (scopeId: number, scopeType: 'league' | 'club') => {
    setIsLoading(true);
    try {
      await switchToContext(scopeId, scopeType);
      setIsOpen(false);
    } catch (error) {
      console.error('Failed to switch context:', error);
      alert('Failed to switch organization. Please try again.');
    } finally {
      setIsLoading(false);
    }
  };

  const getActiveContextDisplay = () => {
    if (!activeContext) return 'Select Organization';

    const roleDisplay = activeContext.role.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase());
    return `${roleDisplay} - ${activeContext.scope_name}`;
  };

  return (
    <div className="relative">
      <button
        onClick={() => setIsOpen(!isOpen)}
        disabled={isLoading}
        className="flex items-center space-x-2 px-4 py-2 bg-forest-800 text-white rounded-md hover:bg-forest-700 transition-colors disabled:opacity-50"
      >
        <svg
          className="w-5 h-5"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeWidth={2}
            d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"
          />
        </svg>
        <span className="text-sm font-medium uppercase">{getActiveContextDisplay()}</span>
        <svg
          className={`w-4 h-4 transition-transform ${isOpen ? 'rotate-180' : ''}`}
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeWidth={2}
            d="M19 9l-7 7-7-7"
          />
        </svg>
      </button>

      {isOpen && (
        <>
          {/* Backdrop */}
          <div
            className="fixed inset-0 z-10"
            onClick={() => setIsOpen(false)}
          />

          {/* Dropdown */}
          <div className="absolute right-0 mt-2 w-80 bg-white border border-forest-200 rounded-md shadow-lg z-20 max-h-96 overflow-y-auto">
            <div className="px-4 py-3 border-b border-forest-200">
              <p className="text-sm font-semibold text-forest-800 uppercase">
                Select Organization
              </p>
              <p className="text-xs text-gray-600 mt-1">
                Switch between leagues and clubs you have access to
              </p>
            </div>

            <div className="py-2">
              {Object.entries(groupedContexts).map(([leagueId, group]) => (
                <div key={leagueId} className="mb-2">
                  {/* League Option */}
                  {group.league && (
                    <button
                      onClick={() => handleContextSwitch(group.league.scope_id, 'league')}
                      disabled={isLoading}
                      className={`w-full text-left px-4 py-2 hover:bg-forest-50 transition-colors disabled:opacity-50 ${
                        activeContext?.scope_id === group.league.scope_id &&
                        activeContext?.scope_type === 'league'
                          ? 'bg-forest-100 border-l-4 border-forest-800'
                          : ''
                      }`}
                    >
                      <div className="flex items-center space-x-2">
                        <svg
                          className="w-4 h-4 text-forest-800"
                          fill="none"
                          stroke="currentColor"
                          viewBox="0 0 24 24"
                        >
                          <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            strokeWidth={2}
                            d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                          />
                        </svg>
                        <div className="flex-1">
                          <p className="text-sm font-medium text-forest-800">
                            {group.league.scope_name}
                          </p>
                          <p className="text-xs text-gray-600 uppercase">
                            {group.league.role.replace('_', ' ')}
                          </p>
                        </div>
                      </div>
                    </button>
                  )}

                  {/* Club Options */}
                  {group.clubs.length > 0 && (
                    <div className="ml-4 mt-1 border-l-2 border-gray-200">
                      {group.clubs.map((club) => (
                        <button
                          key={club.scope_id}
                          onClick={() => handleContextSwitch(club.scope_id, 'club')}
                          disabled={isLoading}
                          className={`w-full text-left px-4 py-2 hover:bg-forest-50 transition-colors disabled:opacity-50 ${
                            activeContext?.scope_id === club.scope_id &&
                            activeContext?.scope_type === 'club'
                              ? 'bg-forest-100 border-l-4 border-forest-600'
                              : ''
                          }`}
                        >
                          <div className="flex items-center space-x-2">
                            <svg
                              className="w-4 h-4 text-forest-600"
                              fill="none"
                              stroke="currentColor"
                              viewBox="0 0 24 24"
                            >
                              <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                strokeWidth={2}
                                d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"
                              />
                            </svg>
                            <div className="flex-1">
                              <p className="text-sm font-medium text-forest-800">
                                {club.scope_name}
                              </p>
                              <p className="text-xs text-gray-600 uppercase">
                                {club.role.replace('_', ' ')}
                              </p>
                            </div>
                          </div>
                        </button>
                      ))}
                    </div>
                  )}
                </div>
              ))}
            </div>

            {/* Invitations Link (for admins only) */}
            {(isLeagueAdmin || isClubAdmin) && (
              <>
                <div className="border-t border-forest-200 my-2"></div>
                <button
                  onClick={() => {
                    navigate('/invitations');
                    setIsOpen(false);
                  }}
                  className="w-full text-left px-4 py-2 hover:bg-forest-50 transition-colors"
                >
                  <div className="flex items-center space-x-2">
                    <svg
                      className="w-4 h-4 text-forest-600"
                      fill="none"
                      stroke="currentColor"
                      viewBox="0 0 24 24"
                    >
                      <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        strokeWidth={2}
                        d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"
                      />
                    </svg>
                    <div className="flex-1">
                      <p className="text-sm font-medium text-forest-800">
                        Manage Invitations
                      </p>
                      <p className="text-xs text-gray-600">
                        Invite users to your organization
                      </p>
                    </div>
                  </div>
                </button>
              </>
            )}
          </div>
        </>
      )}
    </div>
  );
};

export default LeagueSelector;
