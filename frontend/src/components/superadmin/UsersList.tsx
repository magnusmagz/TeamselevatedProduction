import React, { useState } from 'react';

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
}

const UsersList: React.FC<UsersListProps> = ({
  users,
  loading,
  onViewDetails,
  onToggleSuperAdmin,
  onSearch,
}) => {
  const [searchTerm, setSearchTerm] = useState('');

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    onSearch(searchTerm);
  };

  return (
    <div>
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
      <div className="bg-white border border-brand-secondary rounded-lg overflow-hidden">
        <table className="w-full">
          <thead className="bg-brand-secondary">
            <tr>
              <th className="px-4 py-3 text-left text-sm font-semibold text-brand-primary uppercase">Name</th>
              <th className="px-4 py-3 text-left text-sm font-semibold text-brand-primary uppercase">Email</th>
              <th className="px-4 py-3 text-center text-sm font-semibold text-brand-primary uppercase">System Role</th>
              <th className="px-4 py-3 text-center text-sm font-semibold text-brand-primary uppercase">Clubs</th>
              <th className="px-4 py-3 text-left text-sm font-semibold text-brand-primary uppercase">Last Login</th>
              <th className="px-4 py-3 text-center text-sm font-semibold text-brand-primary uppercase">Actions</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr>
                <td colSpan={6} className="px-4 py-8 text-center text-gray-500">
                  Loading users...
                </td>
              </tr>
            ) : users.length === 0 ? (
              <tr>
                <td colSpan={6} className="px-4 py-8 text-center text-gray-500">
                  No users found
                </td>
              </tr>
            ) : (
              users.map((user) => (
                <tr key={user.id} className="border-t border-brand-secondary hover:bg-gray-50">
                  <td className="px-4 py-3 font-medium text-brand-primary">
                    {user.first_name} {user.last_name}
                  </td>
                  <td className="px-4 py-3 text-gray-600">{user.email}</td>
                  <td className="px-4 py-3 text-center">
                    {user.system_role === 'super_admin' ? (
                      <span className="px-2 py-1 bg-red-100 text-red-700 rounded text-sm">
                        Super Admin
                      </span>
                    ) : (
                      <span className="px-2 py-1 bg-gray-100 text-gray-600 rounded text-sm">
                        User
                      </span>
                    )}
                  </td>
                  <td className="px-4 py-3 text-center">{user.club_count}</td>
                  <td className="px-4 py-3 text-gray-600">
                    {user.last_login_at
                      ? new Date(user.last_login_at).toLocaleDateString()
                      : 'Never'}
                  </td>
                  <td className="px-4 py-3 text-center">
                    <div className="flex justify-center gap-2">
                      <button
                        onClick={() => onViewDetails(user.id)}
                        className="text-brand-primary hover:underline text-sm"
                      >
                        Details
                      </button>
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
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
};

export default UsersList;
