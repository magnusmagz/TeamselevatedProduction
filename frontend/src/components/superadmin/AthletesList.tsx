import React, { useState } from 'react';
import DataTable, { DataTableColumn } from '../ui/DataTable';
import Button from '../ui/Button';

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

  const columns: DataTableColumn<Athlete>[] = [
    {
      key: 'name',
      header: 'Name',
      render: (athlete) => (
        <span className="font-medium text-brand-primary">
          {athlete.first_name} {athlete.last_name}
        </span>
      ),
    },
    {
      key: 'email',
      header: 'Email',
      render: (athlete) => <span className="text-gray-600">{athlete.email || '-'}</span>,
    },
    {
      key: 'age',
      header: 'Age',
      align: 'center',
      render: (athlete) => <span className="text-gray-600">{calculateAge(athlete.date_of_birth)}</span>,
    },
    {
      key: 'gender',
      header: 'Gender',
      align: 'center',
      render: (athlete) => <span className="text-gray-600">{athlete.gender || '-'}</span>,
    },
    {
      key: 'club',
      header: 'Club',
      render: (athlete) => (
        <span className="text-gray-600">
          {athlete.club_name || <span className="text-gray-400 italic">No club</span>}
        </span>
      ),
    },
    {
      key: 'teams',
      header: 'Teams',
      render: (athlete) => (
        <span className="text-gray-600">
          {athlete.team_names || <span className="text-gray-400 italic">No teams</span>}
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      align: 'center',
      render: (athlete) =>
        athlete.active_status ? (
          <span className="px-2 py-1 bg-green-100 text-green-700 rounded text-sm">
            Active
          </span>
        ) : (
          <span className="px-2 py-1 bg-gray-100 text-gray-600 rounded text-sm">
            Inactive
          </span>
        ),
    },
  ];

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
          <Button type="submit">Search</Button>
        </div>
      </form>

      {/* Table */}
      <DataTable<Athlete>
        columns={columns}
        rows={loading ? [] : athletes}
        rowKey={(athlete) => athlete.id}
        emptyState={loading ? 'Loading athletes...' : 'No athletes found'}
      />

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
