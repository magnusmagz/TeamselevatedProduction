import React, { useState } from 'react';
import DataTable, { DataTableColumn } from '../ui/DataTable';
import Button from '../ui/Button';

interface ClubRole {
  access_id: number;
  role: string;
  granted_at: string;
  club_id: number;
  club_name: string;
}

interface User {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  system_role: string;
  created_at: string;
  last_login_at: string | null;
}

interface ClubOption {
  id: number;
  name: string;
}

interface UserDetailsProps {
  user: User | null;
  clubRoles: ClubRole[];
  loading: boolean;
  onClose: () => void;
  onToggleSuperAdmin: (userId: number, makeSuperAdmin: boolean) => void;
  onRemoveFromClub: (userId: number, clubId: number) => void;
  onUpdateUser?: (data: { id: number; first_name?: string; last_name?: string; email?: string; phone?: string }) => Promise<boolean>;
  onAssignToClub?: (userId: number, clubId: number, role: string) => Promise<boolean>;
  allClubs?: ClubOption[];
}

const UserDetails: React.FC<UserDetailsProps> = ({
  user,
  clubRoles,
  loading,
  onClose,
  onToggleSuperAdmin,
  onRemoveFromClub,
  onUpdateUser,
  onAssignToClub,
  allClubs,
}) => {
  const [isEditing, setIsEditing] = useState(false);
  const [editForm, setEditForm] = useState({ first_name: '', last_name: '', email: '', phone: '' });
  const [saving, setSaving] = useState(false);
  const [assignClubId, setAssignClubId] = useState<number | ''>('');
  const [assignRole, setAssignRole] = useState('coach');
  const [assigning, setAssigning] = useState(false);

  const startEditing = () => {
    if (user) {
      setEditForm({
        first_name: user.first_name || '',
        last_name: user.last_name || '',
        email: user.email || '',
        phone: '',
      });
      setIsEditing(true);
    }
  };

  const handleSaveUser = async () => {
    if (!onUpdateUser || !user) return;
    setSaving(true);
    try {
      const success = await onUpdateUser({ id: user.id, ...editForm });
      if (success) setIsEditing(false);
    } finally {
      setSaving(false);
    }
  };

  const handleAssignToClub = async () => {
    if (!onAssignToClub || !user || !assignClubId) return;
    setAssigning(true);
    try {
      const success = await onAssignToClub(user.id, assignClubId as number, assignRole);
      if (success) {
        setAssignClubId('');
        setAssignRole('coach');
      }
    } finally {
      setAssigning(false);
    }
  };

  // Filter out clubs the user is already assigned to
  const availableClubs = allClubs
    ? allClubs.filter((c) => !clubRoles.some((cr) => cr.club_id === c.id))
    : [];

  if (!user && !loading) return null;

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-lg max-w-2xl w-full max-h-[90vh] overflow-hidden flex flex-col">
        {/* Header */}
        <div className="bg-brand-primary text-white px-6 py-4 flex justify-between items-center">
          <div className="flex items-center gap-3">
            <h2 className="text-xl font-bold uppercase">
              {loading ? 'Loading...' : `${user?.first_name} ${user?.last_name}`}
            </h2>
            {user && !isEditing && onUpdateUser && (
              <button
                onClick={startEditing}
                className="text-white hover:text-gray-200 text-sm underline"
              >
                Edit
              </button>
            )}
          </div>
          <button
            onClick={onClose}
            className="text-white hover:text-gray-200"
          >
            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        {/* Content */}
        <div className="flex-1 overflow-y-auto p-6">
          {loading ? (
            <div className="text-center py-8 text-gray-500">Loading user details...</div>
          ) : user && (
            <>
              {/* Edit User Form */}
              {isEditing && (
                <div className="mb-6 bg-gray-50 border border-brand-secondary rounded-lg p-4">
                  <h3 className="text-lg font-semibold text-brand-primary uppercase mb-4">Edit User</h3>
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                      <label className="block text-sm font-medium text-brand-primary mb-1">First Name</label>
                      <input
                        type="text"
                        value={editForm.first_name}
                        onChange={(e) => setEditForm({ ...editForm, first_name: e.target.value })}
                        className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary w-full"
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-brand-primary mb-1">Last Name</label>
                      <input
                        type="text"
                        value={editForm.last_name}
                        onChange={(e) => setEditForm({ ...editForm, last_name: e.target.value })}
                        className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary w-full"
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-brand-primary mb-1">Email</label>
                      <input
                        type="email"
                        value={editForm.email}
                        onChange={(e) => setEditForm({ ...editForm, email: e.target.value })}
                        className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary w-full"
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-brand-primary mb-1">Phone</label>
                      <input
                        type="text"
                        value={editForm.phone}
                        onChange={(e) => setEditForm({ ...editForm, phone: e.target.value })}
                        className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary w-full"
                        placeholder="Phone number"
                      />
                    </div>
                  </div>
                  <div className="mt-4 flex gap-2">
                    <Button onClick={handleSaveUser} loading={saving}>
                      Save Changes
                    </Button>
                    <Button variant="secondary" onClick={() => setIsEditing(false)}>
                      Cancel
                    </Button>
                  </div>
                </div>
              )}

              {/* User Info */}
              <div className="mb-6 bg-gray-50 rounded-lg p-4">
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <div className="text-sm text-gray-500">Email</div>
                    <div className="font-medium">{user.email}</div>
                  </div>
                  <div>
                    <div className="text-sm text-gray-500">System Role</div>
                    <div className="flex items-center gap-2">
                      {user.system_role === 'super_admin' ? (
                        <>
                          <span className="px-2 py-1 bg-red-100 text-red-700 rounded text-sm">
                            Super Admin
                          </span>
                          <Button
                            variant="danger-link"
                            size="sm"
                            onClick={() => {
                              if (window.confirm('Remove super admin access?')) {
                                onToggleSuperAdmin(user.id, false);
                              }
                            }}
                          >
                            Revoke
                          </Button>
                        </>
                      ) : (
                        <>
                          <span className="px-2 py-1 bg-gray-100 text-gray-600 rounded text-sm">
                            User
                          </span>
                          <Button
                            variant="link"
                            size="sm"
                            onClick={() => {
                              if (window.confirm('Grant super admin access?')) {
                                onToggleSuperAdmin(user.id, true);
                              }
                            }}
                          >
                            Make Super Admin
                          </Button>
                        </>
                      )}
                    </div>
                  </div>
                  <div>
                    <div className="text-sm text-gray-500">Created</div>
                    <div className="font-medium">
                      {new Date(user.created_at).toLocaleDateString()}
                    </div>
                  </div>
                  <div>
                    <div className="text-sm text-gray-500">Last Login</div>
                    <div className="font-medium">
                      {user.last_login_at
                        ? new Date(user.last_login_at).toLocaleDateString()
                        : 'Never'}
                    </div>
                  </div>
                </div>
              </div>

              {/* Assign to Club */}
              {onAssignToClub && availableClubs.length > 0 && (
                <div className="mb-6">
                  <h3 className="text-lg font-semibold text-brand-primary uppercase mb-4">
                    Assign to Club
                  </h3>
                  <div className="bg-gray-50 border border-brand-secondary rounded-lg p-4">
                    <div className="flex flex-col md:flex-row gap-3">
                      <select
                        value={assignClubId}
                        onChange={(e) => setAssignClubId(e.target.value ? Number(e.target.value) : '')}
                        className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary flex-1"
                      >
                        <option value="">Select a club...</option>
                        {availableClubs.map((c) => (
                          <option key={c.id} value={c.id}>{c.name}</option>
                        ))}
                      </select>
                      <select
                        value={assignRole}
                        onChange={(e) => setAssignRole(e.target.value)}
                        className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary"
                      >
                        <option value="club_admin">Club Admin</option>
                        <option value="coach">Coach</option>
                        <option value="parent">Parent</option>
                      </select>
                      <Button onClick={handleAssignToClub} disabled={!assignClubId} loading={assigning}>
                        Assign
                      </Button>
                    </div>
                  </div>
                </div>
              )}

              {/* Club Memberships */}
              <div>
                <h3 className="text-lg font-semibold text-brand-primary uppercase mb-4">
                  Club Memberships ({clubRoles.length})
                </h3>
                <DataTable<ClubRole>
                  columns={[
                    {
                      key: 'club_name',
                      header: 'Club',
                      render: (cr) => <span className="font-medium">{cr.club_name}</span>,
                    },
                    {
                      key: 'role',
                      header: 'Role',
                      render: (cr) => (
                        <span className={`px-2 py-1 rounded text-sm ${
                          cr.role === 'club_admin'
                            ? 'bg-blue-100 text-blue-700'
                            : cr.role === 'coach'
                            ? 'bg-green-100 text-green-700'
                            : 'bg-gray-100 text-gray-700'
                        }`}>
                          {cr.role}
                        </span>
                      ),
                    },
                    {
                      key: 'since',
                      header: 'Since',
                      render: (cr) => (
                        <span className="text-gray-600">{new Date(cr.granted_at).toLocaleDateString()}</span>
                      ),
                    },
                    {
                      key: 'actions',
                      header: 'Actions',
                      align: 'center',
                      render: (cr) => (
                        <Button
                          variant="danger-link"
                          size="sm"
                          onClick={() => {
                            if (window.confirm(`Remove user from ${cr.club_name}?`)) {
                              onRemoveFromClub(user.id, cr.club_id);
                            }
                          }}
                        >
                          Remove
                        </Button>
                      ),
                    },
                  ] as DataTableColumn<ClubRole>[]}
                  rows={clubRoles}
                  rowKey={(cr) => cr.access_id}
                  emptyState="User is not a member of any clubs"
                />
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
};

export default UserDetails;
