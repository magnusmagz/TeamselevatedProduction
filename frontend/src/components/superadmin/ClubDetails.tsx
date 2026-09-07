import React, { useState } from 'react';
import DataTable, { DataTableColumn } from '../ui/DataTable';
import Button from '../ui/Button';

interface Team {
  id: number;
  name: string;
  age_group: string;
  gender: string;
  athlete_count: number;
  coach_name: string | null;
}

interface ClubUser {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  system_role: string;
  club_role: string;
  granted_at: string;
}

interface Club {
  id: number;
  name: string;
  city?: string;
  state?: string;
  website?: string;
  primary_color?: string;
  created_at: string;
}

interface ClubDetailsProps {
  club: Club | null;
  teams: Team[];
  users: ClubUser[];
  loading: boolean;
  onClose: () => void;
  onChangeRole: (userId: number, clubId: number, newRole: string) => void;
  onRemoveUser: (userId: number, clubId: number) => void;
  onUpdateClub?: (data: { id: number; name?: string; city?: string; state?: string; website?: string; primary_color?: string }) => Promise<boolean>;
  onDeleteClub?: (clubId: number) => Promise<boolean>;
  onAssignUser?: (userId: number, clubId: number, role: string) => Promise<boolean>;
  allUsers?: { id: number; first_name: string; last_name: string; email: string }[];
}

