import React, { useState } from 'react';
import DataTable, { DataTableColumn } from '../ui/DataTable';

interface User {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  system_role: string;
  created_at: string;
  last_login_at: string | null;
  club_count: number;
}

interface UsersListProps {
  users: User[];
  loading: boolean;
  onViewDetails: (userId: number) => void;
  onToggleSuperAdmin: (userId: number, makeSuperAdmin: boolean) => void;
  onSearch: (search: string) => void;
  onCreateUser?: (data: { first_name: string; last_name: string; email: string; password: string; system_role: string }) => Promise<boolean>;
  onImpersonate?: (user: User) => void;
}

const UsersList: React.FC<UsersListProps> = ({
  users,
  loading,
  onViewDetails,
  onToggleSuperAdmin,
  onSearch,
  onCreateUser,
  onImpersonate,
}) => {
  const [searchTerm, setSearchTerm] = useState('');
  const [showCreateForm, setShowCreateForm] = useState(false);
  const [createForm, setCreateForm] = useState({ first_name: '', last_name: '', email: '', password: '', system_role: 'user' });
  const [creating, setCreating] = useState(false);

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    onSearch(searchTerm);
  };

  const handleCreateUser = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!onCreateUser || !createForm.first_name.trim() || !createForm.last_name.trim() || !createForm.email.trim()) return;
    setCreating(true);
    try {
      const success = await onCreateUser(createForm);
      if (success) {
        setCreateForm({ first_name: '', last_name: '', email: '', password: '', system_role: 'user' });
        setShowCreateForm(false);
      }
    } finally {
      setCreating(false);
    }
  };

  const columns: DataTableColumn<User>[] = [
    {
      key: 'name',
      header: 'Name',
      render: (user) => (
        <span className="font-medium text-brand-primary">
          {user.first_name} {user.last_name}
        </span>
      ),
    },
    {
      key: 'email',
      header: 'Email',
      render: (user) => <span className="text-gray-600">{user.email}</span>,
    },
    {
      key: 'system_role',
      header: 'System Role',
      align: 'center',
      render: (user) =>
        user.system_role === 'super_admin' ? (
          <span className="px-2 py-1 bg-red-100 text-red-700 rounded text-sm">
            Super Admin
          </span>
        ) : (
          <span className="px-2 py-1 bg-gray-100 text-gray-600 rounded text-sm">
            User
          </span>
        ),
    },
    { key: 'club_count', header: 'Clubs', align: 'center' },
    {
      key: 'last_login',
      header: 'Last Login',
      render: (user) => (
        <span className="text-gray-600">
          {user.last_login_at
            ? new Date(user.last_login_at).toLocaleDateString()
            : 'Never'}
        </span>
      ),
    },
    {
      key: 'actions',
      header: 'Actions',
      align: 'center',
      render: (user) => (
        <div className="flex justify-center gap-2">
          <button
            onClick={() => onViewDetails(user.id)}
            className="text-brand-primary hover:underline text-sm"
          >
            Details
          </button>
          {/* Super admins are deliberately not impersonable — the
              server refuses it, so offering the button would only
              produce an error. */}
          {onImpersonate && user.system_role !== 'super_admin' && (
            <button
              onClick={() => onImpersonate(user)}
              className="text-amber-700 hover:underline text-sm"
            >
              View As
            </button>
          )}
          {user.system_role === 'super_admin' ? (
            <button
              onClick={() => {
                if (window.confirm(`Remove super admin access from ${user.first_name} ${user.last_name}?`)) {
                  onToggleSuperAdmin(user.id, false);
                }
              }}
              className="text-red-600 hover:underline text-sm"
            >
              Revoke Admin
            </button>
          ) : (
            <button
              onClick={() => {
                if (window.confirm(`Grant super admin access to ${user.first_name} ${user.last_name}?`)) {
                  onToggleSuperAdmin(user.id, true);
                }
              }}
              className="text-green-600 hover:underline text-sm"
            >
              Make Admin
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div>
      {/* Create User Button & Form */}
      <div className="mb-4">
        <button
          onClick={() => setShowCreateForm(!showCreateForm)}
          className="bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-3 hover:bg-brand-primary uppercase font-semibold text-sm"
        >
          {showCreateForm ? 'Cancel' : 'Create User'}
        </button>

        {showCreateForm && (
          <form onSubmit={handleCreateUser} className="mt-4 bg-gray-50 border border-brand-secondary rounded-lg p-4">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-brand-primary mb-1">First Name *</label>
                <input
                  type="text"
                  required
                  value={createForm.first_name}
                  onChange={(e) => setCreateForm({ ...createForm, first_name: e.target.value })}
                  className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary w-full"
                  placeholder="First name"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-brand-primary mb-1">Last Name *</label>
                <input
                  type="text"
                  required
                  value={createForm.last_name}
                  onChange={(e) => setCreateForm({ ...createForm, last_name: e.target.value })}
                  className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary w-full"
                  placeholder="Last name"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-brand-primary mb-1">Email *</label>
                <input
                  type="email"
                  required
                  value={createForm.email}
                  onChange={(e) => setCreateForm({ ...createForm, email: e.target.value })}
                  className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary w-full"
                  placeholder="user@example.com"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-brand-primary mb-1">Password</label>
                <input
                  type="password"
                  value={createForm.password}
                  onChange={(e) => setCreateForm({ ...createForm, password: e.target.value })}
                  className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary w-full"
                  placeholder="Leave blank for default"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-brand-primary mb-1">System Role</label>
                <select
                  value={createForm.system_role}
                  onChange={(e) => setCreateForm({ ...createForm, system_role: e.target.value })}
                  className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary w-full"
                >
                  <option value="user">User</option>
                  <option value="super_admin">Super Admin</option>
                </select>
              </div>
            </div>
            <div className="mt-4 flex gap-2">
              <button
                type="submit"
                disabled={creating}
                className="bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-3 hover:bg-brand-primary uppercase font-semibold text-sm disabled:opacity-50"
              >
                {creating ? 'Creating...' : 'Save User'}
              </button>
              <button
                type="button"
                onClick={() => setShowCreateForm(false)}
                className="bg-white text-brand-primary border border-brand-secondary rounded-md px-6 py-3 hover:bg-gray-50 uppercase font-semibold text-sm"
              >
                Cancel
              </button>
            </div>
          </form>
        )}
      </div>

      {/* Search */}
      <form onSubmit={handleSearch} className="mb-4">
        <div className="flex gap-2">
          <input
            type="text"
            placeholder="Search by name or email..."
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            className="flex-1 px-4 py-2 border border-brand-secondary rounded-md focus:outline-none focus:border-brand-primary"
          />
          <button
            type="submit"
            className="px-4 py-2 bg-brand-primary text-white rounded-md hover:bg-brand-primary-dark"
          >
            Search
          </button>
        </div>
      </form>

      {/* Table */}
      <DataTable<User>
        columns={columns}
        rows={loading ? [] : users}
        rowKey={(user) => user.id}
        emptyState={loading ? 'Loading users...' : 'No users found'}
      />
    </div>
  );
};

export default UsersList;
