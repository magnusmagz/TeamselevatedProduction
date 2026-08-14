import React, { useState, useEffect, useCallback, useRef } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import PlatformStats from '../components/superadmin/PlatformStats';
import ClubsList from '../components/superadmin/ClubsList';
import ClubDetails from '../components/superadmin/ClubDetails';
import UsersList from '../components/superadmin/UsersList';
import UserDetails from '../components/superadmin/UserDetails';
import AthletesList from '../components/superadmin/AthletesList';
import { useTheme } from '../contexts/ThemeContext';
import { useAuth } from '../contexts/AuthContext';

const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

type Tab = 'overview' | 'clubs' | 'users' | 'athletes' | 'templates';

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

interface Athlete {
  id: number;
  first_name: string;
  last_name: string;
  email: string | null;
  date_of_birth: string | null;
  gender: string | null;
  active_status: boolean;
  created_at: string;
  club_id: number | null;
  club_name: string | null;
  team_names: string | null;
}

const TE_DEFAULT_PRIMARY = '#12443e';

const SuperAdminDashboard: React.FC = () => {
  const { colors, updateTheme } = useTheme();
  const { impersonate } = useAuth();
  const navigate = useNavigate();
  const savedColorsRef = useRef(colors.primary);

  // Override to TE default branding on mount, restore on unmount
  useEffect(() => {
    savedColorsRef.current = colors.primary;
    if (colors.primary !== TE_DEFAULT_PRIMARY) {
      updateTheme(TE_DEFAULT_PRIMARY);
    }
    return () => {
      // Restore club branding when leaving super admin
      if (savedColorsRef.current !== TE_DEFAULT_PRIMARY) {
        updateTheme(savedColorsRef.current);
      }
    };
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

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

  // Athletes state
  const [athletes, setAthletes] = useState<Athlete[]>([]);
  const [athletesLoading, setAthletesLoading] = useState(false);

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

  // Fetch athletes
  const fetchAthletes = useCallback(async (search = '') => {
    setAthletesLoading(true);
    try {
      const url = search
        ? `${API_URL}/api/super-admin-gateway.php?action=athletes&search=${encodeURIComponent(search)}`
        : `${API_URL}/api/super-admin-gateway.php?action=athletes`;
      const response = await fetch(url, { headers: getAuthHeaders() });
      const data = await response.json();
      if (data.success) {
        setAthletes(data.athletes);
      }
    } catch (error) {
      console.error('Error fetching athletes:', error);
    } finally {
      setAthletesLoading(false);
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

  // Create club
  const handleCreateClub = async (data: { name: string; city: string; state: string; website: string; primary_color: string }): Promise<boolean> => {
    try {
      const response = await fetch(`${API_URL}/api/super-admin-gateway.php?action=create-club`, {
        method: 'POST',
        headers: getAuthHeaders(),
        body: JSON.stringify(data),
      });
      const result = await response.json();
      if (result.success) {
        fetchClubs();
        fetchStats();
        return true;
      } else {
        alert(result.error || 'Failed to create club');
        return false;
      }
    } catch (error) {
      console.error('Error creating club:', error);
      alert('Failed to create club');
      return false;
    }
  };

  // Update club
  const handleUpdateClub = async (data: { id: number; name?: string; city?: string; state?: string; website?: string; primary_color?: string }): Promise<boolean> => {
    try {
      const response = await fetch(`${API_URL}/api/super-admin-gateway.php?action=update-club`, {
        method: 'PUT',
        headers: getAuthHeaders(),
        body: JSON.stringify(data),
      });
      const result = await response.json();
      if (result.success) {
        fetchClubDetails(data.id);
        fetchClubs();
        return true;
      } else {
        alert(result.error || 'Failed to update club');
        return false;
      }
    } catch (error) {
      console.error('Error updating club:', error);
      alert('Failed to update club');
      return false;
    }
  };

  // Delete club
  const handleDeleteClub = async (clubId: number): Promise<boolean> => {
    try {
      const response = await fetch(`${API_URL}/api/super-admin-gateway.php?action=delete-club&id=${clubId}`, {
        method: 'DELETE',
        headers: getAuthHeaders(),
      });
      const result = await response.json();
      if (result.success) {
        setSelectedClub(null);
        fetchClubs();
        fetchStats();
        return true;
      } else {
        alert(result.error || 'Failed to delete club');
        return false;
      }
    } catch (error) {
      console.error('Error deleting club:', error);
      alert('Failed to delete club');
      return false;
    }
  };

  // Create user
  const handleCreateUser = async (data: { first_name: string; last_name: string; email: string; password: string; system_role: string }): Promise<boolean> => {
    try {
      const response = await fetch(`${API_URL}/api/super-admin-gateway.php?action=create-user`, {
        method: 'POST',
        headers: getAuthHeaders(),
        body: JSON.stringify(data),
      });
      const result = await response.json();
      if (result.success) {
        fetchUsers();
        fetchStats();
        return true;
      } else {
        alert(result.error || 'Failed to create user');
        return false;
      }
    } catch (error) {
      console.error('Error creating user:', error);
      alert('Failed to create user');
      return false;
    }
  };

  // Update user
  const handleUpdateUser = async (data: { id: number; first_name?: string; last_name?: string; email?: string; phone?: string }): Promise<boolean> => {
    try {
      const response = await fetch(`${API_URL}/api/super-admin-gateway.php?action=update-user`, {
        method: 'PUT',
        headers: getAuthHeaders(),
        body: JSON.stringify(data),
      });
      const result = await response.json();
      if (result.success) {
        fetchUserDetails(data.id);
        fetchUsers();
        return true;
      } else {
        alert(result.error || 'Failed to update user');
        return false;
      }
    } catch (error) {
      console.error('Error updating user:', error);
      alert('Failed to update user');
      return false;
    }
  };

  // Assign user to club
  const handleAssignUserToClub = async (userId: number, clubId: number, role: string): Promise<boolean> => {
    try {
      const response = await fetch(`${API_URL}/api/super-admin-gateway.php?action=assign-user-to-club`, {
        method: 'POST',
        headers: getAuthHeaders(),
        body: JSON.stringify({ user_id: userId, club_id: clubId, role }),
      });
      const result = await response.json();
      if (result.success) {
        // Refresh relevant data
        if (selectedClub?.id === clubId) {
          fetchClubDetails(clubId);
        }
        if (selectedUser?.id === userId) {
          fetchUserDetails(userId);
        }
        fetchUsers();
        fetchClubs();
        return true;
      } else {
        alert(result.error || 'Failed to assign user to club');
        return false;
      }
    } catch (error) {
      console.error('Error assigning user to club:', error);
      alert('Failed to assign user to club');
      return false;
    }
  };

  // Start impersonating a user, then land on the dashboard as them.
  //
  // The confirm is not ceremony: from the click onwards every action is taken as
  // that person against their real data, and the only difference on screen is the
  // banner.
  const handleImpersonate = async (target: { id: number; first_name: string; last_name: string; email: string }) => {
    const name = `${target.first_name} ${target.last_name}`.trim();
    const confirmed = window.confirm(
      `Sign in as ${name} (${target.email})?\n\n` +
      'You will see and act on their real data for up to 1 hour. ' +
      'Anything you do is recorded against your admin account.'
    );
    if (!confirmed) return;

    try {
      await impersonate(target.id);
      navigate('/dashboard');
    } catch (error) {
      alert(error instanceof Error ? error.message : 'Could not start impersonation');
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
    }
    if (activeTab === 'clubs' && users.length === 0) {
      // Preload users for assign-user search in club details
      fetchUsers();
    }
    if (activeTab === 'users' && clubs.length === 0) {
      // Preload clubs for assign-to-club dropdown in user details
      fetchClubs();
    }
    if (activeTab === 'users' && users.length === 0) {
      fetchUsers();
    } else if (activeTab === 'athletes' && athletes.length === 0) {
      fetchAthletes();
    }
  }, [activeTab, clubs.length, users.length, athletes.length, fetchClubs, fetchUsers, fetchAthletes]);

  const tabs: { key: Tab; label: string }[] = [
    { key: 'overview', label: 'Overview' },
    { key: 'clubs', label: 'Clubs' },
    { key: 'users', label: 'Users' },
    { key: 'athletes', label: 'Athletes' },
    { key: 'templates', label: 'Email Templates' },
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
          onCreateClub={handleCreateClub}
        />
      )}

      {activeTab === 'users' && (
        <UsersList
          users={users}
          loading={usersLoading}
          onViewDetails={fetchUserDetails}
          onToggleSuperAdmin={handleToggleSuperAdmin}
          onSearch={fetchUsers}
          onCreateUser={handleCreateUser}
          onImpersonate={handleImpersonate}
        />
      )}

      {activeTab === 'athletes' && (
        <AthletesList
          athletes={athletes}
          loading={athletesLoading}
          onSearch={fetchAthletes}
        />
      )}

      {activeTab === 'templates' && (
        <div className="bg-white border border-brand-secondary rounded-md p-6">
          <div className="flex items-center justify-between mb-6">
            <div>
              <h2 className="text-xl font-bold text-brand-primary uppercase tracking-wide">Platform Email Templates</h2>
              <p className="text-sm text-gray-600 mt-1">Create templates that are available to all clubs. Clubs get their own copy when they edit.</p>
            </div>
            <Link
              to="/email-templates/new"
              className="bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-3 hover:bg-brand-primary uppercase font-semibold text-sm"
            >
              Create Platform Template
            </Link>
          </div>
          <div className="border-t border-brand-secondary pt-4">
            <Link
              to="/email-templates"
              className="text-brand-primary hover:underline font-medium"
            >
              Open Template Library →
            </Link>
            <p className="text-xs text-gray-500 mt-1">Set scope to "Platform" when creating to make templates available to all clubs.</p>
          </div>
        </div>
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
          onUpdateClub={handleUpdateClub}
          onDeleteClub={handleDeleteClub}
          onAssignUser={handleAssignUserToClub}
          allUsers={users.map(u => ({ id: u.id, first_name: u.first_name, last_name: u.last_name, email: u.email }))}
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
          onUpdateUser={handleUpdateUser}
          onAssignToClub={handleAssignUserToClub}
          allClubs={clubs.map(c => ({ id: c.id, name: c.name }))}
        />
      )}
    </main>
  );
};

export default SuperAdminDashboard;