const ClubDetails: React.FC<ClubDetailsProps> = ({
  club,
  teams,
  users,
  loading,
  onClose,
  onChangeRole,
  onRemoveUser,
  onUpdateClub,
  onDeleteClub,
  onAssignUser,
  allUsers,
}) => {
  const [editingUserId, setEditingUserId] = useState<number | null>(null);
  const [selectedRole, setSelectedRole] = useState<string>('');
  const [isEditingClub, setIsEditingClub] = useState(false);
  const [editClubForm, setEditClubForm] = useState({ name: '', city: '', state: '', website: '', primary_color: '' });
  const [saving, setSaving] = useState(false);
  const [assignSearch, setAssignSearch] = useState('');
  const [assignRole, setAssignRole] = useState('coach');
  const [assignUserId, setAssignUserId] = useState<number | null>(null);
  const [assigning, setAssigning] = useState(false);

  const handleRoleChange = (userId: number) => {
    if (club && selectedRole) {
      onChangeRole(userId, club.id, selectedRole);
      setEditingUserId(null);
      setSelectedRole('');
    }
  };

  const startEditing = (user: ClubUser) => {
    setEditingUserId(user.id);
    setSelectedRole(user.club_role);
  };

  const startEditingClub = () => {
    if (club) {
      setEditClubForm({
        name: club.name || '',
        city: club.city || '',
        state: club.state || '',
        website: club.website || '',
        primary_color: club.primary_color || '#12443e',
      });
      setIsEditingClub(true);
    }
  };

  const handleSaveClub = async () => {
    if (!onUpdateClub || !club) return;
    setSaving(true);
    try {
      const success = await onUpdateClub({ id: club.id, ...editClubForm });
      if (success) setIsEditingClub(false);
    } finally {
      setSaving(false);
    }
  };

  const handleDeleteClub = async () => {
    if (!onDeleteClub || !club) return;
    if (!window.confirm(`Are you sure you want to delete "${club.name}"? This action cannot be undone.`)) return;
    await onDeleteClub(club.id);
  };

  const filteredAssignUsers = allUsers
    ? allUsers.filter((u) => {
        if (!assignSearch.trim()) return false;
        const term = assignSearch.toLowerCase();
        const alreadyAssigned = users.some((cu) => cu.id === u.id);
        if (alreadyAssigned) return false;
        return (
          u.first_name.toLowerCase().includes(term) ||
          u.last_name.toLowerCase().includes(term) ||
          u.email.toLowerCase().includes(term)
        );
      }).slice(0, 10)
    : [];

  const handleAssignUser = async () => {
    if (!onAssignUser || !club || !assignUserId) return;
    setAssigning(true);
    try {
      const success = await onAssignUser(assignUserId, club.id, assignRole);
      if (success) {
        setAssignSearch('');
        setAssignUserId(null);
        setAssignRole('coach');
      }
    } finally {
      setAssigning(false);
    }
  };

  const teamColumns: DataTableColumn<Team>[] = [
    {
      key: 'name',
      header: 'Team Name',
      render: (team) => <span className="font-medium">{team.name}</span>,
    },
    {
      key: 'age_gender',
      header: 'Age/Gender',
      render: (team) => (
        <span className="text-gray-600">
          {team.age_group} {team.gender}
        </span>
      ),
    },
    {
      key: 'coach',
      header: 'Coach',
      render: (team) => <span className="text-gray-600">{team.coach_name || '-'}</span>,
    },
    { key: 'athlete_count', header: 'Athletes', align: 'center' },
  ];

  const userColumns: DataTableColumn<ClubUser>[] = [
    {
      key: 'name',
      header: 'Name',
      render: (user) => (
        <span className="font-medium">
          {user.first_name} {user.last_name}
          {user.system_role === 'super_admin' && (
            <span className="ml-2 text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded">
              Super Admin
            </span>
          )}
        </span>
      ),
    },
    {
      key: 'email',
      header: 'Email',
      render: (user) => <span className="text-gray-600">{user.email}</span>,
    },
    {
      key: 'role',
      header: 'Role',
      render: (user) =>
        editingUserId === user.id ? (
          <select
            value={selectedRole}
            onChange={(e) => setSelectedRole(e.target.value)}
            className="border border-gray-300 rounded px-2 py-1 text-sm"
          >
            <option value="club_admin">Club Admin</option>
            <option value="coach">Coach</option>
            <option value="parent">Parent</option>
            <option value="player">Player</option>
          </select>
        ) : (
          <span className={`px-2 py-1 rounded text-sm ${
            user.club_role === 'club_admin'
              ? 'bg-blue-100 text-blue-700'
              : user.club_role === 'coach'
              ? 'bg-green-100 text-green-700'
              : 'bg-gray-100 text-gray-700'
          }`}>
            {user.club_role}
          </span>
        ),
    },
    {
      key: 'actions',
      header: 'Actions',
      align: 'center',
      render: (user) =>
        editingUserId === user.id ? (
          <div className="flex justify-center gap-2">
            <Button variant="link" size="sm" onClick={() => handleRoleChange(user.id)}>
              Save
            </Button>
            <Button variant="ghost" size="sm" onClick={() => setEditingUserId(null)}>
              Cancel
            </Button>
          </div>
        ) : (
          <div className="flex justify-center gap-2">
            <Button variant="link" size="sm" onClick={() => startEditing(user)}>
              Edit
            </Button>
            <Button
              variant="danger-link"
              size="sm"
              onClick={() => {
                if (window.confirm(`Remove ${user.first_name} ${user.last_name} from this club?`)) {
                  onRemoveUser(user.id, club!.id);
                }
              }}
            >
              Remove
            </Button>
          </div>
        ),
    },
  ];

  if (!club && !loading) return null;

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-lg max-w-4xl w-full max-h-[90vh] overflow-hidden flex flex-col">
        {/* Header */}
        <div className="bg-brand-primary text-white px-6 py-4 flex justify-between items-center">
          <div className="flex items-center gap-3">
            <h2 className="text-xl font-bold uppercase">
              {loading ? 'Loading...' : club?.name}
            </h2>
            {club && !isEditingClub && (
              <button
                onClick={startEditingClub}
                className="text-white hover:text-gray-200 text-sm underline"
              >
                Edit
              </button>
            )}
          </div>
          <div className="flex items-center gap-3">
            {club && onDeleteClub && (
              <Button variant="danger" onClick={handleDeleteClub}>
                Delete Club
              </Button>
            )}
            <button
              onClick={onClose}
              className="text-white hover:text-gray-200"
            >
              <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
        </div>

        {/* Content */}
        <div className="flex-1 overflow-y-auto p-6">
          {loading ? (
            <div className="text-center py-8 text-gray-500">Loading club details...</div>
          ) : (
            <>
              {/* Edit Club Form */}
              {isEditingClub && (
                <div className="mb-6 bg-gray-50 border border-brand-secondary rounded-lg p-4">
                  <h3 className="text-lg font-semibold text-brand-primary uppercase mb-4">Edit Club</h3>
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                      <label className="block text-sm font-medium text-brand-primary mb-1">Name</label>
                      <input
                        type="text"
                        value={editClubForm.name}
                        onChange={(e) => setEditClubForm({ ...editClubForm, name: e.target.value })}
                        className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary w-full"
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-brand-primary mb-1">City</label>
                      <input
                        type="text"
                        value={editClubForm.city}
                        onChange={(e) => setEditClubForm({ ...editClubForm, city: e.target.value })}
                        className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary w-full"
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-brand-primary mb-1">State</label>
                      <input
                        type="text"
                        value={editClubForm.state}
                        onChange={(e) => setEditClubForm({ ...editClubForm, state: e.target.value })}
                        className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary w-full"
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-brand-primary mb-1">Website</label>
                      <input
                        type="text"
                        value={editClubForm.website}
                        onChange={(e) => setEditClubForm({ ...editClubForm, website: e.target.value })}
                        className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary w-full"
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-brand-primary mb-1">Primary Color</label>
                      <div className="flex gap-2 items-center">
                        <input
                          type="color"
                          value={editClubForm.primary_color}
                          onChange={(e) => setEditClubForm({ ...editClubForm, primary_color: e.target.value })}
                          className="h-10 w-12 border border-brand-secondary rounded cursor-pointer"
                        />
                        <input
                          type="text"
                          value={editClubForm.primary_color}
                          onChange={(e) => setEditClubForm({ ...editClubForm, primary_color: e.target.value })}
                          className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary flex-1"
                        />
                      </div>
                    </div>
                  </div>
                  <div className="mt-4 flex gap-2">
                    <Button onClick={handleSaveClub} loading={saving}>
                      Save Changes
                    </Button>
                    <Button variant="secondary" onClick={() => setIsEditingClub(false)}>
                      Cancel
                    </Button>
                  </div>
                </div>
              )}

              {/* Teams Section */}
              <div className="mb-8">
                <h3 className="text-lg font-semibold text-brand-primary uppercase mb-4">
                  Teams ({teams.length})
                </h3>
                <DataTable<Team>
                  columns={teamColumns}
                  rows={teams}
                  rowKey={(team) => team.id}
                  emptyState="No teams in this club"
                />
              </div>

              {/* Assign User Section */}
              {onAssignUser && (
                <div className="mb-8">
                  <h3 className="text-lg font-semibold text-brand-primary uppercase mb-4">
                    Assign User to Club
                  </h3>
                  <div className="bg-gray-50 border border-brand-secondary rounded-lg p-4">
                    <div className="flex flex-col md:flex-row gap-3">
                      <div className="flex-1 relative">
                        <input
                          type="text"
                          placeholder="Search user by name or email..."
                          value={assignSearch}
                          onChange={(e) => { setAssignSearch(e.target.value); setAssignUserId(null); }}
                          className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary w-full"
                        />
                        {filteredAssignUsers.length > 0 && !assignUserId && (
                          <div className="absolute z-10 top-full left-0 right-0 bg-white border border-brand-secondary rounded-md mt-1 max-h-48 overflow-y-auto shadow-lg">
                            {filteredAssignUsers.map((u) => (
                              <button
                                key={u.id}
                                onClick={() => { setAssignUserId(u.id); setAssignSearch(`${u.first_name} ${u.last_name} (${u.email})`); }}
                                className="block w-full text-left px-3 py-2 text-sm hover:bg-gray-100 text-brand-primary"
                              >
                                {u.first_name} {u.last_name} — {u.email}
                              </button>
                            ))}
                          </div>
                        )}
                      </div>
                      <select
                        value={assignRole}
                        onChange={(e) => setAssignRole(e.target.value)}
                        className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary"
                      >
                        <option value="club_admin">Club Admin</option>
                        <option value="coach">Coach</option>
                        <option value="parent">Parent</option>
                      </select>
                      <Button onClick={handleAssignUser} disabled={!assignUserId} loading={assigning}>
                        Assign
                      </Button>
                    </div>
                  </div>
                </div>
              )}

              {/* Users Section */}
              <div>
                <h3 className="text-lg font-semibold text-brand-primary uppercase mb-4">
                  Users ({users.length})
                </h3>
                <DataTable<ClubUser>
                  columns={userColumns}
                  rows={users}
                  rowKey={(user) => user.id}
                  emptyState="No users in this club"
                />
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
};

export default ClubDetails;
