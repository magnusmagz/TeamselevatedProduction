import React, { useState } from 'react';

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

interface AthletesListProps {
  athletes: Athlete[];
  loading: boolean;
  onSearch: (search: string) => void;
}

const AthletesList: React.FC<AthletesListProps> = ({ athletes, loading, onSearch }) => {
  const [searchTerm, setSearchTerm] = useState('');

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    onSearch(searchTerm);
  };

  const calculateAge = (dob: string | null): string => {
    if (!dob) return '-';
    const birthDate = new Date(dob);
    const today = new Date();
    let age = today.getFullYear() - birthDate.getFullYear();
    const monthDiff = today.getMonth() - birthDate.getMonth();
    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
      age--;
    }
    return age.toString();
  };

  return (
    <div>
      {/* Search */}
      <form onSubmit={handleSearch} className="mb-4">
        <div className="flex gap-2">
          <input
            type="text"
            placeholder="Search by name or email..."
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
              <th className="px-4 py-3 text-left text-sm font-semibold text-brand-primary uppercase">Name</th>
              <th className="px-4 py-3 text-left text-sm font-semibold text-brand-primary uppercase">Email</th>
              <th className="px-4 py-3 text-center text-sm font-semibold text-brand-primary uppercase">Age</th>
              <th className="px-4 py-3 text-center text-sm font-semibold text-brand-primary uppercase">Gender</th>
              <th className="px-4 py-3 text-left text-sm font-semibold text-brand-primary uppercase">Club</th>
              <th className="px-4 py-3 text-left text-sm font-semibold text-brand-primary uppercase">Teams</th>
              <th className="px-4 py-3 text-center text-sm font-semibold text-brand-primary uppercase">Status</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr>
                <td colSpan={7} className="px-4 py-8 text-center text-gray-500">
                  Loading athletes...
                </td>
              </tr>
            ) : athletes.length === 0 ? (
              <tr>
                <td colSpan={7} className="px-4 py-8 text-center text-gray-500">
                  No athletes found
                </td>
              </tr>
            ) : (
              athletes.map((athlete) => (
                <tr key={athlete.id} className="border-t border-brand-secondary hover:bg-gray-50">
                  <td className="px-4 py-3 font-medium text-brand-primary">
                    {athlete.first_name} {athlete.last_name}
                  </td>
                  <td className="px-4 py-3 text-gray-600">
                    {athlete.email || '-'}
                  </td>
                  <td className="px-4 py-3 text-center text-gray-600">
                    {calculateAge(athlete.date_of_birth)}
                  </td>
                  <td className="px-4 py-3 text-center text-gray-600">
                    {athlete.gender || '-'}
                  </td>
                  <td className="px-4 py-3 text-gray-600">
                    {athlete.club_name || <span className="text-gray-400 italic">No club</span>}
                  </td>
                  <td className="px-4 py-3 text-gray-600">
                    {athlete.team_names || <span className="text-gray-400 italic">No teams</span>}
                  </td>
                  <td className="px-4 py-3 text-center">
                    {athlete.active_status ? (
                      <span className="px-2 py-1 bg-green-100 text-green-700 rounded text-sm">
                        Active
                      </span>
                    ) : (
                      <span className="px-2 py-1 bg-gray-100 text-gray-600 rounded text-sm">
                        Inactive
                      </span>
                    )}
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {/* Count */}
      {!loading && athletes.length > 0 && (
        <div className="mt-4 text-sm text-gray-500">
          Showing {athletes.length} athlete{athletes.length !== 1 ? 's' : ''}
        </div>
      )}
    </div>
  );
};

export default AthletesList;
