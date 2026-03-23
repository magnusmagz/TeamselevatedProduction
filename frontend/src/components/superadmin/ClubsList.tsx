import React, { useState } from 'react';

interface Club {
  id: number;
  name: string;
  created_at: string;
  admin_count: number;
  coach_count: number;
  team_count: number;
  athlete_count: number;
}

interface ClubsListProps {
  clubs: Club[];
  loading: boolean;
  onViewDetails: (clubId: number) => void;
  onSearch: (search: string) => void;
  onCreateClub?: (data: { name: string; city: string; state: string; website: string; primary_color: string }) => Promise<boolean>;
}

const ClubsList: React.FC<ClubsListProps> = ({ clubs, loading, onViewDetails, onSearch, onCreateClub }) => {
  const [searchTerm, setSearchTerm] = useState('');
  const [showCreateForm, setShowCreateForm] = useState(false);
  const [createForm, setCreateForm] = useState({ name: '', city: '', state: '', website: '', primary_color: '#12443e' });
  const [creating, setCreating] = useState(false);

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    onSearch(searchTerm);
  };

  const handleCreateClub = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!onCreateClub || !createForm.name.trim()) return;
    setCreating(true);
    try {
      const success = await onCreateClub(createForm);
      if (success) {
        setCreateForm({ name: '', city: '', state: '', website: '', primary_color: '#12443e' });
        setShowCreateForm(false);
      }
    } finally {
      setCreating(false);
    }
  };

  return (
    <div>
      {/* Create Club Button & Form */}
      <div className="mb-4">
        <button
          onClick={() => setShowCreateForm(!showCreateForm)}
          className="bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-3 hover:bg-brand-primary uppercase font-semibold text-sm"
        >
          {showCreateForm ? 'Cancel' : 'Create Club'}
        </button>

        {showCreateForm && (
          <form onSubmit={handleCreateClub} className="mt-4 bg-gray-50 border border-brand-secondary rounded-lg p-4">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-brand-primary mb-1">Name *</label>
                <input
                  type="text"
                  required
                  value={createForm.name}
                  onChange={(e) => setCreateForm({ ...createForm, name: e.target.value })}
                  className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary w-full"
                  placeholder="Club name"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-brand-primary mb-1">City</label>
                <input
                  type="text"
                  value={createForm.city}
                  onChange={(e) => setCreateForm({ ...createForm, city: e.target.value })}
                  className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary w-full"
                  placeholder="City"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-brand-primary mb-1">State</label>
                <input
                  type="text"
                  value={createForm.state}
                  onChange={(e) => setCreateForm({ ...createForm, state: e.target.value })}
                  className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary w-full"
                  placeholder="State"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-brand-primary mb-1">Website</label>
                <input
                  type="text"
                  value={createForm.website}
                  onChange={(e) => setCreateForm({ ...createForm, website: e.target.value })}
                  className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary w-full"
                  placeholder="https://..."
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-brand-primary mb-1">Primary Color</label>
                <div className="flex gap-2 items-center">
                  <input
                    type="color"
                    value={createForm.primary_color}
                    onChange={(e) => setCreateForm({ ...createForm, primary_color: e.target.value })}
                    className="h-10 w-12 border border-brand-secondary rounded cursor-pointer"
                  />
                  <input
                    type="text"
                    value={createForm.primary_color}
                    onChange={(e) => setCreateForm({ ...createForm, primary_color: e.target.value })}
                    className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary flex-1"
                  />
                </div>
              </div>
            </div>
            <div className="mt-4 flex gap-2">
              <button
                type="submit"
                disabled={creating}
                className="bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-3 hover:bg-brand-primary uppercase font-semibold text-sm disabled:opacity-50"
              >
                {creating ? 'Creating...' : 'Save Club'}
              </button>
              <button
                type="button"
                onClick={() => setShowCreateForm(false)}
                className="bg-white text-brand-primary border border-brand-secondary rounded-md px-6 py-3 hover:bg-gray-50 uppercase font-semibold text-sm"
              >
                Cancel
              </button>
            </div>
          </form>
        )}
      </div>

      {/* Search */}
      <form onSubmit={handleSearch} className="mb-4">
        <div className="flex gap-2">
          <input
            type="text"
            placeholder="Search clubs..."
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            className="flex-1 px-4 py-2 border border-brand-secondary rounded-md focus:outline-none focus:border-brand-primary"
          />
          <button
            type="submit"
            className="px-4 py-2 bg-brand-primary text-white rounded-md hover:bg-brand-primary-dark"
          >
            Search
          </button>
        </div>
      </form>

      {/* Table */}
      <div className="bg-white border border-brand-secondary rounded-lg overflow-hidden">
        <table className="w-full">
          <thead className="bg-brand-secondary">
            <tr>
              <th className="px-4 py-3 text-left text-sm font-semibold text-brand-primary uppercase">Club Name</th>
              <th className="px-4 py-3 text-left text-sm font-semibold text-brand-primary uppercase">Created</th>
              <th className="px-4 py-3 text-center text-sm font-semibold text-brand-primary uppercase">Admins</th>
              <th className="px-4 py-3 text-center text-sm font-semibold text-brand-primary uppercase">Coaches</th>
              <th className="px-4 py-3 text-center text-sm font-semibold text-brand-primary uppercase">Teams</th>
              <th className="px-4 py-3 text-center text-sm font-semibold text-brand-primary uppercase">Athletes</th>
              <th className="px-4 py-3 text-center text-sm font-semibold text-brand-primary uppercase">Actions</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr>
                <td colSpan={7} className="px-4 py-8 text-center text-gray-500">
                  Loading clubs...
                </td>
              </tr>
            ) : clubs.length === 0 ? (
              <tr>
                <td colSpan={7} className="px-4 py-8 text-center text-gray-500">
                  No clubs found
                </td>
              </tr>
            ) : (
              clubs.map((club) => (
                <tr key={club.id} className="border-t border-brand-secondary hover:bg-gray-50">
                  <td className="px-4 py-3 font-medium text-brand-primary">{club.name}</td>
                  <td className="px-4 py-3 text-gray-600">
                    {new Date(club.created_at).toLocaleDateString()}
                  </td>
                  <td className="px-4 py-3 text-center">{club.admin_count}</td>
                  <td className="px-4 py-3 text-center">{club.coach_count}</td>
                  <td className="px-4 py-3 text-center">{club.team_count}</td>
                  <td className="px-4 py-3 text-center">{club.athlete_count}</td>
                  <td className="px-4 py-3 text-center">
                    <button
                      onClick={() => onViewDetails(club.id)}
                      className="text-brand-primary hover:underline text-sm"
                    >
                      View Details
                    </button>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
};

export default ClubsList;
