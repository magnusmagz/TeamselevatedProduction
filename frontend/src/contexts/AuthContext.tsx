import React, { createContext, useState, useEffect, useContext } from 'react';

interface RoleContext {
  role: 'club_admin' | 'coach' | 'parent' | 'player';
  scope_type: 'club' | 'team';
  scope_id: number;
  /**
   * Dropped from `roles` by the G2 token diet (TE_FEATURE_SLIM_TOKEN); still
   * present on `activeRole`. Optional because it genuinely is — see the
   * senderId string/number bug for what a type that asserts something false
   * costs. OrgContext backfills the names from api/my-context.php.
   */
  scope_name?: string;
}

interface Organization {
  orgId: number | null;
  orgType: 'club' | null;
  orgName: string | null;
}

export interface Impersonation {
  by: number;
  by_email: string | null;
  by_name: string | null;
  started_at: number;
  /** Unix seconds. The token expires at the same moment. */
  exp: number;
}

interface User {
  id: number;
  email: string;
  name: string;
  phone?: string;
  system_role?: 'super_admin' | 'user';
  organization?: Organization;
  roles?: RoleContext[];
  activeRole?: RoleContext | null;
  /** Set only while a super admin is signed in as this user. */
  impersonation?: Impersonation | null;
}

/**
 * The super admin's own token, parked here for the length of an impersonation.
 *
 * The server can always end an impersonation while its token is still valid
 * (`stop-impersonation` reads the admin's id off the `imp` claim). This exists
 * for the case where it CANNOT: the impersonation token expired, so the claim is
 * unreadable and the admin would otherwise be bounced to the login screen for
 * doing nothing but taking an hour. It is a fallback, never the primary path.
 */
const IMPERSONATOR_TOKEN_KEY = 'auth_token_impersonator';

interface AuthContextType {
  user: User | null;
  isLoading: boolean;
  error: Error | null;
  login: (token: string, user: User) => void;
  updateUser: (userData: Partial<User>) => void;
  refreshAuth: () => Promise<void>;
  logout: () => Promise<void>;
  switchContext: (scopeId: number, scopeType: 'club') => Promise<void>;
  hasPermission: (permission: string) => boolean;
  isSuperAdmin: () => boolean;
  impersonation: Impersonation | null;
  impersonate: (userId: number) => Promise<void>;
  stopImpersonation: () => Promise<void>;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

/**
 * Verify one token with the server.
 *
 * Split out of checkAuth() so the impersonator fallback is a second call rather
 * than a recursive one, and kept at module scope so checkAuth() closes over
 * nothing unstable — an in-component helper makes checkAuth a reactive value and
 * adds an exhaustive-deps warning, and the lint ceiling only goes down.
 */
const verifySession = async (token: string): Promise<{ user: User; token?: string } | null> => {
  const response = await fetch(`${API_URL}/api/auth-gateway.php?action=verify-session`, {
    headers: {
      'Accept': 'application/json',
      'Authorization': `Bearer ${token}`
    }
  });

  console.log('[AuthContext] verifySession - Response status:', response.status);

  if (!response.ok) {
    return null;
  }

  const data = await response.json();
  if (!data.authenticated || !data.user) {
    return null;
  }

  return { user: data.user, token: data.token };
};

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<Error | null>(null);

  const checkAuth = async (): Promise<void> => {
    try {
      console.log('[AuthContext] checkAuth - Starting...');
      setIsLoading(true);
      setError(null);

      // Get token from localStorage
      const token = localStorage.getItem('auth_token');

      if (!token) {
        console.log('[AuthContext] checkAuth - No token found');
        setUser(null);
        setIsLoading(false);
        return;
      }

      let result = await verifySession(token);

      if (!result) {
        localStorage.removeItem('auth_token'); // Clear invalid token

        // An impersonation token dies at the one-hour mark, so "session
        // invalid" is the expected end of every abandoned one. Fall back to the
        // admin's parked token rather than bouncing them to the login screen
        // for doing nothing but taking an hour.
        const parked = localStorage.getItem(IMPERSONATOR_TOKEN_KEY);
        if (parked) {
          localStorage.removeItem(IMPERSONATOR_TOKEN_KEY);
          result = await verifySession(parked);
          if (result) {
            localStorage.setItem('auth_token', parked);
          }
        }
      }

      if (result) {
        console.log('[AuthContext] checkAuth - User authenticated:', result.user);
        setUser(result.user);

        // If a fresh token is returned, update it in localStorage
        if (result.token) {
          localStorage.setItem('auth_token', result.token);
        }
      } else {
        console.log('[AuthContext] checkAuth - Not authenticated');
        setUser(null);
      }
    } catch (err) {
      console.error('[AuthContext] checkAuth - Error:', err);
      setError(err instanceof Error ? err : new Error('Authentication check failed'));
      setUser(null);
    } finally {
      setIsLoading(false);
      console.log('[AuthContext] checkAuth - Finished');
    }
  };

  const login = (token: string, userData: User) => {
    localStorage.setItem('auth_token', token);
    setUser(userData);
  };

  const updateUser = (userData: Partial<User>) => {
    setUser((prevUser) => {
      if (!prevUser) return null;
      return { ...prevUser, ...userData };
    });
  };

