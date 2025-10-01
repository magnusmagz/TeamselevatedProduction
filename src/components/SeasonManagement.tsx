import React, { useState, useEffect } from 'react';

interface Season {
  id: number;
  name: string;
  start_date: string;
  end_date: string;
  is_active: boolean;
  created_at: string;
}

interface SeasonManagementProps {
  onClose: () => void;
}

const SeasonManagement: React.FC<SeasonManagementProps> = ({ onClose }) => {
  const [seasons, setSeasons] = useState<Season[]>([]);
  const [showForm, setShowForm] = useState(false);
  const [editingSeason, setEditingSeason] = useState<Season | null>(null);
  const [formData, setFormData] = useState({
    name: '',
    start_date: '',
    end_date: ''
  });
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetchSeasons();
  }, []);

  const fetchSeasons = async () => {
    try {
      const response = await fetch('http://localhost:8889/seasons-gateway.php?action=list');
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
      ? `http://localhost:8889/api/seasons/${editingSeason.id}`
      : 'http://localhost:8889/seasons-gateway.php?action=create';

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
      const response = await fetch(`http://localhost:8889/api/seasons/${id}`, {
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

  const isCurrentSeason = (season: Season) => {
    const today = new Date();
    const start = new Date(season.start_date);
    const end = new Date(season.end_date);
    return today >= start && today <= end && season.is_active;
  };

  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric'
    });
  };

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
      <div className="bg-white border-2 border-forest-800 w-full max-w-4xl max-h-[90vh] overflow-y-auto">
        <div className="border-b-2 border-forest-800 px-6 py-4 flex justify-between items-center">
          <h3 className="text-xl font-semibold text-forest-800 uppercase tracking-wide">Season Management</h3>
          <button onClick={onClose} className="text-forest-800 hover:bg-gray-100 px-2 text-2xl">
            ×
          </button>
        </div>

        <div className="p-6">
          <div className="flex justify-between items-center mb-6">
            <p className="text-gray-600">Manage your club's seasons to organize teams and track history</p>
            <button
              onClick={() => {
                setShowForm(true);
                setEditingSeason(null);
                setFormData({ name: '', start_date: '', end_date: '' });
              }}
              className="bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 hover:bg-forest-800 hover:text-white font-semibold uppercase"
            >
              + Create Season
            </button>
          </div>

          {/* Season Form */}
          {showForm && (
            <div className="border-2 border-forest-800 p-6 mb-6">
              <h4 className="text-lg font-semibold text-forest-800 mb-4 uppercase">
                {editingSeason ? 'Edit Season' : 'Create New Season'}
              </h4>
              <form onSubmit={handleSubmit} className="space-y-4">
                <div>
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    Season Name *
                  </label>
                  <input
                    type="text"
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    placeholder="e.g., Spring 2024, Fall 2024"
                    required
                  />
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                      Start Date *
                    </label>
                    <input
                      type="date"
                      className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                      value={formData.start_date}
                      onChange={(e) => setFormData({ ...formData, start_date: e.target.value })}
                      required
                    />
                  </div>

                  <div>
                    <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                      End Date *
                    </label>
                    <input
                      type="date"
                      className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                      value={formData.end_date}
                      onChange={(e) => setFormData({ ...formData, end_date: e.target.value })}
                      min={formData.start_date}
                      required
                    />
                  </div>
                </div>

                <div className="flex justify-end space-x-4">
                  <button
                    type="button"
                    onClick={() => {
                      setShowForm(false);
                      setEditingSeason(null);
                      setFormData({ name: '', start_date: '', end_date: '' });
                    }}
                    className="bg-white text-forest-800 border-2 border-forest-800 px-6 py-2 hover:bg-gray-100 uppercase"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    className="bg-forest-800 text-white border-2 border-forest-800 px-6 py-2 hover:bg-forest-700 font-semibold uppercase"
                  >
                    {editingSeason ? 'Update Season' : 'Create Season'}
                  </button>
                </div>
              </form>
            </div>
          )}

          {/* Seasons List */}
          {loading ? (
            <div className="text-center text-forest-800 py-12">Loading seasons...</div>
          ) : seasons.length === 0 ? (
            <div className="text-center text-gray-600 py-12">
              <p>No seasons created yet.</p>
              <p className="mt-2">Create your first season to get started!</p>
            </div>
          ) : (
            <div className="space-y-4">
              {seasons.map(season => (
                <div
                  key={season.id}
                  className={`bg-white border-2 border-forest-800 p-4 flex justify-between items-center ${
                    isCurrentSeason(season) ? 'border-3 border-green-600' : ''
                  }`}
                >
                  <div>
                    <div className="flex items-center space-x-3">
                      <h5 className="text-forest-800 font-semibold text-lg">{season.name}</h5>
                      {isCurrentSeason(season) && (
                        <span className="px-2 py-1 bg-forest-800 text-white text-xs uppercase">
                          CURRENT
                        </span>
                      )}
                      {!season.is_active && (
                        <span className="px-2 py-1 border border-forest-800 text-forest-800 text-xs uppercase">
                          INACTIVE
                        </span>
                      )}
                    </div>
                    <p className="text-gray-600 text-sm mt-1">
                      {formatDate(season.start_date)} - {formatDate(season.end_date)}
                    </p>
                    {(() => {
                      const start = new Date(season.start_date);
                      const end = new Date(season.end_date);
                      const today = new Date();
                      const duration = Math.ceil((end.getTime() - start.getTime()) / (1000 * 60 * 60 * 24));

                      if (today < start) {
                        const daysUntil = Math.ceil((start.getTime() - today.getTime()) / (1000 * 60 * 60 * 24));
                        return (
                          <p className="text-gray-500 text-xs mt-1">
                            Starts in {daysUntil} days • {duration} days total
                          </p>
                        );
                      } else if (today > end) {
                        return (
                          <p className="text-gray-500 text-xs mt-1">
                            Ended • {duration} days total
                          </p>
                        );
                      } else {
                        const daysLeft = Math.ceil((end.getTime() - today.getTime()) / (1000 * 60 * 60 * 24));
                        return (
                          <p className="text-gray-500 text-xs mt-1">
                            {daysLeft} days remaining • {duration} days total
                          </p>
                        );
                      }
                    })()}
                  </div>

                  <div className="flex space-x-2">
                    <button
                      onClick={() => handleEdit(season)}
                      className="text-forest-800 hover:underline px-3 py-1 uppercase text-sm"
                    >
                      Edit
                    </button>
                    <button
                      onClick={() => handleDelete(season.id)}
                      className="text-forest-800 hover:underline px-3 py-1 uppercase text-sm"
                    >
                      Delete
                    </button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

export default SeasonManagement;