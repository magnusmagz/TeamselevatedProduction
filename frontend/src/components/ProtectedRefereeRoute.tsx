import React from 'react';
import { Navigate } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';
import { hasRefereeRole } from '../utils/landingRoute';

/**
 * Route guard for /referee (Referees, 2026-09-08).
 *
 * `ProtectedRoute` is authentication only. This one also asks for the
 * `referee` role on the token — anyone else goes to the staff dashboard, where
 * `ParentRedirect` sorts parents out. The backend is the real control:
 * `api/referees.php?action=my-games` keys on referees.user_id and answers an
 * empty list to an account no club has connected yet.
 */
const ProtectedRefereeRoute: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { user, isLoading } = useAuth();

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

  if (user.system_role !== 'super_admin' && !hasRefereeRole(user)) {
    return <Navigate to="/dashboard" replace />;
  }

  return <>{children}</>;
};

export default ProtectedRefereeRoute;
