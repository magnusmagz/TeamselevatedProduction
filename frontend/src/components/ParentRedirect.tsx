import React, { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useFinancialPermissions } from '../contexts/FinancialPermissionsContext';

interface ParentRedirectProps {
  children: React.ReactNode;
}

/**
 * Wrapper component that redirects parent-only users to the parent portal.
 * Users with coach/admin roles stay on the dashboard.
 */
export const ParentRedirect: React.FC<ParentRedirectProps> = ({ children }) => {
  const navigate = useNavigate();
  const { roles, loading } = useFinancialPermissions();

  useEffect(() => {
    if (loading) return;

    // Check if user is parent-only (has parent role but no coach/admin roles)
    const isParentOnly = roles.is_parent && !roles.is_coach && !roles.is_club_admin && !roles.is_treasurer;

    console.log('[ParentRedirect] roles:', roles, 'isParentOnly:', isParentOnly);

    if (isParentOnly) {
      console.log('[ParentRedirect] Redirecting parent to /parent');
      navigate('/parent', { replace: true });
    }
  }, [roles, loading, navigate]);

  // Show loading while checking permissions
  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <div className="text-center">
          <div className="mx-auto h-16 w-16 bg-brand-secondary flex items-center justify-center mb-4 animate-pulse">
            <svg className="h-8 w-8 text-brand-primary animate-spin" fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
            </svg>
          </div>
          <p className="text-gray-600 font-semibold">LOADING...</p>
        </div>
      </div>
    );
  }

  // Parent-only users will be redirected, but render nothing briefly
  const isParentOnly = roles.is_parent && !roles.is_coach && !roles.is_club_admin && !roles.is_treasurer;
  if (isParentOnly) {
    return null;
  }

  return <>{children}</>;
};

export default ParentRedirect;
