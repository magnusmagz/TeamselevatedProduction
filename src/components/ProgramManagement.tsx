import React, { useState, useEffect } from 'react';
import TeamList from './TeamList';

interface Program {
  id?: number;
  name: string;
  type: 'league' | 'camp' | 'clinic' | 'tryout' | 'tournament' | 'drop_in';
  season_year: number;
  season_type: 'Spring' | 'Summer' | 'Fall' | 'Winter' | 'Year-Round';
  description?: string;
  start_date?: string;
  end_date?: string;
  registration_opens?: string;
  registration_closes?: string;
  min_age?: number;
  max_age?: number;
  capacity?: number;
  current_enrolled?: number;
  status: 'draft' | 'published' | 'closed' | 'cancelled';
  team_count?: number;
  player_count?: number;
}

const ProgramManagement: React.FC = () => {
  const [programs, setPrograms] = useState<Program[]>([]);
  const [showForm, setShowForm] = useState(false);
  const [selectedProgram, setSelectedProgram] = useState<Program | null>(null);
  const [loading, setLoading] = useState(true);
  const [filterYear, setFilterYear] = useState(new Date().getFullYear());
  const [filterSeason, setFilterSeason] = useState<string>('');
  const [showTeams, setShowTeams] = useState(false);
  const [selectedProgramForTeams, setSelectedProgramForTeams] = useState<Program | null>(null);

  const [formData, setFormData] = useState<Program>({
    name: '',
    type: 'league',
    season_year: new Date().getFullYear(),
    season_type: 'Year-Round',
    status: 'draft'
  });

  useEffect(() => {
    fetchPrograms();
  }, [filterYear, filterSeason]);

  const fetchPrograms = async () => {
    try {
      const params = new URLSearchParams();
      if (filterYear) params.append('season_year', filterYear.toString());
      if (filterSeason) params.append('season_type', filterSeason);

      const response = await fetch(`http://localhost:8889/programs-gateway.php?${params}`);
      const data = await response.json();
      setPrograms(data.programs || []);
    } catch (error) {
      console.error('Error fetching programs:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleAddProgram = () => {
    setSelectedProgram(null);
    setFormData({
      name: '',
      type: 'league',
      season_year: filterYear || new Date().getFullYear(),
      season_type: filterSeason as any || 'Year-Round',
      status: 'draft'
    });
    setShowForm(true);
  };

  const handleEditProgram = async (program: Program) => {
    setSelectedProgram(program);
    setFormData(program);
    setShowForm(true);
  };

  const handleDeleteProgram = async (programId: number) => {
    if (!window.confirm('Are you sure you want to delete this program?')) return;

    try {
      const response = await fetch(`http://localhost:8889/programs-gateway.php?id=${programId}`, {
        method: 'DELETE'
      });

      const result = await response.json();

      if (response.ok) {
        fetchPrograms();
      } else {
        alert(result.error || 'Failed to delete program');
      }
    } catch (error) {
      console.error('Error deleting program:', error);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    try {
      const url = selectedProgram
        ? `http://localhost:8889/programs-gateway.php?id=${selectedProgram.id}`
        : 'http://localhost:8889/programs-gateway.php';

      const response = await fetch(url, {
        method: selectedProgram ? 'PUT' : 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(formData)
      });

      if (response.ok) {
        setShowForm(false);
        fetchPrograms();
      }
    } catch (error) {
      console.error('Error saving program:', error);
    }
  };

  const handleManageTeams = (program: Program) => {
    setSelectedProgramForTeams(program);
    setShowTeams(true);
  };

  const currentYear = new Date().getFullYear();
  const years = Array.from({ length: 5 }, (_, i) => currentYear - 2 + i);

  if (loading) {
    return <div className="text-center text-forest-800 py-12">Loading programs...</div>;
  }

  return (
    <div>
      <div className="mb-8 flex justify-between items-start">
        <div>
          <h2 className="text-3xl font-bold text-forest-800 mb-2 uppercase tracking-wide">
            Program Management
          </h2>
          <p className="text-gray-600">Manage your club's programs and seasons</p>
        </div>
        <button
          onClick={handleAddProgram}
          className="bg-forest-800 text-white border-2 border-forest-800 px-4 py-2 hover:bg-forest-700 font-semibold uppercase"
        >
          + Add New Program
        </button>
      </div>

      {/* Filters */}
      <div className="border-2 border-forest-800 bg-white p-4 mb-6">
        <div className="flex gap-4 items-center">
          <select
            value={filterYear}
            onChange={(e) => setFilterYear(Number(e.target.value))}
            className="bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
          >
            {years.map(year => (
              <option key={year} value={year}>{year}</option>
            ))}
          </select>

          <select
            value={filterSeason}
            onChange={(e) => setFilterSeason(e.target.value)}
            className="bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
          >
            <option value="">All Seasons</option>
            <option value="Spring">Spring</option>
            <option value="Summer">Summer</option>
            <option value="Fall">Fall</option>
            <option value="Winter">Winter</option>
            <option value="Year-Round">Year-Round</option>
          </select>

          <div className="ml-auto text-forest-800">
            {programs.length} programs found
          </div>
        </div>
      </div>

      {/* Programs Grid */}
      <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
        {programs.map(program => (
          <div key={program.id} className="border-2 border-forest-800 bg-white p-6">
            <div className="flex justify-between items-start mb-4">
              <div>
                <h3 className="text-xl font-bold text-forest-800">{program.name}</h3>
                <div className="flex gap-2 mt-2">
                  <span className="px-2 py-1 text-xs border border-forest-800 text-forest-800">
                    {program.season_type}
                  </span>
                  <span className="px-2 py-1 text-xs border border-forest-800 text-forest-800">
                    {program.type}
                  </span>
                  <span className={`px-2 py-1 text-xs border ${
                    program.status === 'published'
                      ? 'border-green-600 text-green-600'
                      : 'border-gray-400 text-gray-600'
                  }`}>
                    {program.status}
                  </span>
                </div>
              </div>
            </div>

            <div className="space-y-2 text-sm text-gray-600">
              {program.start_date && (
                <div>
                  <span className="font-semibold">Duration:</span>{' '}
                  {new Date(program.start_date).toLocaleDateString()} - {new Date(program.end_date || '').toLocaleDateString()}
                </div>
              )}
              {program.registration_opens && (
                <div>
                  <span className="font-semibold">Registration:</span>{' '}
                  {new Date(program.registration_opens).toLocaleDateString()}
                </div>
              )}
              <div>
                <span className="font-semibold">Teams:</span> {program.team_count || 0}
              </div>
              <div>
                <span className="font-semibold">Players:</span> {program.player_count || 0}
              </div>
            </div>

            <div className="mt-4 flex gap-2">
              <button
                onClick={() => handleEditProgram(program)}
                className="text-forest-800 hover:text-forest-600 uppercase text-xs font-semibold"
              >
                Edit
              </button>
              <button
                onClick={() => handleManageTeams(program)}
                className="text-forest-800 hover:text-forest-600 uppercase text-xs font-semibold"
              >
                Teams ({program.team_count || 0})
              </button>
              {program.team_count === 0 && (
                <button
                  onClick={() => handleDeleteProgram(program.id!)}
                  className="text-red-600 hover:text-red-800 uppercase text-xs font-semibold"
                >
                  Delete
                </button>
              )}
            </div>
          </div>
        ))}
      </div>

      {/* Program Form Modal */}
      {showForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
          <div className="bg-white border-2 border-forest-800 max-w-2xl w-full max-h-[90vh] overflow-y-auto">
            <div className="border-b-2 border-forest-800 px-6 py-4">
              <h3 className="text-xl font-semibold text-forest-800 uppercase tracking-wide">
                {selectedProgram ? 'Edit Program' : 'Add New Program'}
              </h3>
            </div>

            <form onSubmit={handleSubmit} className="p-6">
              <div className="grid grid-cols-2 gap-4 mb-4">
                <div>
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    Program Name *
                  </label>
                  <input
                    type="text"
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                    required
                  />
                </div>

                <div>
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    Type *
                  </label>
                  <select
                    value={formData.type}
                    onChange={(e) => setFormData({ ...formData, type: e.target.value as any })}
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                    required
                  >
                    <option value="league">League</option>
                    <option value="camp">Camp</option>
                    <option value="clinic">Clinic</option>
                    <option value="tryout">Tryout</option>
                    <option value="tournament">Tournament</option>
                    <option value="drop_in">Drop-In</option>
                  </select>
                </div>

                <div>
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    Year *
                  </label>
                  <input
                    type="number"
                    value={formData.season_year}
                    onChange={(e) => setFormData({ ...formData, season_year: Number(e.target.value) })}
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                    required
                  />
                </div>

                <div>
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    Season *
                  </label>
                  <select
                    value={formData.season_type}
                    onChange={(e) => setFormData({ ...formData, season_type: e.target.value as any })}
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                    required
                  >
                    <option value="Spring">Spring</option>
                    <option value="Summer">Summer</option>
                    <option value="Fall">Fall</option>
                    <option value="Winter">Winter</option>
                    <option value="Year-Round">Year-Round</option>
                  </select>
                </div>

                <div>
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    Start Date
                  </label>
                  <input
                    type="date"
                    value={formData.start_date || ''}
                    onChange={(e) => setFormData({ ...formData, start_date: e.target.value })}
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                  />
                </div>

                <div>
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    End Date
                  </label>
                  <input
                    type="date"
                    value={formData.end_date || ''}
                    onChange={(e) => setFormData({ ...formData, end_date: e.target.value })}
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                  />
                </div>

                <div>
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    Status
                  </label>
                  <select
                    value={formData.status}
                    onChange={(e) => setFormData({ ...formData, status: e.target.value as any })}
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                  >
                    <option value="draft">Draft</option>
                    <option value="published">Published</option>
                    <option value="closed">Closed</option>
                    <option value="cancelled">Cancelled</option>
                  </select>
                </div>
              </div>

              <div className="mb-4">
                <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                  Description
                </label>
                <textarea
                  value={formData.description || ''}
                  onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                  className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                  rows={3}
                />
              </div>

              <div className="flex justify-end gap-2">
                <button
                  type="button"
                  onClick={() => setShowForm(false)}
                  className="px-4 py-2 border-2 border-forest-800 text-forest-800 hover:bg-gray-100 font-semibold uppercase"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="px-4 py-2 bg-forest-800 text-white border-2 border-forest-800 hover:bg-forest-700 font-semibold uppercase"
                >
                  {selectedProgram ? 'Update' : 'Create'} Program
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Teams Management Modal */}
      {showTeams && selectedProgramForTeams && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
          <div className="bg-white border-2 border-forest-800 max-w-6xl w-full max-h-[90vh] overflow-y-auto">
            <div className="border-b-2 border-forest-800 px-6 py-4 flex justify-between items-center">
              <h3 className="text-xl font-semibold text-forest-800 uppercase tracking-wide">
                Teams for {selectedProgramForTeams.name}
              </h3>
              <button
                onClick={() => {
                  setShowTeams(false);
                  setSelectedProgramForTeams(null);
                }}
                className="text-forest-800 hover:bg-gray-100 px-2 text-2xl"
              >
                ×
              </button>
            </div>
            <div className="p-6">
              {/* Here you would show TeamList or TeamManagement component for this program */}
              <p className="text-gray-600">Team management for this program</p>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default ProgramManagement;