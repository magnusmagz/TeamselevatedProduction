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
