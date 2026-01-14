import React, { useState, useEffect, useCallback } from 'react';
import PlatformStats from '../components/superadmin/PlatformStats';
import ClubsList from '../components/superadmin/ClubsList';
import ClubDetails from '../components/superadmin/ClubDetails';
import UsersList from '../components/superadmin/UsersList';
import UserDetails from '../components/superadmin/UserDetails';

const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

type Tab = 'overview' | 'clubs' | 'users';

interface Stats {
  total_clubs: number;
  total_users: number;
  total_teams: number;
  total_athletes: number;
  super_admins: number;
  club_admins: number;
  coaches: number;
}

interface Club {
  id: number;
  name: string;
  created_at: string;
  admin_count: number;
  coach_count: number;
  team_count: number;
  athlete_count: number;
}

interface ClubDetailData {
  id: number;
  name: string;
  created_at: string;
}

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

interface UserDetailData {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  system_role: string;
  created_at: string;
  last_login_at: string | null;
}

interface ClubRole {
  access_id: number;
  role: string;
  granted_at: string;
  club_id: number;
  club_name: string;
}

const SuperAdminDashboard: React.FC = () => {
  const [activeTab, setActiveTab] = useState<Tab>('overview');

  // Stats state
  const [stats, setStats] = useState<Stats | null>(null);
  const [statsLoading, setStatsLoading] = useState(true);

  // Clubs state
  const [clubs, setClubs] = useState<Club[]>([]);
  const [clubsLoading, setClubsLoading] = useState(false);
  const [selectedClub, setSelectedClub] = useState<ClubDetailData | null>(null);
  const [clubTeams, setClubTeams] = useState<Team[]>([]);
  const [clubUsers, setClubUsers] = useState<ClubUser[]>([]);
  const [clubDetailsLoading, setClubDetailsLoading] = useState(false);

  // Users state
  const [users, setUsers] = useState<User[]>([]);
  const [usersLoading, setUsersLoading] = useState(false);
  const [selectedUser, setSelectedUser] = useState<UserDetailData | null>(null);
  const [userClubRoles, setUserClubRoles] = useState<ClubRole[]>([]);
  const [userDetailsLoading, setUserDetailsLoading] = useState(false);

  const getAuthHeaders = () => ({
    'Authorization': `Bearer ${localStorage.getItem('auth_token')}`,
    'Content-Type': 'application/json',
  });

  // Fetch stats
  const fetchStats = useCallback(async () => {
    setStatsLoading(true);
    try {
      const response = await fetch(`${API_URL}/api/super-admin-gateway.php?action=stats`, {
        headers: getAuthHeaders(),
      });
      const data = await response.json();
      if (data.success) {
        setStats(data.stats);
      }
    } catch (error) {
      console.error('Error fetching stats:', error);
    } finally {
      setStatsLoading(false);
    }
  }, []);

  // Fetch clubs
  const fetchClubs = useCallback(async (search = '') => {
    setClubsLoading(true);
    try {
      const url = search
        ? `${API_URL}/api/super-admin-gateway.php?action=clubs&search=${encodeURIComponent(search)}`
        : `${API_URL}/api/super-admin-gateway.php?action=clubs`;
      const response = await fetch(url, { headers: getAuthHeaders() });
      const data = await response.json();
      if (data.success) {
        setClubs(data.clubs);
      }
    } catch (error) {
      console.error('Error fetching clubs:', error);
    } finally {
      setClubsLoading(false);
    }
  }, []);

  // Fetch club details
  const fetchClubDetails = useCallback(async (clubId: number) => {
    setClubDetailsLoading(true);
    try {
      const response = await fetch(
        `${API_URL}/api/super-admin-gateway.php?action=club-details&id=${clubId}`,
        { headers: getAuthHeaders() }
      );
      const data = await response.json();
      if (data.success) {
        setSelectedClub(data.club);
        setClubTeams(data.teams);
        setClubUsers(data.users);
      }
    } catch (error) {
      console.error('Error fetching club details:', error);
    } finally {
      setClubDetailsLoading(false);
    }
  }, []);

  // Fetch users
  const fetchUsers = useCallback(async (search = '') => {
    setUsersLoading(true);
    try {
      const url = search
        ? `${API_URL}/api/super-admin-gateway.php?action=users&search=${encodeURIComponent(search)}`
        : `${API_URL}/api/super-admin-gateway.php?action=users`;
      const response = await fetch(url, { headers: getAuthHeaders() });
      const data = await response.json();
      if (data.success) {
        setUsers(data.users);
      }
    } catch (error) {
      console.error('Error fetching users:', error);
    } finally {
      setUsersLoading(false);
    }
  }, []);

  // Fetch user details
  const fetchUserDetails = useCallback(async (userId: number) => {
    setUserDetailsLoading(true);
    try {
      const response = await fetch(
        `${API_URL}/api/super-admin-gateway.php?action=user-details&id=${userId}`,
        { headers: getAuthHeaders() }
      );
      const data = await response.json();
      if (data.success) {
        setSelectedUser(data.user);
        setUserClubRoles(data.club_roles);
      }
    } catch (error) {
      console.error('Error fetching user details:', error);
    } finally {
      setUserDetailsLoading(false);
    }
  }, []);

  // Update system role
  const handleToggleSuperAdmin = async (userId: number, makeSuperAdmin: boolean) => {
    try {
      const response = await fetch(`${API_URL}/api/super-admin-gateway.php?action=update-system-role`, {
        method: 'PUT',
        headers: getAuthHeaders(),
        body: JSON.stringify({
          user_id: userId,
          system_role: makeSuperAdmin ? 'super_admin' : 'user',
        }),
      });
      const data = await response.json();
      if (data.success) {
        // Refresh data
        fetchUsers();
        fetchStats();
        if (selectedUser?.id === userId) {
          fetchUserDetails(userId);
        }
      } else {
        alert(data.error || 'Failed to update role');
      }
    } catch (error) {
      console.error('Error updating system role:', error);
      alert('Failed to update role');
    }
  };

  // Update club role
  const handleChangeClubRole = async (userId: number, clubId: number, newRole: string) => {
    try {
      const response = await fetch(`${API_URL}/api/super-admin-gateway.php?action=update-club-role`, {
        method: 'PUT',
        headers: getAuthHeaders(),
        body: JSON.stringify({
          user_id: userId,
          club_id: clubId,
          role: newRole,
        }),
      });
      const data = await response.json();
      if (data.success) {
        // Refresh club details
        fetchClubDetails(clubId);
      } else {
        alert(data.error || 'Failed to update role');
      }
    } catch (error) {
      console.error('Error updating club role:', error);
      alert('Failed to update role');
    }
  };

  // Remove from club
  const handleRemoveFromClub = async (userId: number, clubId: number) => {
    try {
      const response = await fetch(
        `${API_URL}/api/super-admin-gateway.php?action=remove-club-access&user_id=${userId}&club_id=${clubId}`,
        {
          method: 'DELETE',
          headers: getAuthHeaders(),
        }
      );
      const data = await response.json();
      if (data.success) {
        // Refresh data
        if (selectedClub?.id === clubId) {
          fetchClubDetails(clubId);
        }
        if (selectedUser?.id === userId) {
          fetchUserDetails(userId);
        }
        fetchUsers();
        fetchClubs();
      } else {
        alert(data.error || 'Failed to remove user from club');
      }
    } catch (error) {
      console.error('Error removing from club:', error);
      alert('Failed to remove user from club');
    }
  };

  // Initial load
  useEffect(() => {
    fetchStats();
  }, [fetchStats]);

  // Load data when tab changes
  useEffect(() => {
    if (activeTab === 'clubs' && clubs.length === 0) {
      fetchClubs();
    } else if (activeTab === 'users' && users.length === 0) {
      fetchUsers();
    }
  }, [activeTab, clubs.length, users.length, fetchClubs, fetchUsers]);

  const tabs: { key: Tab; label: string }[] = [
    { key: 'overview', label: 'Overview' },
    { key: 'clubs', label: 'Clubs' },
    { key: 'users', label: 'Users' },
  ];

  return (
    <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      {/* Header */}
      <div className="mb-8">
        <h1 className="text-2xl font-bold text-brand-primary uppercase">Platform Administration</h1>
        <p className="text-gray-600 mt-1">Manage clubs, users, and roles across the platform</p>
      </div>

      {/* Tabs */}
      <div className="border-b border-brand-secondary mb-6">
        <div className="flex space-x-8">
          {tabs.map((tab) => (
            <button
              key={tab.key}
              onClick={() => setActiveTab(tab.key)}
              className={`py-3 px-1 border-b-2 font-medium text-sm uppercase transition-colors ${
                activeTab === tab.key
                  ? 'border-brand-primary text-brand-primary'
                  : 'border-transparent text-gray-500 hover:text-brand-primary hover:border-gray-300'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>
      </div>

      {/* Tab Content */}
      {activeTab === 'overview' && (
        <PlatformStats stats={stats} loading={statsLoading} />
      )}

      {activeTab === 'clubs' && (
        <ClubsList
          clubs={clubs}
          loading={clubsLoading}
          onViewDetails={fetchClubDetails}
          onSearch={fetchClubs}
        />
      )}

      {activeTab === 'users' && (
        <UsersList
          users={users}
          loading={usersLoading}
          onViewDetails={fetchUserDetails}
          onToggleSuperAdmin={handleToggleSuperAdmin}
          onSearch={fetchUsers}
        />
      )}

      {/* Club Details Modal */}
      {selectedClub && (
        <ClubDetails
          club={selectedClub}
          teams={clubTeams}
          users={clubUsers}
          loading={clubDetailsLoading}
          onClose={() => setSelectedClub(null)}
          onChangeRole={handleChangeClubRole}
          onRemoveUser={handleRemoveFromClub}
        />
      )}

      {/* User Details Modal */}
      {selectedUser && (
        <UserDetails
          user={selectedUser}
          clubRoles={userClubRoles}
          loading={userDetailsLoading}
          onClose={() => setSelectedUser(null)}
          onToggleSuperAdmin={handleToggleSuperAdmin}
          onRemoveFromClub={handleRemoveFromClub}
        />
      )}
    </main>
  );
};

export default SuperAdminDashboard;
