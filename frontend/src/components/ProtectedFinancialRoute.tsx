import React, { ReactNode } from 'react';
import { Navigate } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { useOrg } from '../contexts/OrgContext';

interface Props {
  children: ReactNode;
  requiredPermission?: 'revenue' | 'transactions' | 'roster_fees' | 'reminders' | 'any';
}

/**
 * ProtectedFinancialRoute
 * Protects financial pages based on user permissions
 * Uses OrgContext to determine admin status (league_admin or club_admin have financial access)
 */
export const ProtectedFinancialRoute: React.FC<Props> = ({
  children,
  requiredPermission = 'any'
}) => {
  const { user, isLoading: authLoading } = useAuth();
  const { isLeagueAdmin, isClubAdmin } = useOrg();

  // Show loading while checking auth
  if (authLoading) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="text-gray-500">Loading...</div>
      </div>
    );
  }

  // Redirect to login if not authenticated
  if (!user) {
    return <Navigate to="/login" replace />;
  }

  // League admins and club admins have full financial access
  const hasAccess = isLeagueAdmin || isClubAdmin;

  if (!hasAccess) {
    return (
      <div className="max-w-lg mx-auto mt-12 p-6 bg-white rounded-lg shadow">
        <div className="text-center">
          <svg
            className="mx-auto h-12 w-12 text-gray-400"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              strokeWidth={2}
              d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"
            />
          </svg>
          <h2 className="mt-4 text-lg font-medium text-gray-900">Access Restricted</h2>
          <p className="mt-2 text-sm text-gray-500">
            You don't have permission to view this financial data.
            Please contact your league administrator if you need access.
          </p>
          <button
            onClick={() => window.history.back()}
            className="mt-4 px-4 py-2 bg-brand-primary text-white rounded hover:bg-brand-primary"
          >
            Go Back
          </button>
        </div>
      </div>
    );
  }

  return <>{children}</>;
};

export default ProtectedFinancialRoute;