  const logout = async () => {
    try {
      // Clear token from localStorage
      localStorage.removeItem('auth_token');
      localStorage.removeItem(IMPERSONATOR_TOKEN_KEY);
      setUser(null);

      // Optional: notify backend (though token is already removed locally)
      const token = localStorage.getItem('auth_token');
      if (token) {
        await fetch(`${API_URL}/api/auth-gateway.php?action=logout`, {
          method: 'POST',
          headers: {
            'Accept': 'application/json',
            'Authorization': `Bearer ${token}`
          }
        });
      }
    } catch (err) {
      console.error('Logout failed:', err);
      // Still clear local state even if API call fails
      localStorage.removeItem('auth_token');
      setUser(null);
    }
  };

  /**
   * Start impersonating a user. Super admin only — enforced server-side; this
   * only drives the UI.
   */
  const impersonate = async (userId: number) => {
    const adminToken = localStorage.getItem('auth_token');
    if (!adminToken) {
      throw new Error('Not authenticated');
    }

    const response = await fetch(`${API_URL}/api/super-admin-gateway.php?action=impersonate`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'Authorization': `Bearer ${adminToken}`
      },
      body: JSON.stringify({ user_id: userId })
    });

    const data = await response.json();
    if (!response.ok || !data.token) {
      throw new Error(data.error || 'Could not start impersonation');
    }

    // Park the admin's own token BEFORE overwriting it, or an expired
    // impersonation has nothing to fall back to.
    localStorage.setItem(IMPERSONATOR_TOKEN_KEY, adminToken);
    localStorage.setItem('auth_token', data.token);
    setUser(data.user);
  };

  /** End an impersonation and return to the super admin's own session. */
  const stopImpersonation = async () => {
    const token = localStorage.getItem('auth_token');

    try {
      const response = await fetch(`${API_URL}/api/auth-gateway.php?action=stop-impersonation`, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Authorization': `Bearer ${token}`
        }
      });

      const data = await response.json();
      if (response.ok && data.token) {
        localStorage.setItem('auth_token', data.token);
        localStorage.removeItem(IMPERSONATOR_TOKEN_KEY);
        setUser(data.user);
        return;
      }
    } catch (err) {
      console.error('[AuthContext] stopImpersonation - Error:', err);
    }

    // The server could not end it — most likely the token expired mid-session.
    // Fall back to the parked admin token rather than leaving the operator
    // stuck inside someone else's account with a dead Exit button.
    const parked = localStorage.getItem(IMPERSONATOR_TOKEN_KEY);
    if (parked) {
      localStorage.setItem('auth_token', parked);
      localStorage.removeItem(IMPERSONATOR_TOKEN_KEY);
      await checkAuth();
      return;
    }

    await logout();
  };

  const switchContext = async (scopeId: number, scopeType: 'club') => {
    try {
      console.log('[AuthContext] switchContext - Switching to:', { scopeId, scopeType });
      setIsLoading(true);
      setError(null);

      const token = localStorage.getItem('auth_token');
      if (!token) {
        throw new Error('Not authenticated');
      }

      const response = await fetch(`${API_URL}/api/auth-gateway.php?action=switch-context`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify({
          scope_id: scopeId,
          scope_type: scopeType
        })
      });

      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.error || 'Context switch failed');
      }

      const data = await response.json();
      console.log('[AuthContext] switchContext - Success:', data);

      // Update token and user
      if (data.token && data.user) {
        localStorage.setItem('auth_token', data.token);
        setUser(data.user);
      }
    } catch (err) {
      console.error('[AuthContext] switchContext - Error:', err);
      setError(err instanceof Error ? err : new Error('Context switch failed'));
      throw err;
    } finally {
      setIsLoading(false);
    }
  };

  const hasPermission = (permission: string): boolean => {
    if (!user) return false;

    // Super admins have all permissions
    if (user.system_role === 'super_admin') return true;

    // Check if user has the required role
    const activeRole = user.activeRole?.role;

    // Permission mapping (simplified - can be expanded)
    const permissionRoleMap: { [key: string]: string[] } = {
      'create_club': ['super_admin'],
      'manage_club': ['super_admin', 'club_admin'],
      'create_team': ['super_admin', 'club_admin'],
      'manage_team': ['super_admin', 'club_admin', 'coach'],
      'view_team': ['super_admin', 'club_admin', 'coach', 'parent'],
    };

    const requiredRoles = permissionRoleMap[permission] || [];
    return activeRole ? requiredRoles.includes(activeRole) : false;
  };

  const isSuperAdmin = (): boolean => {
    return user?.system_role === 'super_admin';
  };

  useEffect(() => {
    checkAuth();
  }, []);

  const refreshAuth = async () => {
    await checkAuth();
  };

  return (
    <AuthContext.Provider value={{
      user,
      isLoading,
      error,
      login,
      updateUser,
      refreshAuth,
      logout,
      switchContext,
      hasPermission,
      isSuperAdmin,
      impersonation: user?.impersonation ?? null,
      impersonate,
      stopImpersonation
    }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (context === undefined) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
}
