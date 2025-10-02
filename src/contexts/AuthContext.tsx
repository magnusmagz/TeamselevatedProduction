import React, { createContext, useState, useEffect, useContext } from 'react';

interface User {
  id: number;
  email: string;
  name: string;
}

interface AuthContextType {
  user: User | null;
  isLoading: boolean;
  error: Error | null;
  refreshAuth: () => Promise<void>;
  logout: () => Promise<void>;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8888/teamselevated-backend';

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<Error | null>(null);

  const checkAuth = async () => {
    try {
      console.log('[AuthContext] checkAuth - Starting...');
      setIsLoading(true);
      setError(null);

      const response = await fetch(`${API_URL}/api/auth-gateway.php?action=verify-session`, {
        credentials: 'include', // Important: send cookies
        headers: {
          'Accept': 'application/json',
        }
      });

      console.log('[AuthContext] checkAuth - Response status:', response.status);

      if (response.ok) {
        const data = await response.json();
        console.log('[AuthContext] checkAuth - Response data:', data);
        if (data.authenticated && data.user) {
          console.log('[AuthContext] checkAuth - User authenticated:', data.user);
          setUser(data.user);
        } else {
          console.log('[AuthContext] checkAuth - Not authenticated');
          setUser(null);
        }
      } else {
        console.log('[AuthContext] checkAuth - Response not OK');
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

  const logout = async () => {
    try {
      const response = await fetch(`${API_URL}/api/auth-gateway.php?action=logout`, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Accept': 'application/json',
        }
      });

      if (response.ok) {
        setUser(null);
      }
    } catch (err) {
      console.error('Logout failed:', err);
    }
  };

  useEffect(() => {
    checkAuth();
  }, []);

  const refreshAuth = async () => {
    await checkAuth();
  };

  return (
    <AuthContext.Provider value={{ user, isLoading, error, refreshAuth, logout }}>
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
