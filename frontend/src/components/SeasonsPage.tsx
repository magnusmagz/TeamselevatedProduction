import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';

interface Season {
  id: number;
  name: string;
  start_date: string;
  end_date: string;
  is_active: boolean;
  created_at: string;
}

const SeasonsPage: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const navigate = useNavigate();
  const [seasons, setSeasons] = useState<Season[]>([]);
  const [showForm, setShowForm] = useState(false);
  const [editingSeason, setEditingSeason] = useState<Season | null>(null);
  const [formData, setFormData] = useState({
    name: '',
    start_date: '',
    end_date: ''
  });
  const [loading, setLoading] = useState(true);
  const [searchTerm, setSearchTerm] = useState('');

  useEffect(() => {
    fetchSeasons();
  }, []);

  const fetchSeasons = async () => {
    try {
      const response = await fetch(`${API_URL}/legacy/seasons-gateway.php?action=list`);
      const data = await response.json();
      if (data.success) {
        setSeasons(data.seasons);
      }
    } catch (error) {
      console.error('Error fetching seasons:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    const url = editingSeason
      ? `${API_URL}/api/seasons/${editingSeason.id}`
      : `${API_URL}/legacy/seasons-gateway.php?action=create`;

    const method = editingSeason ? 'PUT' : 'POST';

    try {
      const response = await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(formData)
      });

      if (response.ok) {
        alert(`Season ${editingSeason ? 'updated' : 'created'} successfully!`);
        setShowForm(false);
        setEditingSeason(null);
        setFormData({ name: '', start_date: '', end_date: '' });
        fetchSeasons();
      } else {
        const error = await response.json();
        alert(error.error || 'Failed to save season');
      }
    } catch (error) {
      console.error('Error saving season:', error);
      alert('Failed to save season');
    }
  };

  const handleEdit = (season: Season) => {
    setEditingSeason(season);
    setFormData({
      name: season.name,
      start_date: season.start_date,
      end_date: season.end_date
    });
    setShowForm(true);
  };

  const handleDelete = async (id: number) => {
    if (!window.confirm('Are you sure you want to delete this season?')) return;

    try {
      const response = await fetch(`${API_URL}/api/seasons/${id}`, {
        method: 'DELETE'
      });

      if (response.ok) {
        alert('Season deleted successfully!');
        fetchSeasons();
      } else {
        const error = await response.json();
        alert(error.error || 'Failed to delete season');
      }
    } catch (error) {
      console.error('Error deleting season:', error);
      alert('Failed to delete season');
    }
  };

  const handleActivate = async (id: number) => {
    try {
      const response = await fetch(`${API_URL}/api/seasons/${id}/activate`, {
        method: 'POST'
      });

      if (response.ok) {
        alert('Season activated successfully!');
        fetchSeasons();
      } else {
        const error = await response.json();
        alert(error.error || 'Failed to activate season');
      }
    } catch (error) {
      console.error('Error activating season:', error);
      alert('Failed to activate season');
    }
  };

  const filteredSeasons = seasons.filter(season =>
    season.name.toLowerCase().includes(searchTerm.toLowerCase())
  );

  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric'
    });
  };

  return (
    <div>

      {/* Header */}
      <div className="mb-6">
        <h1 className="text-3xl font-bold text-brand-primary uppercase tracking-wide">SEASON MANAGEMENT</h1>
        <p className="text-gray-600 mt-2">Manage all seasons in the system</p>
      </div>

      {/* Search and Actions Bar */}
      <div className="mb-6 flex justify-between items-center">
        <input
          type="text"
          placeholder="Search seasons..."
          className="w-64 bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
          value={searchTerm}
          onChange={(e) => setSearchTerm(e.target.value)}
        />
        <button
          onClick={() => {
            setEditingSeason(null);
            setFormData({ name: '', start_date: '', end_date: '' });
            setShowForm(true);
          }}
          className="bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-2 hover:bg-brand-primary uppercase font-bold"
        >
          + Add Season
        </button>
      </div>

      {/* Table */}
      <div className="border border-brand-secondary rounded-md overflow-hidden bg-white">
        {loading ? (
          <div className="p-12 text-center text-brand-primary">Loading seasons...</div>
        ) : filteredSeasons.length === 0 ? (
          <div className="p-12 text-center text-brand-primary">
            {searchTerm ? 'No seasons found matching your search.' : 'No seasons found. Create your first season!'}
          </div>
        ) : (
          <table className="min-w-full border-collapse">
            <thead>
              <tr className="border-b border-brand-secondary bg-white">
                <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase tracking-wider border-r border-gray-300">
                  Season Name
                </th>
                <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase tracking-wider border-r border-gray-300">
                  Start Date
                </th>
                <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase tracking-wider border-r border-gray-300">
                  End Date
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
              {filteredSeasons.map((season) => (
                <tr key={season.id} className="border-b border-gray-300 hover:bg-gray-50">
                  <td className="px-6 py-4 whitespace-nowrap border-r border-gray-300">
                    <div className="text-sm font-medium text-brand-primary">{season.name}</div>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-brand-primary border-r border-gray-300">
                    {formatDate(season.start_date)}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-brand-primary border-r border-gray-300">
                    {formatDate(season.end_date)}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap border-r border-gray-300">
                    {season.is_active ? (
                      <span className="px-2 py-1 text-xs text-brand-primary border border-brand-primary">
                        ACTIVE
                      </span>
                    ) : (
                      <span className="px-2 py-1 text-xs text-gray-600 border border-gray-600">
                        INACTIVE
                      </span>
                    )}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm font-medium">
                    <button
                      onClick={() => handleEdit(season)}
                      className="text-brand-primary hover:underline mr-4 uppercase text-xs"
                    >
                      Edit
                    </button>
                    {!season.is_active && (
                      <button
                        onClick={() => handleActivate(season.id)}
                        className="text-brand-primary hover:underline mr-4 uppercase text-xs"
                      >
                        Activate
                      </button>
                    )}
                    <button
                      onClick={() => handleDelete(season.id)}
                      className="text-brand-primary hover:underline uppercase text-xs"
                    >
                      Delete
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {/* Form Modal */}
      {showForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex justify-center items-center z-50">
          <div className="bg-white border border-brand-secondary rounded-md p-6 w-full max-w-md">
            <h3 className="text-xl font-bold text-brand-primary mb-4 uppercase">
              {editingSeason ? 'Edit Season' : 'Create New Season'}
            </h3>

            <form onSubmit={handleSubmit} className="space-y-4">
              <div>
                <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                  Season Name *
                </label>
                <input
                  type="text"
                  className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  placeholder="e.g., Spring 2024"
                  required
                />
              </div>

              <div>
                <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                  Start Date *
                </label>
                <input
                  type="date"
                  className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                  value={formData.start_date}
                  onChange={(e) => setFormData({ ...formData, start_date: e.target.value })}
                  required
                />
              </div>

              <div>
                <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                  End Date *
                </label>
                <input
                  type="date"
                  className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                  value={formData.end_date}
                  onChange={(e) => setFormData({ ...formData, end_date: e.target.value })}
                  min={formData.start_date}
                  required
                />
              </div>

              <div className="flex justify-end space-x-2 mt-6">
                <button
                  type="button"
                  onClick={() => {
                    setShowForm(false);
                    setEditingSeason(null);
                    setFormData({ name: '', start_date: '', end_date: '' });
                  }}
                  className="bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 hover:bg-gray-100"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="bg-brand-primary text-white border border-brand-secondary rounded-md px-4 py-2 hover:bg-brand-primary"
                >
                  {editingSeason ? 'Update Season' : 'Create Season'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

export default SeasonsPage;