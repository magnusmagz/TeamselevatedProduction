import React from 'react';
import { Navigate } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';
import { useOrg } from '../contexts/OrgContext';

/**
 * Route guard for club-admin-only pages.
 *
 * `ProtectedRoute` checks only that someone is signed in — it has no role logic
 * at all. `/crew` used it, so the page was not admin-gated; it was merely absent
 * from the parent nav. Anyone signed in who typed the URL rendered it, and the
 * backend then handed them the full guardian roster.
 *
 * The backend gate (`te_is_club_admin` in `lib/club_standing.php`) is the control
 * that matters. This exists so the page does not render at all for a non-admin,
 * rather than rendering and filling with 403s.
 */
const ProtectedClubAdminRoute: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { user, isLoading } = useAuth();
  const { isClubAdmin } = useOrg();

  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="text-gray-500">Loading...</div>
      </div>
    );
  }

  if (!user) {
    return <Navigate to="/login" replace />;
  }

  if (!isClubAdmin) {
    return (
      <div className="max-w-lg mx-auto mt-12 p-6 bg-white rounded-lg shadow text-center">
        <h2 className="text-lg font-semibold text-brand-primary mb-2">Not available</h2>
        <p className="text-gray-600 text-sm">
          This page is only available to club administrators.
        </p>
      </div>
    );
  }

  return <>{children}</>;
};

export default ProtectedClubAdminRoute;
