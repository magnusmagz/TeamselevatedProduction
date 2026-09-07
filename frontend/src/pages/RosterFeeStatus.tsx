import React, { useState, useEffect } from 'react';
import { useSearchParams, Link } from 'react-router-dom';
import { useOrg } from '../contexts/OrgContext';
import PageHeader from '../components/ui/PageHeader';
import Button from '../components/ui/Button';
import DataTable, { DataTableColumn } from '../components/ui/DataTable';

interface RosterAthlete {
  athlete_id: number;
  first_name: string;
  last_name: string;
  date_of_birth: string;
  guardian_first: string | null;
  guardian_last: string | null;
  guardian_email: string | null;
  guardian_phone: string | null;
  registration_status?: string;
  registered_at?: string;
  jersey_number?: string;
  roster_status?: string;
  total_owed: number;
  total_paid: number;
  total_remaining: number;
  payment_status: 'paid' | 'partial' | 'unpaid';
  program_count?: number;
}

interface RosterSummary {
  total_athletes: number;
  paid_count: number;
  partial_count: number;
  unpaid_count: number;
  total_expected: number;
  total_collected: number;
  total_outstanding: number;
  collection_rate: number;
}

interface Program {
  id: number;
  name: string;
}

interface Team {
  id: number;
  name: string;
}

