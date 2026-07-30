import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import PracticeScheduler from './PracticeScheduler';

interface Coach {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  phone?: string | null;
  team_count: number;
  teams?: { id: number; name: string }[];
}

interface CoachManagementProps {
  onClose?: () => void;
}

const CoachManagement: React.FC<CoachManagementProps> = ({ onClose }) => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const [coaches, setCoaches] = useState<Coach[]>([]);
  const [showForm, setShowForm] = useState(false);
  const [selectedCoach, setSelectedCoach] = useState<Coach | null>(null);
  const [showScheduler, setShowScheduler] = useState(false);
  const [schedulerCoach, setSchedulerCoach] = useState<Coach | null>(null);
  const [schedulerTeam, setSchedulerTeam] = useState<{ id: number; name: string } | null>(null);
  const [formData, setFormData] = useState({
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    password: 'password123',
    role: 'coach'
  });
  const [loading, setLoading] = useState(true);
  const [searchTerm, setSearchTerm] = useState('');
  const [viewingTeamsCoach, setViewingTeamsCoach] = useState<Coach | null>(null);
  const [coachTeams, setCoachTeams] = useState<{ id: number; name: string }[]>([]);

  useEffect(() => {
    fetchCoaches();
  }, []);

  const fetchCoaches = async () => {
    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/legacy/coaches-gateway.php?action=available`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      const data = await response.json();
      // Endpoint returns an array on success, or an error object on 401/403.
      setCoaches(Array.isArray(data) ? data : []);
    } catch (error) {
      console.error('Error fetching coaches:', error);
    } finally {
      setLoading(false);
    }
  };

  const fetchCoachTeams = async (coachId: number) => {
    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/legacy/teams-gateway.php?primary_coach_id=${coachId}`, {
        headers: {
          'Authorization': `Bearer ${token}`
        }
      });
      const data = await response.json();
      if (data.teams && data.teams.length > 0) {
        return data.teams[0]; // Return the first team
      }
      return null;
    } catch (error) {
      console.error('Error fetching coach teams:', error);
      return null;
    }
  };

  const handleViewTeams = async (coach: Coach) => {
    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/legacy/teams-gateway.php?primary_coach_id=${coach.id}`, {
        headers: {
          'Authorization': `Bearer ${token}`
        }
      });
      const data = await response.json();
      setCoachTeams(data.teams || []);
      setViewingTeamsCoach(coach);
    } catch (error) {
      console.error('Error fetching coach teams:', error);
      setCoachTeams([]);
      setViewingTeamsCoach(coach);
    }
  };

  const handleViewSchedule = async (coach: Coach) => {
    const team = await fetchCoachTeams(coach.id);
    if (team) {
      setSchedulerCoach(coach);
      setSchedulerTeam({ id: team.id, name: team.name });
      setShowScheduler(true);
    } else {
      alert(`No teams found for ${coach.first_name} ${coach.last_name}. Please assign a team first.`);
    }
  };

  const handleAddCoach = () => {
    setSelectedCoach(null);
    setFormData({
      first_name: '',
      last_name: '',
      email: '',
      phone: '',
      password: 'password123',
      role: 'coach'
    });
    setShowForm(true);
  };

  const handleEditCoach = (coach: Coach) => {
    setSelectedCoach(coach);
    setFormData({
      first_name: coach.first_name,
      last_name: coach.last_name,
      email: coach.email,
      phone: coach.phone || '',
      password: '',
      role: 'coach'
    });
    setShowForm(true);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    try {
      const isEditing = selectedCoach !== null;
      const url = isEditing
        ? `${API_URL}/legacy/coaches-gateway.php?action=update&id=${selectedCoach.id}`
        : `${API_URL}/legacy/coaches-gateway.php?action=create`;

      const token = localStorage.getItem('auth_token');
      const response = await fetch(url, {
        method: isEditing ? 'PUT' : 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify(formData)
      });

      if (response.ok) {
        if (isEditing) {
          alert('Coach updated successfully!');
        } else {
          alert('Coach created successfully! Default password is: password123');
        }
        setFormData({
          first_name: '',
          last_name: '',
          email: '',
          phone: '',
          password: 'password123',
          role: 'coach'
        });
        setSelectedCoach(null);
        setShowForm(false);
        fetchCoaches();
      } else {
        const error = await response.json();
        alert(error.error || `Failed to ${isEditing ? 'update' : 'create'} coach`);
      }
    } catch (error) {
      console.error(`Error ${selectedCoach ? 'updating' : 'creating'} coach:`, error);
      alert(`Failed to ${selectedCoach ? 'update' : 'create'} coach`);
    }
  };

  const filteredCoaches = coaches.filter(coach => {
    const fullName = `${coach.first_name} ${coach.last_name}`.toLowerCase();
    return fullName.includes(searchTerm.toLowerCase()) ||
           coach.email.toLowerCase().includes(searchTerm.toLowerCase());
  });

  // If modal mode (has onClose prop)
  if (onClose) {
    return (
      <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
        <div className="bg-white border border-brand-secondary rounded-md w-full max-w-6xl max-h-[90vh] overflow-hidden flex flex-col">
          <div className="border-b border-brand-secondary px-6 py-4 flex justify-between items-center">
            <h3 className="text-xl font-semibold text-brand-primary uppercase tracking-wide">Coach Management</h3>
            <button
              onClick={onClose}
              className="text-brand-primary hover:bg-gray-100 px-2 text-2xl"
            >
              ×
            </button>
          </div>

          <div className="flex-1 overflow-y-auto p-4 sm:p-6">
            <div className="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 mb-6">
              <div className="flex flex-col sm:flex-row sm:items-center gap-3 sm:space-x-4">
                <input
                  type="text"
                  placeholder="Search coaches..."
                  className="px-4 py-2 border border-brand-secondary rounded-md focus:outline-none focus:border-brand-accent w-full sm:w-auto"
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                />
                <span className="text-brand-primary text-sm">
                  {filteredCoaches.length} coach{filteredCoaches.length !== 1 ? 'es' : ''} found
                </span>
              </div>
              <button
                onClick={handleAddCoach}
                className="bg-brand-primary text-white border border-brand-secondary rounded-md px-4 py-2 hover:bg-brand-primary uppercase font-semibold w-full sm:w-auto"
              >
                + Add Coach
              </button>
            </div>

            {showForm && (
              <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
                <div className="bg-white border border-brand-secondary rounded-md w-full max-w-2xl">
                  <div className="border-b border-brand-secondary px-6 py-4 flex justify-between items-center">
                    <h4 className="text-lg font-semibold text-brand-primary uppercase tracking-wide">
                      {selectedCoach ? 'Edit Coach' : 'Add New Coach'}
                    </h4>
                    <button
                      onClick={() => {
                        setShowForm(false);
                        setSelectedCoach(null);
                      }}
                      className="text-brand-primary hover:bg-gray-100 px-2 text-2xl"
                    >
                      ×
                    </button>
                  </div>
                  <form onSubmit={handleSubmit} className="p-6">
                    <div className="grid grid-cols-2 gap-4">
                      <div>
                        <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                          First Name *
                        </label>
                        <input
                          type="text"
                          className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                          value={formData.first_name}
                          onChange={(e) => setFormData({ ...formData, first_name: e.target.value })}
                          required
                        />
                      </div>

                      <div>
                        <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                          Last Name *
                        </label>
                        <input
                          type="text"
                          className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                          value={formData.last_name}
                          onChange={(e) => setFormData({ ...formData, last_name: e.target.value })}
                          required
                        />
                      </div>

                      <div className="col-span-2">
                        <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                          Email *
                        </label>
                        <input
                          type="email"
                          className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                          value={formData.email}
                          onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                          required
                        />
                      </div>

                      <div className="col-span-2">
                        <p className="text-gray-600 text-sm">
                          Default password: <span className="font-mono bg-gray-100 border border-brand-primary px-2 py-1">password123</span>
                        </p>
                        <p className="text-gray-500 text-xs mt-1">
                          The coach should change this password on first login
                        </p>
                      </div>

                      <div className="col-span-2 flex justify-end space-x-4 mt-4">
                        <button
                          type="button"
                          onClick={() => setShowForm(false)}
                          className="bg-white text-brand-primary border border-brand-secondary rounded-md px-6 py-2 hover:bg-gray-100 uppercase"
                        >
                          Cancel
                        </button>
                        <button
                          type="submit"
                          className="bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-2 hover:bg-brand-primary font-semibold uppercase"
                        >
                          {selectedCoach ? 'Update Coach' : 'Create Coach'}
                        </button>
                      </div>
                    </div>
                  </form>
                </div>
              </div>
            )}

            {loading ? (
              <div className="text-center text-brand-primary py-12">Loading coaches...</div>
            ) : filteredCoaches.length === 0 ? (
              <div className="text-center py-12">
                <p className="text-gray-600 mb-4">
                  {searchTerm ? 'No coaches found matching your search.' : 'No coaches registered yet.'}
                </p>
                {!searchTerm && (
                  <button
                    onClick={handleAddCoach}
                    className="bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-3 hover:bg-brand-primary uppercase font-semibold"
                  >
                    Add Your First Coach
                  </button>
                )}
              </div>
            ) : (
              <div className="border border-brand-secondary rounded-md overflow-hidden bg-white">
                <div className="overflow-x-auto">
                <table className="min-w-full border-collapse">
                  <thead>
                    <tr className="border-b border-brand-secondary bg-white">
                      <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase tracking-wider border-r border-gray-300">
                        Name
                      </th>
                      <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase tracking-wider border-r border-gray-300">
                        Email
                      </th>
                      <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase tracking-wider border-r border-gray-300">
                        Teams
                      </th>
                      <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase tracking-wider border-r border-gray-300">
                        Status
                      </th>
                      <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase tracking-wider">
                        Actions
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {filteredCoaches.map((coach, index) => (
                      <tr
                        key={coach.id}
                        className="border-b border-gray-300 hover:bg-gray-50"
                      >
                        <td className="px-6 py-4 whitespace-nowrap">
                          <div>
                            <Link
                              to={`/coach/${coach.id}`}
                              className="text-sm font-medium text-brand-primary hover:text-brand-primary-hover hover:underline"
                            >
                              {coach.first_name} {coach.last_name}
                            </Link>
                          </div>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap">
                          <div className="text-brand-primary">{coach.email}</div>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap">
                          <div className="text-brand-primary">
                            {coach.team_count > 0 ? (
                              <span className="font-semibold">{coach.team_count}</span>
                            ) : (
                              <span className="text-gray-500">0</span>
                            )}
                          </div>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap">
                          <span className="px-2 py-1 border border-brand-primary text-brand-primary text-xs uppercase">
                            Active
                          </span>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap">
                          <button
                            onClick={() => handleEditCoach(coach)}
                            className="text-brand-primary hover:underline mr-4 uppercase text-xs"
                          >
                            Edit
                          </button>
                          <button
                            onClick={() => handleViewSchedule(coach)}
                            className="text-brand-primary hover:underline uppercase text-xs"
                          >
                            View Schedule
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                </div>
              </div>
            )}
          </div>
        </div>

        {/* Practice Scheduler Modal */}
        {showScheduler && schedulerCoach && (
          <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
            <div className="bg-white border border-brand-secondary rounded-md w-full max-w-7xl max-h-[90vh] overflow-auto">
              <div className="border-b border-brand-secondary px-6 py-4 flex justify-between items-center">
                <h3 className="text-xl font-semibold text-brand-primary uppercase tracking-wide">
                  Practice Schedule for {schedulerCoach.first_name} {schedulerCoach.last_name}
                </h3>
                <button
                  onClick={() => {
                    setShowScheduler(false);
                    setSchedulerCoach(null);
                  }}
                  className="text-brand-primary hover:bg-gray-100 px-2 text-2xl"
                >
                  ×
                </button>
              </div>
              <div className="p-6">
                {schedulerTeam && (
                  <PracticeScheduler
                    team={schedulerTeam}
                    onClose={() => {
                      setShowScheduler(false);
                      setSchedulerCoach(null);
                      setSchedulerTeam(null);
                    }}
                  />
                )}
              </div>
            </div>
          </div>
        )}
      </div>
    );
  }

  // Standalone page mode (no onClose prop)
  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      {/* Back Button */}

      {/* Header */}
      <div className="mb-6">
        <h1 className="text-3xl font-bold text-brand-primary uppercase tracking-wide">COACH MANAGEMENT</h1>
        <p className="text-gray-600 mt-2">Manage all coaches in the system</p>
      </div>

      <div className="bg-white border border-brand-secondary rounded-md">
        <div className="p-4 sm:p-6">
          <div className="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 mb-6">
              <div className="flex flex-col sm:flex-row sm:items-center gap-3 sm:space-x-4">
                <input
                  type="text"
                  placeholder="Search coaches..."
                  className="px-4 py-2 border border-brand-secondary rounded-md focus:outline-none focus:border-brand-accent w-full sm:w-64"
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                />
                <span className="text-brand-primary text-sm">
                  {filteredCoaches.length} coach{filteredCoaches.length !== 1 ? 'es' : ''} found
                </span>
              </div>
              <button
                onClick={handleAddCoach}
                className="bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-2 hover:bg-brand-primary uppercase font-semibold w-full sm:w-auto"
              >
                + Add Coach
              </button>
            </div>

            {showForm && (
              <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
                <div className="bg-white border border-brand-secondary rounded-md w-full max-w-2xl">
                  <div className="border-b border-brand-secondary px-6 py-4 flex justify-between items-center">
                    <h4 className="text-lg font-semibold text-brand-primary uppercase tracking-wide">
                      {selectedCoach ? 'Edit Coach' : 'Add New Coach'}
                    </h4>
                    <button
                      onClick={() => {
                        setShowForm(false);
                        setSelectedCoach(null);
                      }}
                      className="text-brand-primary hover:bg-gray-100 px-2 text-2xl"
                    >
                      ×
                    </button>
                  </div>
                  <form onSubmit={handleSubmit} className="p-6">
                    <div className="grid grid-cols-2 gap-4">
                      <div>
                        <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                          First Name *
                        </label>
                        <input
                          type="text"
                          className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                          value={formData.first_name}
                          onChange={(e) => setFormData({ ...formData, first_name: e.target.value })}
                          required
                        />
                      </div>

                      <div>
                        <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                          Last Name *
                        </label>
                        <input
                          type="text"
                          className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                          value={formData.last_name}
                          onChange={(e) => setFormData({ ...formData, last_name: e.target.value })}
                          required
                        />
                      </div>

                      <div className="col-span-2">
                        <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                          Email *
                        </label>
                        <input
                          type="email"
                          className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                          value={formData.email}
                          onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                          required
                        />
                      </div>

                      <div className="col-span-2">
                        <p className="text-gray-600 text-sm">
                          Default password: <span className="font-mono bg-gray-100 border border-brand-primary px-2 py-1">password123</span>
                        </p>
                        <p className="text-gray-500 text-xs mt-1">
                          The coach should change this password on first login
                        </p>
                      </div>

                      <div className="col-span-2 flex justify-end space-x-4 mt-4">
                        <button
                          type="button"
                          onClick={() => setShowForm(false)}
                          className="bg-white text-brand-primary border border-brand-secondary rounded-md px-6 py-2 hover:bg-gray-100 uppercase"
                        >
                          Cancel
                        </button>
                        <button
                          type="submit"
                          className="bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-2 hover:bg-brand-primary font-semibold uppercase"
                        >
                          {selectedCoach ? 'Update Coach' : 'Create Coach'}
                        </button>
                      </div>
                    </div>
                  </form>
                </div>
              </div>
            )}

            {loading ? (
              <div className="text-center text-brand-primary py-12">Loading coaches...</div>
            ) : filteredCoaches.length === 0 ? (
              <div className="text-center py-12">
                <p className="text-gray-600 mb-4 text-lg">
                  {searchTerm ? 'No coaches found matching your search.' : 'No coaches registered yet.'}
                </p>
                {!searchTerm && (
                  <button
                    onClick={handleAddCoach}
                    className="bg-brand-primary text-white border border-brand-secondary rounded-md px-8 py-3 hover:bg-brand-primary uppercase font-semibold text-lg"
                  >
                    Add Your First Coach
                  </button>
                )}
              </div>
            ) : (
              <div className="overflow-x-auto border border-brand-secondary rounded-md bg-white">
                <table className="min-w-full border-collapse">
                  <thead>
                    <tr className="border-b border-brand-secondary bg-white">
                      <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase tracking-wider border-r border-gray-300">
                        Name
                      </th>
                      <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase tracking-wider border-r border-gray-300">
                        Email
                      </th>
                      <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase tracking-wider border-r border-gray-300">
                        Teams
                      </th>
                      <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase tracking-wider border-r border-gray-300">
                        Status
                      </th>
                      <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase tracking-wider">
                        Actions
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {filteredCoaches.map((coach) => (
                      <tr
                        key={coach.id}
                        className="border-b border-gray-300 hover:bg-gray-50"
                      >
                        <td className="px-6 py-4 whitespace-nowrap border-r border-gray-300">
                          <div>
                            <Link
                              to={`/coach/${coach.id}`}
                              className="text-sm font-medium text-brand-primary hover:text-brand-primary-hover hover:underline"
                            >
                              {coach.first_name} {coach.last_name}
                            </Link>
                          </div>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-brand-primary border-r border-gray-300">
                          {coach.email}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-brand-primary border-r border-gray-300">
                          {coach.team_count > 0 ? coach.team_count : '0'}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap border-r border-gray-300">
                          <span className="px-2 py-1 text-xs text-brand-primary border border-brand-primary">
                            Active
                          </span>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm font-medium">
                          <button
                            onClick={() => handleEditCoach(coach)}
                            className="text-brand-primary hover:underline mr-4 uppercase text-xs"
                          >
                            Edit
                          </button>
                          <button
                            onClick={() => handleViewSchedule(coach)}
                            className="text-brand-primary hover:underline mr-4 uppercase text-xs"
                          >
                            View Schedule
                          </button>
                          <button
                            onClick={() => handleViewTeams(coach)}
                            className="text-brand-primary hover:underline uppercase text-xs"
                          >
                            View Teams
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
        </div>
      </div>

      {/* Practice Scheduler Modal */}
      {showScheduler && schedulerCoach && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
          <div className="bg-white border border-brand-secondary rounded-md w-full max-w-7xl max-h-[90vh] overflow-auto">
            <div className="border-b border-brand-secondary px-6 py-4 flex justify-between items-center">
              <h3 className="text-xl font-semibold text-brand-primary uppercase tracking-wide">
                Practice Schedule for {schedulerCoach.first_name} {schedulerCoach.last_name}
              </h3>
              <button
                onClick={() => {
                  setShowScheduler(false);
                  setSchedulerCoach(null);
                }}
                className="text-brand-primary hover:bg-gray-100 px-2 text-2xl"
              >
                ×
              </button>
            </div>
            <div className="p-6">
              {schedulerTeam && (
                <PracticeScheduler
                  team={schedulerTeam}
                  onClose={() => {
                    setShowScheduler(false);
                    setSchedulerCoach(null);
                    setSchedulerTeam(null);
                  }}
                />
              )}
            </div>
          </div>
        </div>
      )}

      {/* View Teams Modal */}
      {viewingTeamsCoach && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
          <div className="bg-white border border-brand-secondary rounded-md w-full max-w-lg">
            <div className="border-b border-brand-secondary px-6 py-4 flex justify-between items-center">
              <h3 className="text-xl font-semibold text-brand-primary uppercase tracking-wide">
                Teams for {viewingTeamsCoach.first_name} {viewingTeamsCoach.last_name}
              </h3>
              <button
                onClick={() => {
                  setViewingTeamsCoach(null);
                  setCoachTeams([]);
                }}
                className="text-brand-primary hover:bg-gray-100 px-2 text-2xl"
              >
                ×
              </button>
            </div>
            <div className="p-6">
              {coachTeams.length === 0 ? (
                <p className="text-gray-500 text-center py-4">No teams assigned to this coach.</p>
              ) : (
                <ul className="space-y-2">
                  {coachTeams.map((team) => (
                    <li key={team.id} className="p-3 border border-brand-secondary rounded-md">
                      <span className="font-medium text-brand-primary">{team.name}</span>
                    </li>
                  ))}
                </ul>
              )}
            </div>
            <div className="border-t border-brand-secondary px-6 py-4">
              <button
                onClick={() => {
                  setViewingTeamsCoach(null);
                  setCoachTeams([]);
                }}
                className="bg-white text-brand-primary border border-brand-secondary rounded-md px-6 py-2 hover:bg-gray-100 uppercase"
              >
                Close
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default CoachManagement;