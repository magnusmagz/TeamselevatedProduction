import React, { useState, useEffect } from 'react';
import AddUserModal from './AddUserModal';
import EditUserRoleModal from './EditUserRoleModal';

interface LeagueUserManagementProps {
  leagueId: number;
}

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

const LeagueUserManagement: React.FC<LeagueUserManagementProps> = ({ leagueId }) => {
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';
  const [users, setUsers] = useState<User[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showAddModal, setShowAddModal] = useState(false);
  const [editingUser, setEditingUser] = useState<{ user: User; role: UserRole } | null>(null);

  useEffect(() => {
    fetchUsers();
  }, [leagueId]);

  const fetchUsers = async () => {
    try {
      setLoading(true);
      setError(null);

      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/api/league-users-gateway.php?leagueId=${leagueId}`, {
        headers: {
          'Authorization': `Bearer ${token}`
        }
      });

      if (!response.ok) {
        throw new Error('Failed to fetch users');
      }

      const data = await response.json();
      setUsers(data.users || []);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load users');
    } finally {
      setLoading(false);
    }
  };

  const handleRemoveUserRole = async (user: User, role: UserRole) => {
    if (!window.confirm(`Remove ${role.role} access for ${user.name}?`)) {
      return;
    }

    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/api/league-users-gateway.php?action=remove`, {
        method: 'DELETE',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify({
          leagueId,
          userId: user.id,
          role: role.role,
          accessType: role.access_type,
          scopeId: role.scope_id
        })
      });

      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.error || 'Failed to remove user');
      }

      await fetchUsers();
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Failed to remove user');
    }
  };

  const getRoleBadgeColor = (role: string) => {
    switch (role) {
      case 'league_admin':
        return 'bg-purple-100 text-purple-800';
      case 'club_admin':
        return 'bg-blue-100 text-blue-800';
      case 'coach':
        return 'bg-green-100 text-green-800';
      default:
        return 'bg-gray-100 text-gray-800';
    }
  };

  const formatRole = (role: string) => {
    return role.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase());
  };

  if (loading) {
    return (
      <div className="flex justify-center items-center py-12">
        <div className="text-brand-primary">Loading users...</div>
      </div>
    );
  }

  return (
    <div className="bg-white shadow-sm rounded-lg">
      {error && (
        <div className="bg-red-50 border-l-4 border-red-400 p-4 mb-4">
          <p className="text-red-700">{error}</p>
        </div>
      )}

      {/* Header with Create User button */}
      <div className="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
        <div>
          <h2 className="text-lg font-semibold text-brand-primary">Users & Permissions</h2>
          <p className="text-sm text-gray-600 mt-1">
            Manage user access and roles for this league
          </p>
        </div>
        <button
          onClick={() => setShowAddModal(true)}
          className="px-4 py-2 bg-brand-primary text-white rounded-md hover:bg-brand-primary uppercase font-medium text-sm"
        >
          Create User
        </button>
      </div>

      {/* Users Table */}
      <div className="overflow-x-auto">
        <table className="min-w-full divide-y divide-gray-200">
          <thead className="bg-gray-50">
            <tr>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                User
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Email
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Roles
              </th>
              <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                Actions
              </th>
            </tr>
          </thead>
          <tbody className="bg-white divide-y divide-gray-200">
            {users.length === 0 ? (
              <tr>
                <td colSpan={4} className="px-6 py-8 text-center text-gray-500">
                  No users found. Add users to get started.
                </td>
              </tr>
            ) : (
              users.map((user) => (
                <tr key={user.id} className="hover:bg-gray-50">
                  <td className="px-6 py-4 whitespace-nowrap">
                    <div className="text-sm font-medium text-gray-900">{user.name}</div>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap">
                    <div className="text-sm text-gray-500">{user.email}</div>
                  </td>
                  <td className="px-6 py-4">
                    <div className="flex flex-wrap gap-2">
                      {user.roles.map((role, idx) => (
                        <div key={idx} className="flex items-center gap-2">
                          <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${getRoleBadgeColor(role.role)}`}>
                            {formatRole(role.role)}
                            {role.access_type === 'club' && (
                              <span className="ml-1 text-xs opacity-75">
                                ({role.scope_name})
                              </span>
                            )}
                          </span>
                          <button
                            onClick={() => setEditingUser({ user, role })}
                            className="text-blue-600 hover:text-blue-800"
                            title="Edit role"
                          >
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                          </button>
                          <button
                            onClick={() => handleRemoveUserRole(user, role)}
                            className="text-red-600 hover:text-red-800"
                            title="Remove role"
                          >
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                            </svg>
                          </button>
                        </div>
                      ))}
                    </div>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                    <button
                      onClick={() => setEditingUser({ user, role: user.roles[0] })}
                      className="text-brand-primary hover:text-brand-primary-hover uppercase font-medium text-xs"
                    >
                      Manage
                    </button>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {/* Modals */}
      {showAddModal && (
        <AddUserModal
          leagueId={leagueId}
          onClose={() => setShowAddModal(false)}
          onSuccess={() => {
            setShowAddModal(false);
            fetchUsers();
          }}
        />
      )}

      {editingUser && (
        <EditUserRoleModal
          leagueId={leagueId}
          user={editingUser.user}
          currentRole={editingUser.role}
          onClose={() => setEditingUser(null)}
          onSuccess={() => {
            setEditingUser(null);
            fetchUsers();
          }}
        />
      )}
    </div>
  );
};

export default LeagueUserManagement;
