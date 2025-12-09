import React, { createContext, useState, useEffect, useContext } from 'react';
import { useAuth } from './AuthContext';

interface RoleContext {
  role: 'league_admin' | 'club_admin' | 'coach' | 'parent' | 'player';
  scope_type: 'league' | 'club' | 'team';
  scope_id: number;
  scope_name: string;
  league_id?: number;
}

interface OrgContextType {
  activeContext: RoleContext | null;
  availableContexts: RoleContext[];
  switchToContext: (scopeId: number, scopeType: 'league' | 'club') => Promise<void>;
  isLeagueAdmin: boolean;
  isClubAdmin: boolean;
  currentLeagueId: number | null;
  currentClubId: number | null;
  getClubsInActiveLeague: () => RoleContext[];
}

const OrgContext = createContext<OrgContextType | undefined>(undefined);

export function OrgProvider({ children }: { children: React.ReactNode }) {
  const { user, switchContext } = useAuth();
  const [activeContext, setActiveContext] = useState<RoleContext | null>(null);
  const [availableContexts, setAvailableContexts] = useState<RoleContext[]>([]);

  // Sync with user's active role from AuthContext
  useEffect(() => {
    if (user) {
      setActiveContext(user.activeRole || null);
      setAvailableContexts(user.roles || []);

      // Store active context in localStorage for persistence
      if (user.activeRole) {
        localStorage.setItem('active_org_context', JSON.stringify(user.activeRole));
      }
    } else {
      setActiveContext(null);
      setAvailableContexts([]);
      localStorage.removeItem('active_org_context');
    }
  }, [user]);

  const switchToContext = async (scopeId: number, scopeType: 'league' | 'club') => {
    await switchContext(scopeId, scopeType);
    // User state will be updated via AuthContext, which will trigger the useEffect above
  };

  // Helper: Check if user is league admin in active context
  const isLeagueAdmin = activeContext?.role === 'league_admin' && activeContext.scope_type === 'league';

  // Helper: Check if user is club admin in active context
  const isClubAdmin = activeContext?.role === 'club_admin' && activeContext.scope_type === 'club';

  // Helper: Get current league ID
  const currentLeagueId = (() => {
    if (!activeContext) return null;
    if (activeContext.scope_type === 'league') return activeContext.scope_id;
    if (activeContext.scope_type === 'club') return activeContext.league_id || null;
    return null;
  })();

  // Helper: Get current club ID
  const currentClubId = (() => {
    if (!activeContext) return null;
    if (activeContext.scope_type === 'club') return activeContext.scope_id;
    return null;
  })();

  // Helper: Get all clubs in the active league
  const getClubsInActiveLeague = (): RoleContext[] => {
    if (!currentLeagueId) return [];

    return availableContexts.filter(
      (ctx) => ctx.scope_type === 'club' && ctx.league_id === currentLeagueId
    );
  };

  return (
    <OrgContext.Provider
      value={{
        activeContext,
        availableContexts,
        switchToContext,
        isLeagueAdmin,
        isClubAdmin,
        currentLeagueId,
        currentClubId,
        getClubsInActiveLeague,
      }}
    >
      {children}
    </OrgContext.Provider>
  );
}

export function useOrg() {
  const context = useContext(OrgContext);
  if (context === undefined) {
    throw new Error('useOrg must be used within an OrgProvider');
  }
  return context;
}