export const RosterFeeStatus: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { activeContext } = useOrg();
  const [searchParams, setSearchParams] = useSearchParams();

  const [roster, setRoster] = useState<RosterAthlete[]>([]);
  const [summary, setSummary] = useState<RosterSummary | null>(null);
  const [contextName, setContextName] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [programs, setPrograms] = useState<Program[]>([]);
  const [teams, setTeams] = useState<Team[]>([]);

  const [viewMode, setViewMode] = useState<'program' | 'team' | 'club'>(
    (searchParams.get('mode') as 'program' | 'team' | 'club') || 'club'
  );
  const [selectedProgram, setSelectedProgram] = useState<string>(searchParams.get('program_id') || '');
  const [selectedTeam, setSelectedTeam] = useState<string>(searchParams.get('team_id') || '');
  const [statusFilter, setStatusFilter] = useState<string>(searchParams.get('status') || 'all');

  const clubId = activeContext?.scope_id;

  // Fetch programs and teams for filters
  useEffect(() => {
    if (!clubId) return;

    const fetchFilters = async () => {
      const token = localStorage.getItem('auth_token');
      try {
        // Fetch programs
        const progRes = await fetch(`${API_URL}/api/programs.php?club_id=${clubId}`, {
          headers: { 'Authorization': `Bearer ${token}` }
        });
        const progData = await progRes.json();
        if (progData.success) {
          setPrograms(progData.programs || []);
        }

        // Fetch teams
        const teamRes = await fetch(`${API_URL}/legacy/teams-gateway.php?club_id=${clubId}`, {
          headers: {
            'Authorization': `Bearer ${token}`
          }
        });
        const teamData = await teamRes.json();
        if (teamData.teams) {
          setTeams(teamData.teams);
        }
      } catch (err) {
        console.error('Error fetching filters:', err);
      }
    };

    fetchFilters();
  }, [API_URL, clubId]);

  // Fetch roster data
  useEffect(() => {
    if (!clubId) return;

    const fetchRoster = async () => {
      setLoading(true);
      setError(null);

      try {
        let url = `${API_URL}/api/roster-fee-status.php?`;

        if (viewMode === 'program' && selectedProgram) {
          url += `program_id=${selectedProgram}`;
        } else if (viewMode === 'team' && selectedTeam) {
          url += `team_id=${selectedTeam}`;
        } else {
          url += `club_id=${clubId}`;
        }

        if (statusFilter && statusFilter !== 'all') {
          url += `&status=${statusFilter}`;
        }

        const token = localStorage.getItem('auth_token');
        const response = await fetch(url, {
          headers: { 'Authorization': `Bearer ${token}` }
        });
        const data = await response.json();

        if (data.success) {
          setRoster(data.roster || []);
          setSummary(data.summary);
          setContextName(data.context || 'All Athletes');
        } else {
          setError(data.error || 'Failed to fetch roster');
        }
      } catch (err) {
        setError('Failed to load roster data');
        console.error(err);
      } finally {
        setLoading(false);
      }
    };

    fetchRoster();

    // Update URL params
    const params: Record<string, string> = { mode: viewMode };
    if (selectedProgram && viewMode === 'program') params.program_id = selectedProgram;
    if (selectedTeam && viewMode === 'team') params.team_id = selectedTeam;
    if (statusFilter !== 'all') params.status = statusFilter;
    setSearchParams(params);
  }, [API_URL, clubId, viewMode, selectedProgram, selectedTeam, statusFilter, setSearchParams]);

  const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: 'USD'
    }).format(amount);
  };

  const formatDate = (dateStr: string) => {
    return new Date(dateStr).toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric'
    });
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'paid':
        return <span className="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-800">Paid</span>;
      case 'partial':
        return <span className="px-2 py-1 text-xs font-medium rounded-full bg-yellow-100 text-yellow-800">Partial</span>;
      case 'unpaid':
        return <span className="px-2 py-1 text-xs font-medium rounded-full bg-red-100 text-red-800">Unpaid</span>;
      default:
        return <span className="px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-800">{status}</span>;
    }
  };

  const handleExportCSV = () => {
    if (roster.length === 0) return;

    const headers = ['Athlete Name', 'Guardian', 'Email', 'Phone', 'Total Owed', 'Total Paid', 'Remaining', 'Status'];
    const rows = roster.map(a => [
      `${a.first_name} ${a.last_name}`,
      a.guardian_first && a.guardian_last ? `${a.guardian_first} ${a.guardian_last}` : '',
      a.guardian_email || '',
      a.guardian_phone || '',
      a.total_owed.toString(),
      a.total_paid.toString(),
      a.total_remaining.toString(),
      a.payment_status
    ]);

    const csvContent = [
      headers.join(','),
      ...rows.map(row => row.map(cell => `"${cell}"`).join(','))
    ].join('\n');

    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `roster-fee-status-${new Date().toISOString().split('T')[0]}.csv`;
    a.click();
    window.URL.revokeObjectURL(url);
  };

  const rosterColumns: DataTableColumn<RosterAthlete>[] = [
    {
      key: 'athlete',
      header: 'Athlete',
      className: 'whitespace-nowrap',
      render: (athlete) => (
        <>
          <Link
            to={`/athlete/${athlete.athlete_id}`}
            className="text-brand-primary hover:text-brand-primary font-medium"
          >
            {athlete.first_name} {athlete.last_name}
          </Link>
          {athlete.date_of_birth && (
            <p className="text-xs text-gray-500">
              DOB: {formatDate(athlete.date_of_birth)}
            </p>
          )}
        </>
      ),
    },
    {
      key: 'guardian',
      header: 'Guardian',
      className: 'whitespace-nowrap',
      render: (athlete) =>
        athlete.guardian_first && athlete.guardian_last ? (
          <span className="text-gray-900">
            {athlete.guardian_first} {athlete.guardian_last}
          </span>
        ) : (
          <span className="text-gray-400">—</span>
        ),
    },
    {
      key: 'contact',
      header: 'Contact',
      render: (athlete) => (
        <>
          {athlete.guardian_email && (
            <a
              href={`mailto:${athlete.guardian_email}`}
              className="text-sm text-brand-primary hover:text-brand-primary block"
            >
              {athlete.guardian_email}
            </a>
          )}
          {athlete.guardian_phone && (
            <span className="text-sm text-gray-500 block">
              {athlete.guardian_phone}
            </span>
          )}
        </>
      ),
    },
    {
      key: 'total_owed',
      header: 'Total Owed',
      align: 'right',
      className: 'whitespace-nowrap',
      render: (athlete) => formatCurrency(athlete.total_owed),
    },
    {
      key: 'total_paid',
      header: 'Paid',
      align: 'right',
      className: 'whitespace-nowrap',
      render: (athlete) => <span className="text-green-600">{formatCurrency(athlete.total_paid)}</span>,
    },
    {
      key: 'total_remaining',
      header: 'Remaining',
      align: 'right',
      className: 'whitespace-nowrap',
      render: (athlete) => (
        <span className={athlete.total_remaining > 0 ? 'text-red-600 font-medium' : 'text-gray-500'}>
          {formatCurrency(athlete.total_remaining)}
        </span>
      ),
    },
    {
      key: 'payment_status',
      header: 'Status',
      align: 'center',
      className: 'whitespace-nowrap',
      render: (athlete) => getStatusBadge(athlete.payment_status),
    },
    {
      key: 'actions',
      header: 'Actions',
      actions: true,
      render: (athlete) => (
        <Link
          to={`/athlete/${athlete.athlete_id}/payments`}
          className="text-brand-primary hover:text-brand-primary text-sm font-medium"
        >
          View Payments
        </Link>
      ),
    },
  ];

  if (!clubId) {
    return (
      <div className="text-center py-12">
        <p className="text-gray-500">Please select a club to view roster fee status.</p>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Roster Fee Status"
        subtitle="View payment status for each athlete in your roster"
        actions={
          <Button
            onClick={handleExportCSV}
            disabled={roster.length === 0}
            leadingIcon={
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
              </svg>
            }
          >
            Export CSV
          </Button>
        }
      />

      {/* Filters */}
      <div className="bg-white rounded-lg shadow p-4">
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          {/* View Mode */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">View By</label>
            <select
              value={viewMode}
              onChange={(e) => {
                setViewMode(e.target.value as 'program' | 'team' | 'club');
                setSelectedProgram('');
                setSelectedTeam('');
              }}
              className="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-brand-accent focus:border-brand-accent"
            >
              <option value="club">All Athletes (Club)</option>
              <option value="program">By Program</option>
              <option value="team">By Team</option>
            </select>
          </div>

          {/* Program Selector */}
          {viewMode === 'program' && (
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Program</label>
              <select
                value={selectedProgram}
                onChange={(e) => setSelectedProgram(e.target.value)}
                className="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-brand-accent focus:border-brand-accent"
              >
                <option value="">Select a program</option>
                {programs.map(p => (
                  <option key={p.id} value={p.id}>{p.name}</option>
                ))}
              </select>
            </div>
          )}

          {/* Team Selector */}
          {viewMode === 'team' && (
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Team</label>
              <select
                value={selectedTeam}
                onChange={(e) => setSelectedTeam(e.target.value)}
                className="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-brand-accent focus:border-brand-accent"
              >
                <option value="">Select a team</option>
                {teams.map(t => (
                  <option key={t.id} value={t.id}>{t.name}</option>
                ))}
              </select>
            </div>
          )}

          {/* Status Filter */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Payment Status</label>
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-brand-accent focus:border-brand-accent"
            >
              <option value="all">All Statuses</option>
              <option value="paid">Paid</option>
              <option value="partial">Partial</option>
              <option value="unpaid">Unpaid</option>
            </select>
          </div>
        </div>
      </div>

      {/* Summary Cards */}
      {summary && (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div className="bg-white rounded-lg shadow p-4">
            <p className="text-sm text-gray-500">Total Athletes</p>
            <p className="text-2xl font-bold text-brand-primary">{summary.total_athletes}</p>
            <div className="mt-2 flex gap-2 text-xs">
              <span className="text-green-600">{summary.paid_count} paid</span>
              <span className="text-yellow-600">{summary.partial_count} partial</span>
              <span className="text-red-600">{summary.unpaid_count} unpaid</span>
            </div>
          </div>

          <div className="bg-white rounded-lg shadow p-4">
            <p className="text-sm text-gray-500">Expected Revenue</p>
            <p className="text-2xl font-bold text-brand-primary">{formatCurrency(summary.total_expected)}</p>
          </div>

          <div className="bg-white rounded-lg shadow p-4">
            <p className="text-sm text-gray-500">Collected</p>
            <p className="text-2xl font-bold text-green-600">{formatCurrency(summary.total_collected)}</p>
          </div>

          <div className="bg-white rounded-lg shadow p-4">
            <p className="text-sm text-gray-500">Outstanding</p>
            <p className="text-2xl font-bold text-red-600">{formatCurrency(summary.total_outstanding)}</p>
            <div className="mt-2">
              <div className="w-full bg-gray-200 rounded-full h-2">
                <div
                  className="bg-green-500 h-2 rounded-full"
                  style={{ width: `${summary.collection_rate}%` }}
                />
              </div>
              <p className="text-xs text-gray-500 mt-1">{summary.collection_rate}% collected</p>
            </div>
          </div>
        </div>
      )}

      {/* Context Label */}
      {contextName && (
        <div className="text-sm text-gray-600">
          Showing: <span className="font-medium">{contextName}</span>
        </div>
      )}

      {/* Roster Table */}
      {loading ? (
        <div className="bg-white rounded-lg shadow overflow-hidden">
          <div className="p-8 text-center text-gray-500">Loading roster...</div>
        </div>
      ) : error ? (
        <div className="bg-white rounded-lg shadow overflow-hidden">
          <div className="p-8 text-center text-red-500">{error}</div>
        </div>
      ) : (
        <DataTable<RosterAthlete>
          columns={rosterColumns}
          rows={roster}
          rowKey={(athlete) => athlete.athlete_id}
          emptyState={
            viewMode === 'program' && !selectedProgram
              ? 'Please select a program to view roster'
              : viewMode === 'team' && !selectedTeam
              ? 'Please select a team to view roster'
              : 'No athletes found matching your filters'
          }
        />
      )}
    </div>
  );
};

export default RosterFeeStatus;
