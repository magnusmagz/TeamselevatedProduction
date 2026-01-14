import React, { useState } from 'react';

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
}

const ClubDetails: React.FC<ClubDetailsProps> = ({
  club,
  teams,
  users,
  loading,
  onClose,
  onChangeRole,
  onRemoveUser,
}) => {
  const [editingUserId, setEditingUserId] = useState<number | null>(null);
  const [selectedRole, setSelectedRole] = useState<string>('');

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

  if (!club && !loading) return null;

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-lg max-w-4xl w-full max-h-[90vh] overflow-hidden flex flex-col">
        {/* Header */}
        <div className="bg-brand-primary text-white px-6 py-4 flex justify-between items-center">
          <h2 className="text-xl font-bold uppercase">
            {loading ? 'Loading...' : club?.name}
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
            <div className="text-center py-8 text-gray-500">Loading club details...</div>
          ) : (
            <>
              {/* Teams Section */}
              <div className="mb-8">
                <h3 className="text-lg font-semibold text-brand-primary uppercase mb-4">
                  Teams ({teams.length})
                </h3>
                {teams.length === 0 ? (
                  <p className="text-gray-500">No teams in this club</p>
                ) : (
                  <div className="bg-gray-50 rounded-lg overflow-hidden">
                    <table className="w-full">
                      <thead className="bg-brand-secondary">
                        <tr>
                          <th className="px-4 py-2 text-left text-sm font-semibold text-brand-primary">Team Name</th>
                          <th className="px-4 py-2 text-left text-sm font-semibold text-brand-primary">Age/Gender</th>
                          <th className="px-4 py-2 text-left text-sm font-semibold text-brand-primary">Coach</th>
                          <th className="px-4 py-2 text-center text-sm font-semibold text-brand-primary">Athletes</th>
                        </tr>
                      </thead>
                      <tbody>
                        {teams.map((team) => (
                          <tr key={team.id} className="border-t border-gray-200">
                            <td className="px-4 py-2 font-medium">{team.name}</td>
                            <td className="px-4 py-2 text-gray-600">
                              {team.age_group} {team.gender}
                            </td>
                            <td className="px-4 py-2 text-gray-600">
                              {team.coach_name || '-'}
                            </td>
                            <td className="px-4 py-2 text-center">{team.athlete_count}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>

              {/* Users Section */}
              <div>
                <h3 className="text-lg font-semibold text-brand-primary uppercase mb-4">
                  Users ({users.length})
                </h3>
                {users.length === 0 ? (
                  <p className="text-gray-500">No users in this club</p>
                ) : (
                  <div className="bg-gray-50 rounded-lg overflow-hidden">
                    <table className="w-full">
                      <thead className="bg-brand-secondary">
                        <tr>
                          <th className="px-4 py-2 text-left text-sm font-semibold text-brand-primary">Name</th>
                          <th className="px-4 py-2 text-left text-sm font-semibold text-brand-primary">Email</th>
                          <th className="px-4 py-2 text-left text-sm font-semibold text-brand-primary">Role</th>
                          <th className="px-4 py-2 text-center text-sm font-semibold text-brand-primary">Actions</th>
                        </tr>
                      </thead>
                      <tbody>
                        {users.map((user) => (
                          <tr key={user.id} className="border-t border-gray-200">
                            <td className="px-4 py-2 font-medium">
                              {user.first_name} {user.last_name}
                              {user.system_role === 'super_admin' && (
                                <span className="ml-2 text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded">
                                  Super Admin
                                </span>
                              )}
                            </td>
                            <td className="px-4 py-2 text-gray-600">{user.email}</td>
                            <td className="px-4 py-2">
                              {editingUserId === user.id ? (
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
                              )}
                            </td>
                            <td className="px-4 py-2 text-center">
                              {editingUserId === user.id ? (
                                <div className="flex justify-center gap-2">
                                  <button
                                    onClick={() => handleRoleChange(user.id)}
                                    className="text-green-600 hover:text-green-800 text-sm"
                                  >
                                    Save
                                  </button>
                                  <button
                                    onClick={() => setEditingUserId(null)}
                                    className="text-gray-600 hover:text-gray-800 text-sm"
                                  >
                                    Cancel
                                  </button>
                                </div>
                              ) : (
                                <div className="flex justify-center gap-2">
                                  <button
                                    onClick={() => startEditing(user)}
                                    className="text-brand-primary hover:underline text-sm"
                                  >
                                    Edit
                                  </button>
                                  <button
                                    onClick={() => {
                                      if (window.confirm(`Remove ${user.first_name} ${user.last_name} from this club?`)) {
                                        onRemoveUser(user.id, club!.id);
                                      }
                                    }}
                                    className="text-red-600 hover:underline text-sm"
                                  >
                                    Remove
                                  </button>
                                </div>
                              )}
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

export default ClubDetails;
