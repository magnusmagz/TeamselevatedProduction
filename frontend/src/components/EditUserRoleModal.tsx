import React, { useState } from 'react';

interface UserRole {
  role: string;
  access_type: 'league' | 'club';
  scope_id: number;
  scope_name: string;
  granted_at: string;
  active: boolean;
}

interface User {
  id: number;
  email: string;
  first_name: string;
  last_name: string;
  name: string;
  system_role: string;
  roles: UserRole[];
}

interface EditUserRoleModalProps {
  leagueId: number;
  user: User;
  currentRole: UserRole;
  onClose: () => void;
  onSuccess: () => void;
}

const EditUserRoleModal: React.FC<EditUserRoleModalProps> = ({
  leagueId,
  user,
  currentRole,
  onClose,
  onSuccess
}) => {
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';
  const [newRole, setNewRole] = useState<string>(currentRole.role);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (newRole === currentRole.role) {
      setError('Please select a different role');
      return;
    }

    try {
      setLoading(true);
      setError(null);

      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/api/league-users-gateway.php?action=update`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify({
          leagueId,
          userId: user.id,
          oldRole: currentRole.role,
          role: newRole,
          accessType: currentRole.access_type,
          scopeId: currentRole.scope_id
        })
      });

      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.error || 'Failed to update role');
      }

      onSuccess();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update role');
    } finally {
      setLoading(false);
    }
  };

  const formatRole = (role: string) => {
    return role.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase());
  };

  // Available roles based on access type
  const availableRoles = currentRole.access_type === 'league'
    ? ['league_admin', 'coach']
    : ['club_admin', 'coach'];

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
      <div className="bg-white rounded-lg shadow-xl max-w-md w-full">
        {/* Header */}
        <div className="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
          <h2 className="text-xl font-semibold text-forest-800">Edit User Role</h2>
          <button
            onClick={onClose}
            className="text-gray-400 hover:text-gray-600"
          >
            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        {/* Body */}
        <form onSubmit={handleSubmit} className="px-6 py-4 space-y-4">
          {error && (
            <div className="bg-red-50 border-l-4 border-red-400 p-3">
              <p className="text-sm text-red-700">{error}</p>
            </div>
          )}

          {/* User Info */}
          <div className="bg-gray-50 rounded-md p-3">
            <div className="text-sm font-medium text-gray-900">{user.name}</div>
            <div className="text-xs text-gray-500">{user.email}</div>
            <div className="text-xs text-gray-600 mt-1">
              {currentRole.access_type === 'club' && (
                <span className="font-medium">{currentRole.scope_name}</span>
              )}
              {currentRole.access_type === 'league' && (
                <span className="font-medium">League Level Access</span>
              )}
            </div>
          </div>

          {/* Current Role */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Current Role
            </label>
            <div className="px-3 py-2 bg-gray-100 border border-gray-300 rounded-md text-sm text-gray-700">
              {formatRole(currentRole.role)}
            </div>
          </div>

          {/* New Role Selection */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              New Role *
            </label>
            <select
              value={newRole}
              onChange={(e) => setNewRole(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-forest-500"
            >
              {availableRoles.map((role) => (
                <option key={role} value={role}>
                  {formatRole(role)}
                </option>
              ))}
            </select>
            <p className="mt-1 text-xs text-gray-500">
              {newRole === 'league_admin' && 'Can manage all clubs and users in the league'}
              {newRole === 'club_admin' && 'Can manage this specific club'}
              {newRole === 'coach' && 'Can manage assigned teams'}
            </p>
          </div>

          {/* Actions */}
          <div className="flex justify-end space-x-3 pt-4 border-t">
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2 text-gray-700 border border-gray-300 rounded-md hover:bg-gray-50 uppercase font-medium text-sm"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={loading || newRole === currentRole.role}
              className="px-4 py-2 bg-forest-600 text-white rounded-md hover:bg-forest-700 disabled:opacity-50 disabled:cursor-not-allowed uppercase font-medium text-sm"
            >
              {loading ? 'Updating...' : 'Update Role'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};

export default EditUserRoleModal;
