import React, { useState, useEffect } from 'react';

interface AddUserModalProps {
  leagueId: number;
  onClose: () => void;
  onSuccess: () => void;
}

interface Club {
  id: number;
  name: string;
}

const AddUserModal: React.FC<AddUserModalProps> = ({ leagueId, onClose, onSuccess }) => {
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';
  const [email, setEmail] = useState('');
  const [firstName, setFirstName] = useState('');
  const [lastName, setLastName] = useState('');
  const [role, setRole] = useState<'league_admin' | 'club_admin' | 'coach'>('coach');
  const [accessType, setAccessType] = useState<'league' | 'club'>('league');
  const [clubId, setClubId] = useState<number | null>(null);
  const [clubs, setClubs] = useState<Club[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    fetchClubs();
  }, [leagueId]);

  const fetchClubs = async () => {
    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/legacy/clubs-gateway.php?league_id=${leagueId}`, {
        headers: {
          'Authorization': `Bearer ${token}`
        }
      });

      if (response.ok) {
        const data = await response.json();
        setClubs(data.clubs || []);
      }
    } catch (err) {
      console.error('Failed to fetch clubs:', err);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!email || !firstName || !lastName) {
      setError('Please fill in all required fields');
      return;
    }

    if (accessType === 'club' && !clubId) {
      setError('Please select a club');
      return;
    }

    try {
      setLoading(true);
      setError(null);

      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/api/league-users-gateway.php?action=create`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify({
          leagueId,
          email,
          firstName,
          lastName,
          role,
          accessType,
          clubId
        })
      });

      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.error || 'Failed to create user');
      }

      onSuccess();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to create user');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
      <div className="bg-white rounded-lg shadow-xl max-w-md w-full max-h-[90vh] overflow-y-auto">
        {/* Header */}
        <div className="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
          <h2 className="text-xl font-semibold text-brand-primary">Create New User</h2>
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

          {/* Email */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Email *
            </label>
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="user@example.com"
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-brand-accent"
              required
            />
          </div>

          {/* First Name */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              First Name *
            </label>
            <input
              type="text"
              value={firstName}
              onChange={(e) => setFirstName(e.target.value)}
              placeholder="John"
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-brand-accent"
              required
            />
          </div>

          {/* Last Name */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Last Name *
            </label>
            <input
              type="text"
              value={lastName}
              onChange={(e) => setLastName(e.target.value)}
              placeholder="Doe"
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-brand-accent"
              required
            />
          </div>

          {/* Access Type */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Access Level *
            </label>
            <select
              value={accessType}
              onChange={(e) => setAccessType(e.target.value as 'league' | 'club')}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-brand-accent"
            >
              <option value="league">League Level</option>
              <option value="club">Club Level</option>
            </select>
          </div>

          {/* Club Selection (if club-level access) */}
          {accessType === 'club' && (
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Select Club *
              </label>
              <select
                value={clubId || ''}
                onChange={(e) => setClubId(Number(e.target.value))}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-brand-accent"
                required
              >
                <option value="">Choose a club...</option>
                {clubs.map((club) => (
                  <option key={club.id} value={club.id}>
                    {club.name}
                  </option>
                ))}
              </select>
            </div>
          )}

          {/* Role Selection */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Role *
            </label>
            <select
              value={role}
              onChange={(e) => setRole(e.target.value as 'league_admin' | 'club_admin' | 'coach')}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-brand-accent"
            >
              {accessType === 'league' ? (
                <>
                  <option value="league_admin">League Admin</option>
                  <option value="coach">Coach</option>
                </>
              ) : (
                <>
                  <option value="club_admin">Club Admin</option>
                  <option value="coach">Coach</option>
                </>
              )}
            </select>
            <p className="mt-1 text-xs text-gray-500">
              {role === 'league_admin' && 'Can manage all clubs and users in the league'}
              {role === 'club_admin' && 'Can manage a specific club'}
              {role === 'coach' && 'Can manage assigned teams'}
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
              disabled={loading}
              className="px-4 py-2 bg-brand-primary text-white rounded-md hover:bg-brand-primary disabled:opacity-50 disabled:cursor-not-allowed uppercase font-medium text-sm"
            >
              {loading ? 'Creating...' : 'Create User'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};

export default AddUserModal;
