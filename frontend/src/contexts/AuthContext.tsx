import React, { createContext, useState, useEffect, useContext } from 'react';

interface RoleContext {
  role: 'league_admin' | 'club_admin' | 'coach' | 'parent' | 'player';
  scope_type: 'league' | 'club' | 'team';
  scope_id: number;
  scope_name: string;
  league_id?: number;
}

interface Organization {
  orgId: number | null;
  orgType: 'league' | 'club' | null;
  orgName: string | null;
}

interface User {
  id: number;
  email: string;
  name: string;
  system_role?: 'super_admin' | 'user';
  organization?: Organization;
  roles?: RoleContext[];
  activeRole?: RoleContext | null;
}

interface AuthContextType {
  user: User | null;
  isLoading: boolean;
  error: Error | null;
  login: (token: string, user: User) => void;
  updateUser: (userData: Partial<User>) => void;
  refreshAuth: () => Promise<void>;
  logout: () => Promise<void>;
  switchContext: (scopeId: number, scopeType: 'league' | 'club') => Promise<void>;
  hasPermission: (permission: string) => boolean;
  isSuperAdmin: () => boolean;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<Error | null>(null);

  const checkAuth = async () => {
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

      const response = await fetch(`${API_URL}/api/auth-gateway.php?action=verify-session`, {
        headers: {
          'Accept': 'application/json',
          'Authorization': `Bearer ${token}`
        }
      });

      console.log('[AuthContext] checkAuth - Response status:', response.status);

      if (response.ok) {
        const data = await response.json();
        console.log('[AuthContext] checkAuth - Response data:', data);
        if (data.authenticated && data.user) {
          console.log('[AuthContext] checkAuth - User authenticated:', data.user);
          setUser(data.user);

          // If a fresh token is returned, update it in localStorage
          if (data.token) {
            localStorage.setItem('auth_token', data.token);
          }
        } else {
          console.log('[AuthContext] checkAuth - Not authenticated');
          setUser(null);
          localStorage.removeItem('auth_token'); // Clear invalid token
        }
      } else {
        console.log('[AuthContext] checkAuth - Response not OK');
        setUser(null);
        localStorage.removeItem('auth_token'); // Clear invalid token
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

  const switchContext = async (scopeId: number, scopeType: 'league' | 'club') => {
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
      'create_league': ['super_admin'],
      'manage_league': ['super_admin', 'league_admin'],
      'create_club': ['super_admin', 'league_admin'],
      'manage_club': ['super_admin', 'league_admin', 'club_admin'],
      'create_team': ['super_admin', 'league_admin', 'club_admin'],
      'manage_team': ['super_admin', 'league_admin', 'club_admin', 'coach'],
      'view_team': ['super_admin', 'league_admin', 'club_admin', 'coach', 'parent'],
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
      isSuperAdmin
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
