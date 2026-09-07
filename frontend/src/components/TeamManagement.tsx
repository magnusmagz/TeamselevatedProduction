import React, { useState, useEffect } from 'react';
import { useAuth } from '../contexts/AuthContext';
import TeamList from './TeamList';
import TeamFormWithTabs from './TeamFormWithTabs';
import AthleteManagement from './AthleteManagement';
import SeasonManagement from './SeasonManagement';
import VenueManagement from './VenueManagement';
import PageHeader from './ui/PageHeader';
import Button from './ui/Button';

interface Team {
  id: number;
  name: string;
  age_group: string;
  gender?: string | null;
  division: string;
  season_name: string;
  coach_name: string;
  primary_coach_id: number | null;
  player_count: number;
  home_field_name: string;
}

const TeamManagement: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { user } = useAuth();
  // Club admins (and super admins) get the create/delete affordances.
  // Coaches see the same TeamList UI but read-only on those actions —
  // the backend rejects edits anyway, but hiding the buttons keeps the
  // experience clean.
  const canManageTeams =
    user?.system_role === 'super_admin' ||
    user?.activeRole?.role === 'club_admin';
  const [teams, setTeams] = useState<Team[]>([]);
  const [showForm, setShowForm] = useState(false);
  const [showAthleteManagement, setShowAthleteManagement] = useState(false);
  const [showSeasonManagement, setShowSeasonManagement] = useState(false);
  const [showVenueManagement, setShowVenueManagement] = useState(false);
  const [selectedTeam, setSelectedTeam] = useState<Team | null>(null);
  const [loading, setLoading] = useState(true);
  const [filters, setFilters] = useState({
    search: '',
    season_id: '',
    age_group: '',
    division: '',
    primary_coach_id: ''
  });

  const [allCoaches, setAllCoaches] = useState<{ id: number; name: string }[]>([]);

  useEffect(() => {
    fetchTeams();
  }, [filters]);

  // Build coach list from all teams (fetch unfiltered once)
  useEffect(() => {
    const fetchCoaches = async () => {
      try {
        const token = localStorage.getItem('auth_token');
        const response = await fetch(`${API_URL}/legacy/teams-gateway.php`, {
          headers: { 'Authorization': `Bearer ${token}` }
        });
        const data = await response.json();
        const coachMap = new Map<number, string>();
        (data.teams || []).forEach((t: Team) => {
          if (t.primary_coach_id && t.coach_name) {
            coachMap.set(t.primary_coach_id, t.coach_name);
          }
        });
        setAllCoaches(
          Array.from(coachMap, ([id, name]) => ({ id, name }))
            .sort((a, b) => a.name.localeCompare(b.name))
        );
      } catch (err) {
        console.error('Error fetching coaches:', err);
      }
    };
    fetchCoaches();
  }, [API_URL]);

  const fetchTeams = async () => {
    try {
      const token = localStorage.getItem('auth_token');
      const queryParams = new URLSearchParams(
        Object.entries(filters).filter(([_, v]) => v !== '')
      );
      const response = await fetch(`${API_URL}/legacy/teams-gateway.php?${queryParams}`, {
        headers: {
          'Authorization': `Bearer ${token}`
        }
      });
      const data = await response.json();
      setTeams(data.teams || []);
    } catch (error) {
      console.error('Error fetching teams:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleCreateTeam = () => {
    setSelectedTeam(null);
    setShowForm(true);
  };

  const handleEditTeam = (team: Team) => {
    setSelectedTeam(team);
    setShowForm(true);
  };

  const handleDeleteTeam = async (teamId: number) => {
    if (!window.confirm('Are you sure you want to archive this team?')) return;

    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/legacy/teams-gateway.php?id=${teamId}`, {
        method: 'DELETE',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify({ reason: 'Manual archive' })
      });

      if (response.ok) {
        fetchTeams();
      } else {
        const data = await response.json().catch(() => ({}));
        alert(data.error || 'Failed to archive team. You may not have permission.');
      }
    } catch (error) {
      console.error('Error archiving team:', error);
      alert('Failed to archive team. Please try again.');
    }
  };

  const handleFormSubmit = async (teamData: any) => {
    const url = selectedTeam
      ? `${API_URL}/legacy/teams-gateway.php?id=${selectedTeam.id}`
      : `${API_URL}/legacy/teams-gateway.php`;

    const method = selectedTeam ? 'PUT' : 'POST';
    const token = localStorage.getItem('auth_token');

    try {
      const response = await fetch(url, {
        method,
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify(teamData)
      });

      const data = await response.json();

      if (response.ok && data.success) {
        setShowForm(false);
        fetchTeams();
      } else {
        console.error('Error saving team:', data.error || 'Unknown error');
        alert(`Failed to ${selectedTeam ? 'update' : 'create'} team: ${data.error || 'Unknown error'}`);
      }
    } catch (error) {
      console.error('Error saving team:', error);
      alert(`Failed to ${selectedTeam ? 'update' : 'create'} team. Please try again.`);
    }
  };

  return (
    <div>
      <PageHeader title="Team Management" subtitle="Manage your club's teams, coaches, and athletes" />

      <div className="border border-brand-secondary rounded-md p-4 sm:p-6 mb-6 bg-white">
        <div className="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 mb-4">
          <div className="flex flex-col sm:flex-row sm:items-center gap-3">
            <input
              type="text"
              placeholder="Search teams..."
              className="bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent w-full sm:w-auto"
              value={filters.search}
              onChange={(e) => setFilters({ ...filters, search: e.target.value })}
            />
            <span className="text-brand-primary text-sm">
              {teams.length} team{teams.length !== 1 ? 's' : ''} found
            </span>
          </div>
          {canManageTeams && (
            <Button onClick={handleCreateTeam} className="w-full sm:w-auto">
              + Create Team
            </Button>
          )}
        </div>
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">

          <select
            className="bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
            value={filters.age_group}
            onChange={(e) => setFilters({ ...filters, age_group: e.target.value })}
          >
            <option value="">All Age Groups</option>
            <option value="U5">U5</option>
            <option value="U6">U6</option>
            <option value="U7">U7</option>
            <option value="U8">U8</option>
            <option value="U9">U9</option>
            <option value="U10">U10</option>
            <option value="U11">U11</option>
            <option value="U12">U12</option>
            <option value="U13">U13</option>
            <option value="U14">U14</option>
            <option value="U15">U15</option>
            <option value="U16">U16</option>
            <option value="U17">U17</option>
            <option value="U18">U18</option>
            <option value="U19">U19</option>
            <option value="Adult">Adult</option>
          </select>

          <select
            className="bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
            value={filters.division}
            onChange={(e) => setFilters({ ...filters, division: e.target.value })}
          >
            <option value="">All Divisions</option>
            <option value="Recreational">Recreational</option>
            <option value="Competitive">Competitive</option>
            <option value="Elite">Elite</option>
          </select>

          <select
            className="bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
            value={filters.primary_coach_id}
            onChange={(e) => setFilters({ ...filters, primary_coach_id: e.target.value })}
          >
            <option value="">All Coaches</option>
            {allCoaches.map((coach) => (
              <option key={coach.id} value={coach.id}>
                {coach.name}
              </option>
            ))}
          </select>

          <Button
            variant="secondary"
            onClick={() => setFilters({ search: '', season_id: '', age_group: '', division: '', primary_coach_id: '' })}
          >
            Clear Filters
          </Button>
        </div>
      </div>

      {loading ? (
        <div className="text-center text-brand-primary py-12">Loading teams...</div>
      ) : (
        <TeamList
          teams={teams}
          onEdit={handleEditTeam}
          onDelete={handleDeleteTeam}
          canDelete={canManageTeams}
        />
      )}

      {showForm && (
        <TeamFormWithTabs
          team={selectedTeam}
          onSubmit={handleFormSubmit}
          onClose={() => setShowForm(false)}
        />
      )}

      {showAthleteManagement && (
        <AthleteManagement onClose={() => setShowAthleteManagement(false)} />
      )}

      {showSeasonManagement && (
        <SeasonManagement onClose={() => setShowSeasonManagement(false)} />
      )}

      {showVenueManagement && (
        <VenueManagement onClose={() => setShowVenueManagement(false)} />
      )}
    </div>
  );
};

export default TeamManagement;