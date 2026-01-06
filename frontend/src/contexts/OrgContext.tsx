import React, { createContext, useState, useEffect, useContext } from 'react';
import { useAuth } from './AuthContext';

interface RoleContext {
  role: 'club_admin' | 'coach' | 'parent' | 'player';
  scope_type: 'club' | 'team';
  scope_id: number;
  scope_name: string;
}

interface OrgContextType {
  activeContext: RoleContext | null;
  availableContexts: RoleContext[];
  switchToContext: (scopeId: number, scopeType: 'club') => Promise<void>;
  isClubAdmin: boolean;
  currentClubId: number | null;
}

const OrgContext = createContext<OrgContextType | undefined>(undefined);

export function OrgProvider({ children }: { children: React.ReactNode }) {
  const { user } = useAuth();
  const [activeContext, setActiveContext] = useState<RoleContext | null>(null);
  const [availableContexts, setAvailableContexts] = useState<RoleContext[]>([]);

  // Sync with user's active role from AuthContext
  useEffect(() => {
    if (user) {
      // Get roles and activeRole from user (populated by backend JWT)
      const userRoles = user.roles || [];
      const userActiveRole = user.activeRole || null;

      // If user has roles but no active role set, use the first available role
      const active = userActiveRole || (userRoles.length > 0 ? userRoles[0] : null);

      setActiveContext(active);
      setAvailableContexts(userRoles);

      // Store in localStorage for persistence
      if (active) {
        localStorage.setItem('active_org_context', JSON.stringify(active));
      }
    } else {
      setActiveContext(null);
      setAvailableContexts([]);
      localStorage.removeItem('active_org_context');
    }
  }, [user]);

  const switchToContext = async (scopeId: number, scopeType: 'club') => {
    // TODO: Implement context switching via API
    console.log('Context switch not yet implemented:', scopeId, scopeType);
  };

  // Helper: Check if user is club admin in active context
  const isClubAdmin = activeContext?.role === 'club_admin' && activeContext.scope_type === 'club';

  // Helper: Get current club ID
  const currentClubId = (() => {
    if (!activeContext) return null;
    if (activeContext.scope_type === 'club') return activeContext.scope_id;
    return null;
  })();

  return (
    <OrgContext.Provider
      value={{
        activeContext,
        availableContexts,
        switchToContext,
        isClubAdmin,
        currentClubId,
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
