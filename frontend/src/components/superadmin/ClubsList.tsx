import React, { useState } from 'react';
import DataTable, { DataTableColumn } from '../ui/DataTable';
import Button from '../ui/Button';

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

  const columns: DataTableColumn<Club>[] = [
    {
      key: 'name',
      header: 'Club Name',
      render: (club) => <span className="font-medium text-brand-primary">{club.name}</span>,
    },
    {
      key: 'created',
      header: 'Created',
      render: (club) => <span className="text-gray-600">{new Date(club.created_at).toLocaleDateString()}</span>,
    },
    { key: 'admin_count', header: 'Admins', align: 'center' },
    { key: 'coach_count', header: 'Coaches', align: 'center' },
    { key: 'team_count', header: 'Teams', align: 'center' },
    { key: 'athlete_count', header: 'Athletes', align: 'center' },
    {
      key: 'actions',
      header: 'Actions',
      align: 'center',
      render: (club) => (
        <Button variant="link" size="sm" onClick={() => onViewDetails(club.id)}>
          View Details
        </Button>
      ),
    },
  ];

  return (
    <div>
      {/* Create Club Button & Form */}
      <div className="mb-4">
        <Button onClick={() => setShowCreateForm(!showCreateForm)}>
          {showCreateForm ? 'Cancel' : 'Create Club'}
        </Button>

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
              <Button type="submit" loading={creating}>
                Save Club
              </Button>
              <Button type="button" variant="secondary" onClick={() => setShowCreateForm(false)}>
                Cancel
              </Button>
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
          <Button type="submit">Search</Button>
        </div>
      </form>

      {/* Table */}
      <DataTable<Club>
        columns={columns}
        rows={loading ? [] : clubs}
        rowKey={(club) => club.id}
        emptyState={loading ? 'Loading clubs...' : 'No clubs found'}
      />
    </div>
  );
};

export default ClubsList;
