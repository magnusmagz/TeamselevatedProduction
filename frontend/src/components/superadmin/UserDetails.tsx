import React from 'react';

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

interface UserDetailsProps {
  user: User | null;
  clubRoles: ClubRole[];
  loading: boolean;
  onClose: () => void;
  onToggleSuperAdmin: (userId: number, makeSuperAdmin: boolean) => void;
  onRemoveFromClub: (userId: number, clubId: number) => void;
}

const UserDetails: React.FC<UserDetailsProps> = ({
  user,
  clubRoles,
  loading,
  onClose,
  onToggleSuperAdmin,
  onRemoveFromClub,
}) => {
  if (!user && !loading) return null;

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-lg max-w-2xl w-full max-h-[90vh] overflow-hidden flex flex-col">
        {/* Header */}
        <div className="bg-brand-primary text-white px-6 py-4 flex justify-between items-center">
          <h2 className="text-xl font-bold uppercase">
            {loading ? 'Loading...' : `${user?.first_name} ${user?.last_name}`}
          </h2>
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
                          <button
                            onClick={() => {
                              if (window.confirm('Remove super admin access?')) {
                                onToggleSuperAdmin(user.id, false);
                              }
                            }}
                            className="text-red-600 hover:underline text-sm"
                          >
                            Revoke
                          </button>
                        </>
                      ) : (
                        <>
                          <span className="px-2 py-1 bg-gray-100 text-gray-600 rounded text-sm">
                            User
                          </span>
                          <button
                            onClick={() => {
                              if (window.confirm('Grant super admin access?')) {
                                onToggleSuperAdmin(user.id, true);
                              }
                            }}
                            className="text-green-600 hover:underline text-sm"
                          >
                            Make Super Admin
                          </button>
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

              {/* Club Memberships */}
              <div>
                <h3 className="text-lg font-semibold text-brand-primary uppercase mb-4">
                  Club Memberships ({clubRoles.length})
                </h3>
                {clubRoles.length === 0 ? (
                  <p className="text-gray-500">User is not a member of any clubs</p>
                ) : (
                  <div className="bg-gray-50 rounded-lg overflow-hidden">
                    <table className="w-full">
                      <thead className="bg-brand-secondary">
                        <tr>
                          <th className="px-4 py-2 text-left text-sm font-semibold text-brand-primary">Club</th>
                          <th className="px-4 py-2 text-left text-sm font-semibold text-brand-primary">Role</th>
                          <th className="px-4 py-2 text-left text-sm font-semibold text-brand-primary">Since</th>
                          <th className="px-4 py-2 text-center text-sm font-semibold text-brand-primary">Actions</th>
                        </tr>
                      </thead>
                      <tbody>
                        {clubRoles.map((cr) => (
                          <tr key={cr.access_id} className="border-t border-gray-200">
                            <td className="px-4 py-2 font-medium">{cr.club_name}</td>
                            <td className="px-4 py-2">
                              <span className={`px-2 py-1 rounded text-sm ${
                                cr.role === 'club_admin'
                                  ? 'bg-blue-100 text-blue-700'
                                  : cr.role === 'coach'
                                  ? 'bg-green-100 text-green-700'
                                  : 'bg-gray-100 text-gray-700'
                              }`}>
                                {cr.role}
                              </span>
                            </td>
                            <td className="px-4 py-2 text-gray-600">
                              {new Date(cr.granted_at).toLocaleDateString()}
                            </td>
                            <td className="px-4 py-2 text-center">
                              <button
                                onClick={() => {
                                  if (window.confirm(`Remove user from ${cr.club_name}?`)) {
                                    onRemoveFromClub(user.id, cr.club_id);
                                  }
                                }}
                                className="text-red-600 hover:underline text-sm"
                              >
                                Remove
                              </button>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
};

export default UserDetails;
