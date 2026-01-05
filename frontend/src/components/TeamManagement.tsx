import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import TeamList from './TeamList';
import TeamFormWithTabs from './TeamFormWithTabs';
import AthleteManagement from './AthleteManagement';
import SeasonManagement from './SeasonManagement';
import VenueManagement from './VenueManagement';

interface Team {
  id: number;
  name: string;
  age_group: string;
  division: string;
  season_name: string;
  coach_name: string;
  primary_coach_id: number | null;
  player_count: number;
  home_field_name: string;
}

const TeamManagement: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const navigate = useNavigate();
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
    division: ''
  });

  useEffect(() => {
    fetchTeams();
  }, [filters]);

  const fetchTeams = async () => {
    try {
      const queryParams = new URLSearchParams(
        Object.entries(filters).filter(([_, v]) => v !== '')
      );
      const response = await fetch(`${API_URL}/legacy/teams-gateway.php?${queryParams}`);
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
      const response = await fetch(`${API_URL}/legacy/teams-gateway.php?id=${teamId}`, {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ reason: 'Manual archive' })
      });

      if (response.ok) {
        fetchTeams();
      }
    } catch (error) {
      console.error('Error deleting team:', error);
    }
  };

  const handleFormSubmit = async (teamData: any) => {
    const url = selectedTeam
      ? `${API_URL}/legacy/teams-gateway.php?id=${selectedTeam.id}`
      : `${API_URL}/legacy/teams-gateway.php`;

    const method = selectedTeam ? 'PUT' : 'POST';

    try {
      const response = await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json' },
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
      <div className="mb-8 flex justify-between items-start">
        <div>
          <h2 className="text-3xl font-bold text-brand-primary mb-2 uppercase tracking-wide">Team Management</h2>
          <p className="text-gray-600">Manage your club's teams, coaches, and athletes</p>
        </div>
        <div className="flex space-x-2">
          <button
            onClick={handleCreateTeam}
            className="bg-brand-primary text-white border border-brand-secondary rounded-md px-4 py-2 hover:bg-brand-primary font-semibold uppercase"
          >
            + Create Team
          </button>
        </div>
      </div>

      <div className="border border-brand-secondary rounded-md p-6 mb-6 bg-white">
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <input
            type="text"
            placeholder="Search teams..."
            className="bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
            value={filters.search}
            onChange={(e) => setFilters({ ...filters, search: e.target.value })}
          />

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

          <button
            onClick={() => setFilters({ search: '', season_id: '', age_group: '', division: '' })}
            className="bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 hover:bg-gray-100 uppercase"
          >
            Clear Filters
          </button>
        </div>
      </div>

      {loading ? (
        <div className="text-center text-brand-primary py-12">Loading teams...</div>
      ) : (
        <TeamList
          teams={teams}
          onEdit={handleEditTeam}
          onDelete={handleDeleteTeam}
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