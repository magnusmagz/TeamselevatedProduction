import React from 'react';
import { Navigate } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';
import { useFinancialPermissions } from '../contexts/FinancialPermissionsContext';

interface ProtectedParentRouteProps {
  children: React.ReactNode;
}

export const ProtectedParentRoute: React.FC<ProtectedParentRouteProps> = ({ children }) => {
  const { user, isLoading: authLoading } = useAuth();
  const { roles, loading: permissionsLoading } = useFinancialPermissions();

  const isLoading = authLoading || permissionsLoading;

  console.log('[ProtectedParentRoute] authLoading:', authLoading, 'permissionsLoading:', permissionsLoading, 'roles:', roles);

  if (isLoading) {
    console.log('[ProtectedParentRoute] Still loading, showing spinner');
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

  // Must be authenticated
  if (!user) {
    return <Navigate to="/login" replace />;
  }

  // Check if user has parent role or has accessible athletes (which indicates parent/guardian relationship)
  // Parents can access the portal, but coaches and admins should use the main app
  const hasParentAccess = roles.is_parent;

  if (!hasParentAccess) {
    // Not a parent - redirect to main dashboard
    console.log('[ProtectedParentRoute] No parent access, redirecting to /dashboard');
    return <Navigate to="/dashboard" replace />;
  }

  console.log('[ProtectedParentRoute] Parent access granted, rendering children');

  return <>{children}</>;
};

export default ProtectedParentRoute;
