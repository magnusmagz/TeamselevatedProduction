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
}

const ClubsList: React.FC<ClubsListProps> = ({ clubs, loading, onViewDetails, onSearch }) => {
  const [searchTerm, setSearchTerm] = useState('');

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    onSearch(searchTerm);
  };

  return (
    <div>
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
