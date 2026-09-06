import React, { useState, useEffect } from 'react';
import { useOrg } from '../contexts/OrgContext';
import InviteUsersForm from './InviteUsersForm';
import InvitationDashboard from './InvitationDashboard';
import CoachAccessControl from './CoachAccessControl';
import CoachSetPasswordModal from './CoachSetPasswordModal';
import { portalStatusMeta, portalStatusDetail } from '../utils/portalStatus';

interface ClubUser {
  id: number;
  user_id: number;
  email: string;
  first_name: string;
  last_name: string;
  role: string;
  active: boolean;
  granted_at: string;
  // Platform access, from lib/portal_status.php (api/club-users-gateway.php GET
  // carries it since 2026-09-06). Optional: an older backend sends none, and an
  // absent status renders as Unknown with no action rather than crashing.
  status?: string;
  first_login_at?: string | null;
  invited_at?: string | null;
  shared_account?: boolean;
  shared_reason?: string | null;
}

/**
 * Roles whose sign-in a club admin manages HERE. parent and player are crew —
 * the Crew page invites them with a `:parent_invite`, and api/coach-access.php
 * refuses them with a 422 that says so. Mirrors TE_STAFF_INVITE_ROLES.
 */
const STAFF_ROLES = ['club_admin', 'coach', 'treasurer', 'volunteer'];

const ClubUserManagement: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { activeContext } = useOrg();
  const clubId = activeContext?.scope_id;

  const [users, setUsers] = useState<ClubUser[]>([]);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState<'users' | 'invite' | 'invitations'>('users');
  const [editingUserId, setEditingUserId] = useState<number | null>(null);
  const [editingRole, setEditingRole] = useState<string>('');
  const [passwordUser, setPasswordUser] = useState<ClubUser | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);

  useEffect(() => {
    if (clubId) {
      fetchUsers();
    }
  }, [clubId]);

  const fetchUsers = async () => {
    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/api/club-users-gateway.php?clubId=${clubId}`, {
        headers: {
          'Authorization': `Bearer ${token}`
        }
      });
      const data = await response.json();
      if (data.success) {
        setUsers(data.users || []);
        setLoadError(null);
      } else if (response.status === 403) {
        // Club-admin data. A coach who reaches this tab gets told, not an
        // empty table that reads as "no users".
        setLoadError('Only club admins can see the club user list.');
      } else {
        setLoadError(data.error || 'Could not load users.');
      }
    } catch (error) {
      console.error('Error fetching users:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleUpdateRole = async (userId: number, newRole: string) => {
    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/api/club-users-gateway.php`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify({
          clubId,
          userId,
          role: newRole
        })
      });

      if (response.ok) {
        fetchUsers();
        setEditingUserId(null);
      } else {
        alert('Failed to update role');
      }
    } catch (error) {
      alert('Failed to update role');
    }
  };

  const handleRemoveUser = async (userId: number) => {
    if (!window.confirm('Are you sure you want to remove this user from the club?')) return;

    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/api/club-users-gateway.php?clubId=${clubId}&userId=${userId}`, {
        method: 'DELETE',
        headers: {
          'Authorization': `Bearer ${token}`
        }
      });

      if (response.ok) {
        fetchUsers();
      } else {
        alert('Failed to remove user');
      }
    } catch (error) {
      alert('Failed to remove user');
    }
  };

  const formatRole = (role: string) => {
    return role.replace('_', ' ').toUpperCase();
  };

  return (
    <div className="space-y-6">
      {/* Sub-tabs */}
      <div className="flex space-x-1 bg-gray-100 p-1 rounded-md">
        <button
          onClick={() => setActiveTab('users')}
          className={`flex-1 py-2 px-4 rounded-md text-sm font-semibold transition-all uppercase ${
            activeTab === 'users'
              ? 'bg-white text-brand-primary shadow-sm'
              : 'text-gray-700 hover:text-gray-900'
          }`}
        >
          Current Users
        </button>
        <button
          onClick={() => setActiveTab('invite')}
          className={`flex-1 py-2 px-4 rounded-md text-sm font-semibold transition-all uppercase ${
            activeTab === 'invite'
              ? 'bg-white text-brand-primary shadow-sm'
              : 'text-gray-700 hover:text-gray-900'
          }`}
        >
          Invite Users
        </button>
        <button
          onClick={() => setActiveTab('invitations')}
          className={`flex-1 py-2 px-4 rounded-md text-sm font-semibold transition-all uppercase ${
            activeTab === 'invitations'
              ? 'bg-white text-brand-primary shadow-sm'
              : 'text-gray-700 hover:text-gray-900'
          }`}
        >
          Pending Invitations
        </button>
      </div>

      {activeTab === 'users' && (
        <div className="bg-white rounded-md border border-brand-secondary overflow-hidden">
          {loading ? (
            <div className="text-center py-8 text-brand-primary">Loading users...</div>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-brand-secondary">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-brand-primary uppercase tracking-wider">
                      Name
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-brand-primary uppercase tracking-wider">
                      Email
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-brand-primary uppercase tracking-wider">
                      Role
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-brand-primary uppercase tracking-wider">
                      Status
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-brand-primary uppercase tracking-wider">
                      Actions
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-brand-primary uppercase tracking-wider">
                      Access
                    </th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-brand-secondary">
                  {users.map((user) => (
                    <tr key={user.id} className="hover:bg-gray-50">
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-brand-primary">
                        {user.first_name} {user.last_name}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                        {user.email}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        {editingUserId === user.user_id ? (
                          <select
                            value={editingRole}
                            onChange={(e) => setEditingRole(e.target.value)}
                            className="border border-brand-secondary rounded px-2 py-1 text-sm"
                          >
                            <option value="club_admin">Club Admin</option>
                            <option value="coach">Coach</option>
                          </select>
                        ) : (
                          <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-md bg-brand-secondary text-brand-primary uppercase">
                            {formatRole(user.role)}
                          </span>
                        )}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        {(() => {
                          const meta = portalStatusMeta(user.status);
                          const detail = portalStatusDetail(user);
                          return (
                            <>
                              <span
                                className={`px-2 py-0.5 rounded-full text-xs font-semibold ${meta.cls}`}
                                title={meta.help}
                              >
                                {meta.label}
                              </span>
                              {detail && (
                                <div className="text-[11px] text-gray-500 mt-0.5 tabular-nums">{detail}</div>
                              )}
                              {user.shared_account && (
                                <div className="text-[11px] text-amber-700 mt-0.5" title={user.shared_reason || ''}>
                                  &#9888; may be another account
                                </div>
                              )}
                            </>
                          );
                        })()}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm">
                        {editingUserId === user.user_id ? (
                          <div className="flex space-x-2">
                            <button
                              onClick={() => handleUpdateRole(user.user_id, editingRole)}
                              className="text-green-600 hover:text-green-700 font-semibold uppercase"
                            >
                              Save
                            </button>
                            <button
                              onClick={() => setEditingUserId(null)}
                              className="text-gray-600 hover:text-gray-700 font-semibold uppercase"
                            >
                              Cancel
                            </button>
                          </div>
                        ) : (
                          <div className="flex space-x-2">
                            <button
                              onClick={() => {
                                setEditingUserId(user.user_id);
                                setEditingRole(user.role);
                              }}
                              className="text-brand-primary hover:text-brand-primary-hover font-semibold uppercase"
                            >
                              Edit
                            </button>
                            <button
                              onClick={() => handleRemoveUser(user.user_id)}
                              className="text-red-600 hover:text-red-700 font-semibold uppercase"
                            >
                              Remove
                            </button>
                          </div>
                        )}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm">
                        {STAFF_ROLES.includes(user.role) ? (
                          <CoachAccessControl
                            coach={{
                              id: user.user_id,
                              first_name: user.first_name,
                              last_name: user.last_name,
                              email: user.email,
                              status: user.status,
                            }}
                            clubId={clubId ?? null}
                            onChanged={fetchUsers}
                            onSetPassword={() => setPasswordUser(user)}
                          />
                        ) : (
                          <span className="text-xs text-gray-500" title="Crew are invited from the Crew page.">
                            Managed on Crew
                          </span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {loadError && (
                <div className="text-center py-8 text-red-700" role="alert">
                  {loadError}
                </div>
              )}
              {!loadError && users.length === 0 && (
                <div className="text-center py-8 text-gray-600">
                  No users found
                </div>
              )}
            </div>
          )}
        </div>
      )}

      {passwordUser && (
        <CoachSetPasswordModal
          coach={{
            id: passwordUser.user_id,
            first_name: passwordUser.first_name,
            last_name: passwordUser.last_name,
            email: passwordUser.email,
          }}
          clubId={clubId ?? 0}
          onClose={() => setPasswordUser(null)}
          onSaved={fetchUsers}
        />
      )}

      {activeTab === 'invite' && (
        <InviteUsersForm clubId={clubId} onSuccess={fetchUsers} />
      )}

      {activeTab === 'invitations' && (
        <InvitationDashboard clubId={clubId} />
      )}
    </div>
  );
};

export default ClubUserManagement;
